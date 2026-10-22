<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class SupplierController extends Controller
{
    /**
     * Nombre maximum de fournisseurs renvoyes par page.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Display a paginated listing of the suppliers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim($request->string('search')->toString());

        $suppliers = Supplier::query()
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(
                $request->filled('is_active'),
                fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return SupplierResource::collection($suppliers);
    }

    /**
     * Store a newly created supplier.
     */
    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        // Le modele en memoire ignore les valeurs par defaut de la base
        // (`is_active`) : il est recharge pour que la reponse soit fidele.
        $supplier->refresh();

        return SupplierResource::make($supplier)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified supplier.
     */
    public function show(Supplier $supplier): SupplierResource
    {
        return SupplierResource::make($supplier);
    }

    /**
     * Update the specified supplier.
     */
    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($request->validated());

        return SupplierResource::make($supplier)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Remove the specified supplier.
     *
     * Un fournisseur rattache a des achats n'est pas supprime : ses achats
     * seraient supprimes en cascade. Il doit etre desactive.
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->purchases()->exists()) {
            return response()->json([
                'message' => 'Ce fournisseur est associé à des achats. Désactivez-le plutôt que de le supprimer.',
            ], Response::HTTP_CONFLICT);
        }

        $supplier->delete();

        return response()->json([
            'message' => 'Fournisseur supprimé.',
        ]);
    }

    /**
     * Nombre de fournisseurs par page demande, borne a une plage raisonnable.
     */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), self::MAX_PER_PAGE);
    }
}
