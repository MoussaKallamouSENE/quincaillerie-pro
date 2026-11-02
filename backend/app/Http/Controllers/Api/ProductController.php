<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends Controller
{
    /**
     * Nombre maximum de produits renvoyes par page.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Display a paginated listing of the catalogue.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim($request->string('search')->toString());

        $products = Product::query()
            ->with(['category', 'unit'])
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when(
                $request->filled('category_id'),
                fn (Builder $query) => $query->where('category_id', $request->integer('category_id')),
            )
            ->when(
                $request->filled('unit_id'),
                fn (Builder $query) => $query->where('unit_id', $request->integer('unit_id')),
            )
            ->when(
                $request->boolean('low_stock'),
                fn (Builder $query) => $query->whereColumn('stock_quantity', '<=', 'min_stock_alert'),
            )
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    /**
     * Store a newly created product.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request): Product {
            $product = Product::create($request->validated());

            $initialStock = (int) $product->stock_quantity;

            if ($initialStock > 0) {
                StockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $request->user()->id,
                    'type' => 'in',
                    'quantity' => $initialStock,
                    'reference' => 'INIT-'.$product->sku,
                    'note' => 'Stock initial à la création du produit',
                ]);
            }

            return $product;
        });

        // Le modele en memoire ignore les valeurs par defaut de la base
        // (`is_active`) : il est recharge pour que la reponse soit fidele.
        $product->refresh();

        return ProductResource::make($product->load(['category', 'unit']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product): ProductResource
    {
        return ProductResource::make($product->load(['category', 'unit']));
    }

    /**
     * Update the specified product.
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = DB::transaction(function () use ($request, $product): Product {
            $lockedProduct = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->id);

            $data = $request->validated();
            $before = (int) $lockedProduct->stock_quantity;

            $lockedProduct->update($data);

            if (array_key_exists('stock_quantity', $data)) {
                $after = (int) $lockedProduct->stock_quantity;
                $delta = $after - $before;

                if ($delta !== 0) {
                    StockMovement::create([
                        'product_id' => $lockedProduct->id,
                        'user_id' => $request->user()->id,
                        'type' => 'adjustment',
                        'quantity' => $delta,
                        'reference' => 'MVT-'.Str::upper(Str::random(10)),
                        'note' => sprintf(
                            'Modification de la fiche produit (stock : %d -> %d)',
                            $before,
                            $after,
                        ),
                    ]);
                }
            }

            return $lockedProduct;
        });

        return ProductResource::make($product->load(['category', 'unit']))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Remove the specified product.
     *
     * Un produit rattache a l'historique (vente, achat ou mouvement de stock)
     * ne peut pas etre supprime : il doit etre desactive pour conserver la
     * tracabilite.
     */
    public function destroy(Product $product): JsonResponse
    {
        if (
            $product->saleItems()->exists()
            || $product->purchaseItems()->exists()
            || $product->stockMovements()->exists()
        ) {
            return response()->json([
                'message' => 'Ce produit possède un historique (ventes, achats ou mouvements). Désactivez-le plutôt que de le supprimer.',
            ], Response::HTTP_CONFLICT);
        }

        $product->delete();

        return response()->json([
            'message' => 'Produit supprimé.',
        ]);
    }

    /**
     * Nombre de produits par page demande, borne a une plage raisonnable.
     */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), self::MAX_PER_PAGE);
    }
}
