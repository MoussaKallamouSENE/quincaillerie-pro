<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends Controller
{
    /**
     * Nombre maximum de clients renvoyes par page.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Display a paginated listing of the customers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim($request->string('search')->toString());

        $customers = Customer::query()
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
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

        return CustomerResource::collection($customers);
    }

    /**
     * Store a newly created customer.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        // Le modele en memoire ignore les valeurs par defaut de la base
        // (`is_active`, `credit_limit`) : il est recharge pour que la reponse
        // soit fidele.
        $customer->refresh();

        return CustomerResource::make($customer)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified customer.
     */
    public function show(Customer $customer): CustomerResource
    {
        return CustomerResource::make($customer);
    }

    /**
     * Update the specified customer.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return CustomerResource::make($customer)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Remove the specified customer.
     *
     * Un client rattache a des ventes n'est pas supprime : ses ventes perdraient
     * leur lien avec le client. Il doit etre desactive.
     */
    public function destroy(Customer $customer): JsonResponse
    {
        if ($customer->sales()->exists()) {
            return response()->json([
                'message' => 'Ce client est associé à des ventes. Désactivez-le plutôt que de le supprimer.',
            ], Response::HTTP_CONFLICT);
        }

        $customer->delete();

        return response()->json([
            'message' => 'Client supprimé.',
        ]);
    }

    /**
     * Nombre de clients par page demande, borne a une plage raisonnable.
     */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), self::MAX_PER_PAGE);
    }
}
