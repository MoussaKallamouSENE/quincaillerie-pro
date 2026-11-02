<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StockMovementController extends Controller
{
    /**
     * Liste les mouvements de stock, du plus recent au plus ancien.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            StockMovement::with(['product', 'user'])->latest()->paginate(20)
        );
    }

    /**
     * Enregistre un mouvement de stock et met a jour le produit dans la meme
     * transaction.
     *
     * Le produit est verrouille pendant l'operation afin que deux mouvements
     * concurrents ne puissent pas produire un stock incoherent. Une sortie ne
     * peut jamais rendre le stock negatif.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'type' => ['required', 'string', 'in:in,out,adjustment'],
            'quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validated['type'] !== 'adjustment' && $validated['quantity'] < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'La quantité doit être supérieure à zéro.',
            ]);
        }

        try {
            $result = DB::transaction(function () use ($validated, $request): array {
                $product = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($validated['product_id']);

                $before = $product->stock_quantity;
                $quantity = $validated['quantity'];

                if ($validated['type'] === 'out' && $quantity > $before) {
                    throw ValidationException::withMessages([
                        'quantity' => sprintf(
                            'Stock insuffisant : %d disponible(s), %d demandé(s).',
                            $before,
                            $quantity,
                        ),
                    ]);
                }

                $delta = match ($validated['type']) {
                    'in' => $quantity,
                    'out' => -$quantity,
                    'adjustment' => $quantity - $before,
                };

                $after = $before + $delta;

                $product->update(['stock_quantity' => $after]);

                if ($delta === 0) {
                    return [
                        'movement' => null,
                        'product' => $product,
                    ];
                }

                $movement = StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => $validated['type'],
                    'quantity' => $delta,
                    'reference' => 'MVT-'.Str::upper(Str::random(10)),
                    'note' => $validated['type'] === 'adjustment'
                        ? sprintf('%s (stock : %d -> %d)', $validated['reason'], $before, $after)
                        : $validated['reason'],
                ]);

                return [
                    'movement' => $movement,
                    'product' => $product,
                ];
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Erreur lors de la mise à jour du stock.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($result['movement'] === null) {
            return response()->json([
                'message' => 'Le stock est déjà à cette valeur.',
                'movement' => null,
                'stock_quantity' => $result['product']->stock_quantity,
            ]);
        }

        return response()->json([
            'message' => 'Stock mis à jour avec succès.',
            'movement' => $result['movement'],
            'stock_quantity' => $result['product']->stock_quantity,
        ], Response::HTTP_CREATED);
    }
}
