<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalePaymentRequest;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\SaleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class SaleController extends Controller
{
    /**
     * Nombre maximum de ventes renvoyees par page.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Create a new controller instance.
     */
    public function __construct(private readonly SaleService $saleService) {}

    /**
     * Display a paginated listing of the sales.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim($request->string('search')->toString());

        $sales = Sale::query()
            ->with(['customer', 'user', 'items.product', 'payments'])
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(
                $request->filled('payment_status'),
                fn (Builder $query) => $query->where('payment_status', $request->string('payment_status')->toString()),
            )
            ->when(
                $request->filled('cash_session_id'),
                fn (Builder $query) => $query->where('cash_session_id', $request->integer('cash_session_id')),
            )
            ->latest('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return SaleResource::collection($sales);
    }

    /**
     * Display the specified sale.
     *
     * The customer, the seller, the lines with their product and the payments
     * are loaded with the sale, so that the whole receipt is available in a
     * single request. An unknown sale results in a 404 response.
     */
    public function show(int $sale): JsonResponse
    {
        $model = Sale::query()
            ->with(['customer', 'user', 'items.product', 'payments'])
            ->findOrFail($sale);

        return SaleResource::make($model)->response();
    }

    /**
     * Download the sale invoice as a PDF.
     *
     * The customer, the seller, the lines with their product and the payments
     * are loaded with the sale, so that the invoice can be printed as a single
     * document.
     */
    public function downloadInvoice(Request $request, int $sale): Response
    {
        $model = Sale::query()
            ->with(['customer', 'user', 'items.product', 'payments'])
            ->findOrFail($sale);

        $user = $request->user();

        abort_unless(
            $user->hasAnyRole(['Admin', 'Gérant']) || $model->user_id === $user->id,
            Response::HTTP_FORBIDDEN,
            'Vous ne pouvez pas consulter la facture de cette vente.',
        );

        $company = Setting::query()->where('key', 'company.profile')->first()?->value ?? [];
        $company = is_array($company) ? $company : [];
        $logoPath = $company['logo_path'] ?? null;
        $logoDataUri = null;
        if (is_string($logoPath) && str_starts_with($logoPath, 'company-logos/') && Storage::disk('local')->exists($logoPath)) {
            $logoDataUri = 'data:'.Storage::disk('local')->mimeType($logoPath).';base64,'.base64_encode(Storage::disk('local')->get($logoPath));
        }

        return Pdf::loadView('pdf.invoice', ['sale' => $model, 'company' => $company, 'logoDataUri' => $logoDataUri])
            ->setPaper('a4', 'portrait')
            ->download('facture-'.$model->reference.'.pdf');
    }

    /**
     * Store a newly created sale.
     *
     * The sale, its items, its payments and the stock decrements are written
     * in a single transaction : a failing line rolls the whole sale back.
     */
    public function store(StoreSaleRequest $request): JsonResponse
    {
        $sale = $this->saleService->createSale($request->validated(), $request->user());

        return SaleResource::make($sale)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Enregistre un règlement ultérieur sur une vente à crédit.
     */
    public function addPayment(StoreSalePaymentRequest $request, Sale $sale): JsonResponse
    {
        $sale = $this->saleService->addPayment(
            $sale,
            $request->validated(),
            $request->user(),
        );

        return SaleResource::make($sale)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Nombre de ventes par page demande, borne a une plage raisonnable.
     */
    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), self::MAX_PER_PAGE);
    }
}
