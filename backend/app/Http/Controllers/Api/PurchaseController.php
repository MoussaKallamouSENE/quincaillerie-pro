<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Reception;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PurchaseController extends Controller
{
    /**
     * Liste paginée des achats avec les relations supplier et user.
     */
    public function index(Request $request)
    {
        $purchases = Purchase::query()
            ->with(['supplier', 'user'])
            ->withCount('items')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($purchases);
    }

    /**
     * Créer une commande d'achat.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $purchase = DB::transaction(function () use ($validated, $request) {
            $totalAmount = 0;

            foreach ($validated['items'] as $item) {
                $totalAmount += $item['quantity'] * $item['unit_price'];
            }

            $purchase = Purchase::create([
                'supplier_id' => $validated['supplier_id'],
                'user_id' => $request->user()?->id,
                'reference' => 'ACH-'.strtoupper(Str::random(8)),
                'total_amount' => $totalAmount,
                'status' => 'pending',
            ]);

            foreach ($validated['items'] as $item) {
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['quantity'] * $item['unit_price'],
                ]);
            }

            return $purchase->load(['supplier', 'user', 'items.product']);
        });

        return response()->json($purchase, Response::HTTP_CREATED);
    }

    /**
     * Valider la réception d'une commande.
     */

    /**
     * Valider la réception d'une commande.
     */
    public function receive(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $purchase = DB::transaction(function () use ($id, $request, $validated) {
            // Verrouille la commande jusqu'à la fin de la transaction.
            $purchase = Purchase::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($id);

            // Le statut est vérifié après l'acquisition du verrou.
            if ($purchase->status !== 'pending') {
                return null;
            }

            $purchase->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            Reception::create([
                'purchase_id' => $purchase->id,
                'user_id' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($purchase->items as $item) {
                $product = Product::find($item->product_id);

                if ($product) {
                    $product->increment(
                        'stock_quantity',
                        $item->quantity
                    );

                    StockMovement::create([
                        'product_id' => $product->id,
                        'user_id' => $request->user()->id,
                        'type' => 'in',
                        'quantity' => $item->quantity,
                        'reference' => $purchase->reference,
                        'note' => 'Réception commande '.$purchase->reference,
                    ]);
                }
            }

            return $purchase;
        });

        if ($purchase === null) {
            return response()->json([
                'message' => 'Cette commande ne peut plus être réceptionnée.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $purchase->load([
            'supplier',
            'user',
            'items.product',
            'receptions',
        ]);

        return response()->json([
            'message' => 'Réception validée avec succès.',
            'purchase' => $purchase,
        ]);
    }

    /**
     * Afficher le détail d'une commande d'achat.
     */
    public function show(Purchase $purchase): JsonResponse
    {
        $purchase->load([
            'supplier',
            'user',
            'items.product',
            'receptions.user',
        ]);

        return response()->json($purchase);
    }

    /**
     * Annuler une commande d'achat.
     *
     * Une commande en attente est simplement marquée annulée. Une commande
     * déjà réceptionnée est annulée en reversant le stock reçu, dans la même
     * transaction et sous verrou, avec un mouvement de sortie par ligne.
     */
    public function cancel(Request $request, Purchase $purchase): JsonResponse
    {
        $result = DB::transaction(function () use ($purchase, $request): array {
            $purchase = Purchase::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($purchase->id);

            if ($purchase->status === 'cancelled') {
                return [
                    'cancelled' => false,
                    'purchase' => $purchase,
                ];
            }

            if ($purchase->status === 'received') {
                foreach ($purchase->items as $item) {
                    $product = Product::query()
                        ->lockForUpdate()
                        ->find($item->product_id);

                    if ($product === null) {
                        throw ValidationException::withMessages([
                            'items' => 'Un produit de cette commande n’existe plus.',
                        ]);
                    }

                    if ($product->stock_quantity < $item->quantity) {
                        throw ValidationException::withMessages([
                            'items' => sprintf(
                                'Stock insuffisant pour annuler la commande : %s (%d disponible(s), %d à retirer).',
                                $product->name,
                                $product->stock_quantity,
                                $item->quantity,
                            ),
                        ]);
                    }

                    $product->decrement('stock_quantity', $item->quantity);

                    StockMovement::create([
                        'product_id' => $product->id,
                        'user_id' => $request->user()->id,
                        'type' => 'out',
                        'quantity' => -$item->quantity,
                        'reference' => $purchase->reference,
                        'note' => 'Annulation commande '.$purchase->reference,
                    ]);
                }
            }

            $purchase->update(['status' => 'cancelled']);

            return [
                'cancelled' => true,
                'purchase' => $purchase,
            ];
        });

        if (! $result['cancelled']) {
            return response()->json([
                'message' => 'Cette commande est déjà annulée.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result['purchase']->load([
            'supplier',
            'user',
            'items.product',
            'receptions.user',
        ]);

        return response()->json([
            'message' => 'Commande annulée avec succès.',
            'purchase' => $result['purchase'],
        ]);
    }
}
