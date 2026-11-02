<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    /**
     * Display the list of the catalogue categories.
     *
     * Cette liste alimente les listes de sélection du formulaire de création
     * d'un produit ainsi que l'écran de gestion des catégories.
     */
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()
                ->with('parent')
                ->withCount('products')
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] ??= true;

        $category = Category::create($data);

        return CategoryResource::make(
            $category->load('parent')->loadCount('products')
        )
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified category.
     */
    public function show(Category $category): CategoryResource
    {
        return CategoryResource::make(
            $category->load('parent')->loadCount('products')
        );
    }

    /**
     * Update the specified category.
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return CategoryResource::make(
            $category->load('parent')->loadCount('products')
        )
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Remove the specified category.
     *
     * Une catégorie contenant des produits ou des sous-catégories ne peut pas
     * être supprimée : la cascade de la base de données effacerait le catalogue.
     */
    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Cette catégorie contient des produits. Déplacez-les ou désactivez la catégorie plutôt que de la supprimer.',
            ], Response::HTTP_CONFLICT);
        }

        if ($category->children()->exists()) {
            return response()->json([
                'message' => 'Cette catégorie contient des sous-catégories. Supprimez-les ou déplacez-les d’abord.',
            ], Response::HTTP_CONFLICT);
        }

        $category->delete();

        return response()->json([
            'message' => 'Catégorie supprimée.',
        ]);
    }
}
