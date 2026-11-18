<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManualPaymentIndexRequest;
use App\Http\Requests\RejectManualPaymentRequest;
use App\Http\Resources\ManualPaymentResource;
use App\Http\Resources\SaleResource;
use App\Models\Payment;
use App\Services\SaleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ManualPaymentController extends Controller
{
    /**
     * Nombre maximum de paiements renvoyes par page.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Create a new controller instance.
     */
    public function __construct(private readonly SaleService $saleService) {}

    /**
     * Liste les paiements manuels, les pending par defaut.
     */
    public function index(ManualPaymentIndexRequest $request): AnonymousResourceCollection
    {
        $status = $request->validated('status');
        $method = $request->validated('method');
        $search = $request->validated('search');

        $query = Payment::query()
            ->whereIn('method', Payment::MANUAL_METHODS)
            ->when(
                $method !== null,
                fn (Builder $query) => $query->where('method', $method),
            )
            ->when(
                $search !== null,
                fn (Builder $query) => $this->applyReferenceSearch($query, $search),
            )
            ->with([
                'sale.customer',
                'sale.user',
                'sale.payments',
                'user',
                'confirmedBy',
                'rejectedBy',
            ])
            ->when(
                $status !== 'all',
                fn (Builder $query) => $query->where('status', $status),
            )
            ->latest('created_at')
            ->latest('id');

        $payments = $request->filled('page') || $request->filled('per_page')
            ? $query
                ->paginate(min(max($request->integer('per_page', 15), 1), self::MAX_PER_PAGE))
                ->withQueryString()
            : $query->get();

        return ManualPaymentResource::collection($payments);
    }

    /**
     * Applique une recherche litterale sur la reference de transaction.
     *
     * Le caractere d'echappement `!` est explicite pour fonctionner de facon
     * identique sur MySQL et SQLite, sans dependre du mode backslash de MySQL.
     */
    private function applyReferenceSearch(Builder $query, string $search): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);

        $query->whereRaw("reference LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
    }

    /**
     * Confirme un paiement Wave ou Orange Money en attente.
     */
    public function confirm(Request $request, Payment $payment): JsonResponse
    {
        $sale = $this->saleService->confirmManualPayment($payment, $request->user());

        return SaleResource::make($sale)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Rejette un paiement Wave ou Orange Money en attente.
     */
    public function reject(RejectManualPaymentRequest $request, Payment $payment): JsonResponse
    {
        $sale = $this->saleService->rejectManualPayment(
            $payment,
            $request->user(),
            $request->validated('reason'),
        );

        return SaleResource::make($sale)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
