<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        $product = Product::create($request->validated());

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
        $product->update($request->validated());

        return ProductResource::make($product->load(['category', 'unit']))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Remove the specified product.
     */
    public function destroy(Product $product): JsonResponse
    {
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
