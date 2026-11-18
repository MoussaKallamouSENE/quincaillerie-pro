<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelRefundRequest;
use App\Http\Requests\ReconcileRefundRequest;
use App\Http\Requests\RefundIndexRequest;
use App\Http\Requests\RejectRefundRequest;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Refund;
use App\Services\RefundAccessService;
use App\Services\RefundService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class RefundController extends Controller
{
    /**
     * Nombre maximum de remboursements renvoyes par page.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly RefundService $refundService,
        private readonly RefundAccessService $accessService,
    ) {}

    /**
     * Liste paginee des remboursements visibles par l'utilisateur.
     */
    public function index(RefundIndexRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $status = $request->validated('status');
        $method = $request->validated('method');
        $saleId = $request->validated('sale_id');
        $paymentId = $request->validated('payment_id');
        $search = $request->validated('search');

        $query = Refund::query()
            ->with($this->relations())
            ->when(
                ! $this->accessService->canViewAll($user),
                fn (Builder $query) => $query->where(function (Builder $query) use ($user): void {
                    $query->where('requested_by_id', $user->id)
                        ->orWhere('executed_by_id', $user->id);
                }),
            )
            ->when(
                $status !== null && $status !== 'all',
                fn (Builder $query) => $query->where('status', $status),
            )
            ->when(
                $method !== null,
                fn (Builder $query) => $query->where('method', $method),
            )
            ->when(
                $saleId !== null,
                fn (Builder $query) => $query->where('sale_id', $saleId),
            )
            ->when(
                $paymentId !== null,
                fn (Builder $query) => $query->where('payment_id', $paymentId),
            )
            ->when(
                $search !== null,
                fn (Builder $query) => $this->applySearch($query, $search),
            )
            ->latest('created_at')
            ->latest('id');

        $perPage = $request->filled('per_page')
            ? $request->integer('per_page')
            : 15;

        $refunds = $query
            ->paginate(min(max($perPage, 1), self::MAX_PER_PAGE))
            ->withQueryString();

        return RefundResource::collection($refunds);
    }

    /**
     * Affiche une demande de remboursement visible par l'utilisateur.
     */
    public function show(Request $request, Refund $refund): JsonResponse
    {
        abort_unless(
            $this->accessService->canView($request->user(), $refund),
            Response::HTTP_FORBIDDEN,
            'Vous ne pouvez pas consulter ce remboursement.',
        );

        return RefundResource::make($refund->load([...$this->relations(), 'documents.uploadedBy']))->response();
    }

    /**
     * Cree une nouvelle demande de remboursement.
     */
    public function store(StoreRefundRequest $request): JsonResponse
    {
        $refund = $this->refundService->create($request->validated(), $request->user());

        return RefundResource::make($refund)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Approuve une demande en attente ou bloquee.
     */
    public function approve(Request $request, Refund $refund): JsonResponse
    {
        $refund = $this->refundService->approve($refund, $request->user());

        return RefundResource::make($refund)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Rejette une demande en attente.
     */
    public function reject(RejectRefundRequest $request, Refund $refund): JsonResponse
    {
        $refund = $this->refundService->reject(
            $refund,
            $request->user(),
            $request->validated('reason'),
        );

        return RefundResource::make($refund)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Annule une demande en attente, bloquee ou approuvee.
     */
    public function cancel(CancelRefundRequest $request, Refund $refund): JsonResponse
    {
        $refund = $this->refundService->cancel(
            $refund,
            $request->user(),
            $request->validated('reason'),
        );

        return RefundResource::make($refund)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Execute un remboursement approuve.
     */
    public function execute(Request $request, Refund $refund): JsonResponse
    {
        $refund = $this->refundService->execute($refund, $request->user());

        return RefundResource::make($refund)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Enregistre la reconciliation d'un remboursement non especes.
     */
    public function reconcile(ReconcileRefundRequest $request, Refund $refund): JsonResponse
    {
        $refund = $this->refundService->reconcile(
            $refund,
            $request->user(),
            $request->validated(),
        );

        return RefundResource::make($refund)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Applique une recherche litterale sur les references.
     */
    private function applySearch(Builder $query, string $search): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);

        $query->where(function (Builder $query) use ($escaped): void {
            $query->whereRaw("reference LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                ->orWhereRaw("transaction_reference LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                ->orWhereHas('sale', fn (Builder $saleQuery) => $saleQuery
                    ->whereRaw("reference LIKE ? ESCAPE '!'", ["%{$escaped}%"]));
        });
    }

    /**
     * Relations chargees avec chaque remboursement.
     *
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'sale.customer',
            'sale.user',
            'payment',
            'requestedBy',
            'approvedBy',
            'rejectedBy',
            'executedBy',
            'cancelledBy',
            'reconciledBy',
            'cashSession',
            'cashMovement',
        ];
    }
}
