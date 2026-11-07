<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportFilterRequest;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Supplier;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ReportsController extends Controller
{
    /**
     * Types de rapports exposes par l'API.
     *
     * @var list<string>
     */
    private const TYPES = [
        'sales', 'purchases', 'stock', 'out_of_stock', 'low_stock', 'stock_movements',
        'customers', 'suppliers', 'cash_sessions', 'cash_movements', 'expenses',
    ];

    /**
     * Libelles lisibles des rapports, utilises dans les exports PDF.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'sales' => 'Rapport des ventes',
        'purchases' => 'Rapport des achats',
        'stock' => 'État du stock',
        'out_of_stock' => 'Produits en rupture de stock',
        'low_stock' => 'Produits en stock faible',
        'stock_movements' => 'Mouvements de stock',
        'customers' => 'Répertoire des clients',
        'suppliers' => 'Répertoire des fournisseurs',
        'cash_sessions' => 'Sessions de caisse',
        'cash_movements' => 'Mouvements de caisse',
        'expenses' => 'Rapport des dépenses',
    ];

    /**
     * Nombre maximal de lignes dans un export PDF.
     */
    private const PDF_LIMIT = 500;

    /**
     * Indicateurs de synthese et graphique d'activite.
     *
     * Le chiffre d'affaires ne doit jamais etre confondu avec la tresorerie
     * ou le benefice. Le cout historique des articles vendus n'etant pas
     * conserve, le benefice et la marge restent volontairement nuls.
     */
    public function summary(ReportFilterRequest $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        $sales = Sale::query()->whereBetween('created_at', [$from, $to]);
        $receivedPurchases = Purchase::query()
            ->where('status', 'received')
            ->whereBetween('received_at', [$from, $to]);
        $expenses = Expense::query()
            ->whereBetween('date', [$from->startOfDay(), $to->endOfDay()]);

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency' => 'XOF',
            'metrics' => [
                'revenue' => round((float) (clone $sales)->sum('total_amount'), 2),
                'sales_count' => (clone $sales)->count(),
                'received_purchases' => round((float) (clone $receivedPurchases)->sum('total_amount'), 2),
                'received_purchases_count' => (clone $receivedPurchases)->count(),
                'expenses' => round((float) (clone $expenses)->sum('amount'), 2),
                'profit' => null,
                'margin' => null,
            ],
            'activity' => $this->salesActivity($from, $to),
            'top_products' => $this->topProducts($from, $to),
            'notes' => [
                'Le chiffre d’affaires correspond aux ventes enregistrées sur la période : il ne représente ni la trésorerie encaissée, ni le bénéfice.',
                'Les achats réceptionnés sont comptés à leur date de réception. Le rapport détaillé « Achats » liste les commandes par date de création et affiche leur date de réception.',
                'Le bénéfice et la marge ne sont pas calculés : le coût historique des articles vendus n’est pas conservé.',
            ],
        ]);
    }

    /**
     * Liste paginee d'un rapport.
     */
    public function index(string $type, ReportFilterRequest $request): JsonResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        [$from, $to] = $this->period($request);
        $query = $this->query($type, $from, $to, $request->string('search')->toString());
        $this->applyOrdering($query, $type);
        $rows = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'type' => $type,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /**
     * Export PDF du rapport filtre.
     */
    public function pdf(string $type, ReportFilterRequest $request): Response
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        [$from, $to] = $this->period($request);
        $query = $this->query($type, $from, $to, $request->string('search')->toString());
        $total = (clone $query)->count();
        $this->applyOrdering($query, $type);
        $rows = $query->limit(self::PDF_LIMIT)->get();
        $payload = $this->pdfPayload($type, $rows);
        $company = $this->companySettings();

        $pdf = Pdf::loadView('pdf.report', [
            'type' => $type,
            'label' => self::LABELS[$type],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'generatedAt' => CarbonImmutable::now()->format('d/m/Y à H:i'),
            'company' => $company,
            'logoDataUri' => $this->logoDataUri($company),
            'columns' => $payload['columns'],
            'rows' => $payload['rows'],
            'totals' => $payload['totals'],
            'note' => $payload['note'],
            'totalCount' => $total,
            'limit' => self::PDF_LIMIT,
            'truncated' => $total > self::PDF_LIMIT,
        ])->setPaper('a4', 'landscape');

        return $pdf->download("rapport-{$type}-{$from->toDateString()}-{$to->toDateString()}.pdf");
    }

    /**
     * Periode effective du rapport.
     *
     * Les periodes predefinies vont du debut de la periode a aujourd'hui
     * inclus. Une periode personnalisee est validee par ReportFilterRequest.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(ReportFilterRequest $request): array
    {
        $today = CarbonImmutable::today();
        $period = $request->input('period', 'month');

        [$from, $to] = match ($period) {
            'today' => [$today, $today->endOfDay()],
            'week' => [$today->startOfWeek(), $today->endOfDay()],
            'year' => [$today->startOfYear(), $today->endOfDay()],
            'custom' => [
                CarbonImmutable::parse($request->validated('from'))->startOfDay(),
                CarbonImmutable::parse($request->validated('to'))->endOfDay(),
            ],
            default => [$today->startOfMonth(), $today->endOfDay()],
        };

        return [$from, $to];
    }

    /**
     * Agregation des ventes par jour ou par mois.
     *
     * @return list<array{bucket: string, amount: float, count: int}>
     */
    private function salesActivity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        $bucketFormat = $days > 62 ? '%Y-%m' : '%Y-%m-%d';
        $driver = DB::connection()->getDriverName();
        $dateExpression = $driver === 'sqlite'
            ? "strftime('".$bucketFormat."', created_at)"
            : 'DATE_FORMAT(created_at, ?)';
        $seriesQuery = Sale::query()
            ->selectRaw("{$dateExpression} as bucket, SUM(total_amount) as amount, COUNT(*) as count");

        if ($driver !== 'sqlite') {
            $seriesQuery->addBinding($bucketFormat, 'select');
        }

        return $seriesQuery
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn (Sale $row): array => [
                'bucket' => (string) $row->getAttribute('bucket'),
                'amount' => round((float) $row->getAttribute('amount'), 2),
                'count' => (int) $row->getAttribute('count'),
            ])
            ->values()
            ->all();
    }

    /**
     * Produits les plus vendus sur la periode.
     *
     * @return list<array{id: int, name: string, sku: string, quantity: int, revenue: float}>
     */
    private function topProducts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereBetween('sales.created_at', [$from, $to])
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(sale_items.quantity) as quantity'),
                DB::raw('SUM(sale_items.subtotal) as revenue'),
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('quantity')
            ->limit(8)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'sku' => (string) $row->sku,
                'quantity' => (int) $row->quantity,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Requete de base d'un rapport.
     */
    private function query(string $type, CarbonImmutable $from, CarbonImmutable $to, string $search): Builder
    {
        $like = '%'.addcslashes($search, '%_\\').'%';
        $hasSearch = $search !== '';

        return match ($type) {
            'sales' => Sale::query()
                ->with(['customer:id,name', 'user:id,name'])
                ->withCount('items')
                ->whereBetween('created_at', [$from, $to])
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('reference', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $like))
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)))),

            'purchases' => Purchase::query()
                ->with(['supplier:id,name', 'user:id,name'])
                ->withCount('items')
                ->whereBetween('created_at', [$from, $to])
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('reference', 'like', $like)
                    ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $like))
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)))),

            'stock' => Product::query()
                ->with(['category:id,name', 'unit:id,name'])
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like))),

            'out_of_stock' => Product::query()
                ->with(['category:id,name', 'unit:id,name'])
                ->where('stock_quantity', '<=', 0)
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like))),

            'low_stock' => Product::query()
                ->with(['category:id,name', 'unit:id,name'])
                ->whereColumn('stock_quantity', '<=', 'min_stock_alert')
                ->where('stock_quantity', '>', 0)
                ->where('min_stock_alert', '>', 0)
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like))),

            'stock_movements' => StockMovement::query()
                ->with(['product:id,name,sku', 'user:id,name'])
                ->whereBetween('created_at', [$from, $to])
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('reference', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like))
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)))),

            'customers' => Customer::query()
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like))),

            'suppliers' => Supplier::query()
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like))),

            'cash_sessions' => CashSession::query()
                ->with('user:id,name')
                ->whereBetween('opened_at', [$from, $to])
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('status', 'like', $like)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)))),

            'cash_movements' => CashMovement::query()
                ->with(['user:id,name', 'cashSession:id,opened_at,status'])
                ->whereBetween('created_at', [$from, $to])
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('reason', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)))),

            'expenses' => Expense::query()
                ->with(['user:id,name', 'cashSession:id,opened_at'])
                ->whereBetween('date', [$from->startOfDay(), $to->endOfDay()])
                ->when($hasSearch, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->where('name', 'like', $like)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like)))),
        };
    }

    /**
     * Tri par defaut adapte a chaque rapport.
     */
    private function applyOrdering(Builder $query, string $type): void
    {
        match ($type) {
            'stock', 'out_of_stock' => $query->orderBy('stock_quantity')->orderBy('name'),
            'low_stock' => $query->orderByRaw('(min_stock_alert - stock_quantity) DESC')->orderBy('name'),
            'customers', 'suppliers' => $query->orderBy('name'),
            'cash_sessions' => $query->orderByDesc('opened_at')->orderByDesc('id'),
            'expenses' => $query->orderByDesc('date')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * Prepare les colonnes et les lignes d'un rapport PDF.
     *
     * Chaque type possede sa propre presentation : aucun nom de colonne SQL
     * brut ni objet JSON n'est expose dans le document.
     *
     * @param  Collection<int, Model>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function pdfPayload(string $type, Collection $rows): array
    {
        return match ($type) {
            'sales' => $this->salesPdf($rows),
            'purchases' => $this->purchasesPdf($rows),
            'stock' => $this->stockPdf($rows),
            'out_of_stock' => $this->outOfStockPdf($rows),
            'low_stock' => $this->lowStockPdf($rows),
            'stock_movements' => $this->stockMovementsPdf($rows),
            'customers' => $this->customersPdf($rows),
            'suppliers' => $this->suppliersPdf($rows),
            'cash_sessions' => $this->cashSessionsPdf($rows),
            'cash_movements' => $this->cashMovementsPdf($rows),
            'expenses' => $this->expensesPdf($rows),
        };
    }

    /**
     * @param  Collection<int, Sale>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function salesPdf(Collection $rows): array
    {
        return [
            'columns' => [
                ['label' => 'Référence'],
                ['label' => 'Date'],
                ['label' => 'Client'],
                ['label' => 'Vendeur'],
                ['label' => 'Articles', 'align' => 'center'],
                ['label' => 'Paiement'],
                ['label' => 'Total', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn (Sale $sale): array => [
                (string) $sale->reference,
                $this->dateTime($sale->created_at),
                $sale->customer?->name ?? 'Client de passage',
                $sale->user?->name ?? '—',
                $this->number($sale->getAttribute('items_count')),
                $this->paymentStatus($sale->payment_status),
                $this->money($sale->total_amount),
            ])->all(),
            'totals' => [
                ['label' => 'Ventes affichées', 'value' => $this->number($rows->count())],
                ['label' => 'Total des ventes affichées', 'value' => $this->money($rows->sum('total_amount'))],
            ],
            'note' => 'Le chiffre d’affaires ne constitue pas un bénéfice : le coût historique des articles vendus n’est pas conservé.',
        ];
    }

    /**
     * @param  Collection<int, Purchase>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function purchasesPdf(Collection $rows): array
    {
        return [
            'columns' => [
                ['label' => 'Référence'],
                ['label' => 'Date de commande'],
                ['label' => 'Fournisseur'],
                ['label' => 'Statut'],
                ['label' => 'Réceptionné le'],
                ['label' => 'Articles', 'align' => 'center'],
                ['label' => 'Total', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn (Purchase $purchase): array => [
                (string) $purchase->reference,
                $this->dateTime($purchase->created_at),
                $purchase->supplier?->name ?? '—',
                $this->purchaseStatus($purchase->status),
                $this->dateTime($purchase->received_at),
                $this->number($purchase->getAttribute('items_count')),
                $this->money($purchase->total_amount),
            ])->all(),
            'totals' => [
                ['label' => 'Commandes affichées', 'value' => $this->number($rows->count())],
                ['label' => 'Total des commandes affichées', 'value' => $this->money($rows->sum('total_amount'))],
            ],
            'note' => 'Les commandes sont listées par date de création. La date de réception est affichée séparément et sert au calcul des achats réceptionnés.',
        ];
    }

    /**
     * @param  Collection<int, Product>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function stockPdf(Collection $rows): array
    {
        $stockValue = $rows->sum(fn (Product $product): float => (float) $product->stock_quantity * (float) $product->purchase_price);

        return [
            'columns' => [
                ['label' => 'Produit'],
                ['label' => 'SKU'],
                ['label' => 'Catégorie'],
                ['label' => 'Unité'],
                ['label' => 'Stock', 'align' => 'right'],
                ['label' => 'Seuil', 'align' => 'right'],
                ['label' => 'Prix d’achat', 'align' => 'right'],
                ['label' => 'Prix de vente', 'align' => 'right'],
                ['label' => 'Valeur du stock', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn (Product $product): array => [
                (string) $product->name,
                (string) $product->sku,
                $product->category?->name ?? '—',
                $product->unit?->name ?? '—',
                $this->number($product->stock_quantity),
                $this->number($product->min_stock_alert),
                $this->money($product->purchase_price),
                $this->money($product->sale_price),
                $this->money((float) $product->stock_quantity * (float) $product->purchase_price),
            ])->all(),
            'totals' => [
                ['label' => 'Références affichées', 'value' => $this->number($rows->count())],
                ['label' => 'Valeur du stock affiché', 'value' => $this->money($stockValue)],
            ],
            'note' => 'La valeur du stock est estimée avec le prix d’achat actuel du produit ; elle ne constitue pas une valorisation comptable.',
        ];
    }

    /**
     * @param  Collection<int, Product>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function outOfStockPdf(Collection $rows): array
    {
        return [
            'columns' => [
                ['label' => 'Produit'],
                ['label' => 'SKU'],
                ['label' => 'Catégorie'],
                ['label' => 'Unité'],
                ['label' => 'Stock', 'align' => 'right'],
                ['label' => 'Seuil', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn (Product $product): array => [
                (string) $product->name,
                (string) $product->sku,
                $product->category?->name ?? '—',
                $product->unit?->name ?? '—',
                $this->number($product->stock_quantity),
                $this->number($product->min_stock_alert),
            ])->all(),
            'totals' => [
                ['label' => 'Produits en rupture', 'value' => $this->number($rows->count())],
            ],
            'note' => null,
        ];
    }

    /**
     * @param  Collection<int, Product>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function lowStockPdf(Collection $rows): array
    {
        $missing = $rows->sum(fn (Product $product): int => max(0, (int) $product->min_stock_alert - (int) $product->stock_quantity));

        return [
            'columns' => [
                ['label' => 'Produit'],
                ['label' => 'SKU'],
                ['label' => 'Catégorie'],
                ['label' => 'Unité'],
                ['label' => 'Stock', 'align' => 'right'],
                ['label' => 'Seuil', 'align' => 'right'],
                ['label' => 'Manquant', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn (Product $product): array => [
                (string) $product->name,
                (string) $product->sku,
                $product->category?->name ?? '—',
                $product->unit?->name ?? '—',
                $this->number($product->stock_quantity),
                $this->number($product->min_stock_alert),
                $this->number(max(0, (int) $product->min_stock_alert - (int) $product->stock_quantity)),
            ])->all(),
            'totals' => [
                ['label' => 'Produits sous le seuil', 'value' => $this->number($rows->count())],
                ['label' => 'Unités manquantes', 'value' => $this->number($missing)],
            ],
            'note' => null,
        ];
    }

    /**
     * @param  Collection<int, StockMovement>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function stockMovementsPdf(Collection $rows): array
    {
        $entries = $rows->filter(fn (StockMovement $movement): bool => $movement->quantity > 0)->sum('quantity');
        $exits = $rows->filter(fn (StockMovement $movement): bool => $movement->quantity < 0)->sum(fn (StockMovement $movement): int => abs((int) $movement->quantity));

        return [
            'columns' => [
                ['label' => 'Date'],
                ['label' => 'Produit'],
                ['label' => 'SKU'],
                ['label' => 'Type'],
                ['label' => 'Quantité', 'align' => 'right'],
                ['label' => 'Référence'],
                ['label' => 'Utilisateur'],
                ['label' => 'Note'],
            ],
            'rows' => $rows->map(fn (StockMovement $movement): array => [
                $this->dateTime($movement->created_at),
                $movement->product?->name ?? 'Produit supprimé',
                $movement->product?->sku ?? '—',
                $this->stockMovementType($movement->type),
                $this->signedNumber($movement->quantity),
                $movement->reference ?? '—',
                $movement->user?->name ?? '—',
                $movement->note ? Str::limit($movement->note, 90) : '—',
            ])->all(),
            'totals' => [
                ['label' => 'Mouvements affichés', 'value' => $this->number($rows->count())],
                ['label' => 'Entrées', 'value' => $this->number($entries)],
                ['label' => 'Sorties', 'value' => $this->number($exits)],
            ],
            'note' => null,
        ];
    }

    /**
     * @param  Collection<int, Customer>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function customersPdf(Collection $rows): array
    {
        return [
            'columns' => [
                ['label' => 'Client'],
                ['label' => 'Téléphone'],
                ['label' => 'Email'],
                ['label' => 'Adresse'],
                ['label' => 'Encours', 'align' => 'right'],
                ['label' => 'Limite de crédit', 'align' => 'right'],
                ['label' => 'Statut'],
            ],
            'rows' => $rows->map(fn (Customer $customer): array => [
                (string) $customer->name,
                $customer->phone ?? '—',
                $customer->email ?? '—',
                $customer->address ?? '—',
                $this->money($customer->outstanding_balance),
                $this->money($customer->credit_limit),
                $customer->is_active ? 'Actif' : 'Inactif',
            ])->all(),
            'totals' => [
                ['label' => 'Clients affichés', 'value' => $this->number($rows->count())],
                ['label' => 'Encours total', 'value' => $this->money($rows->sum('outstanding_balance'))],
            ],
            'note' => null,
        ];
    }

    /**
     * @param  Collection<int, Supplier>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function suppliersPdf(Collection $rows): array
    {
        return [
            'columns' => [
                ['label' => 'Fournisseur'],
                ['label' => 'Contact'],
                ['label' => 'Téléphone'],
                ['label' => 'Email'],
                ['label' => 'Adresse'],
                ['label' => 'Statut'],
            ],
            'rows' => $rows->map(fn (Supplier $supplier): array => [
                (string) $supplier->name,
                $supplier->contact_person ?? '—',
                $supplier->phone ?? '—',
                $supplier->email ?? '—',
                $supplier->address ?? '—',
                $supplier->is_active ? 'Actif' : 'Inactif',
            ])->all(),
            'totals' => [
                ['label' => 'Fournisseurs affichés', 'value' => $this->number($rows->count())],
            ],
            'note' => null,
        ];
    }

    /**
     * @param  Collection<int, CashSession>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function cashSessionsPdf(Collection $rows): array
    {
        return [
            'columns' => [
                ['label' => 'Ouverture'],
                ['label' => 'Clôture'],
                ['label' => 'Utilisateur'],
                ['label' => 'Statut'],
                ['label' => 'Fond initial', 'align' => 'right'],
                ['label' => 'Solde compté', 'align' => 'right'],
                ['label' => 'Solde théorique', 'align' => 'right'],
                ['label' => 'Écart', 'align' => 'right'],
            ],
            'rows' => $rows->map(function (CashSession $session): array {
                $expected = $session->expected_cash !== null ? (float) $session->expected_cash : null;
                $difference = $session->closing_balance !== null && $expected !== null
                    ? (float) $session->closing_balance - $expected
                    : null;

                return [
                    $this->dateTime($session->opened_at),
                    $this->dateTime($session->closed_at),
                    $session->user?->name ?? '—',
                    $session->status === 'open' ? 'Ouverte' : 'Clôturée',
                    $this->money($session->opening_balance),
                    $this->money($session->closing_balance),
                    $this->money($expected),
                    $this->money($difference),
                ];
            })->all(),
            'totals' => [
                ['label' => 'Sessions affichées', 'value' => $this->number($rows->count())],
                ['label' => 'Fonds initiaux', 'value' => $this->money($rows->sum('opening_balance'))],
            ],
            'note' => 'Le solde théorique d’une session clôturée est figé à sa clôture ; celui d’une session ouverte n’est affiché que s’il a déjà été calculé.',
        ];
    }

    /**
     * @param  Collection<int, CashMovement>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function cashMovementsPdf(Collection $rows): array
    {
        $in = $rows->filter(fn (CashMovement $movement): bool => $movement->type === 'in')->sum('amount');
        $out = $rows->filter(fn (CashMovement $movement): bool => $movement->type === 'out')->sum('amount');

        return [
            'columns' => [
                ['label' => 'Date'],
                ['label' => 'Session'],
                ['label' => 'Utilisateur'],
                ['label' => 'Sens'],
                ['label' => 'Motif'],
                ['label' => 'Montant', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn (CashMovement $movement): array => [
                $this->dateTime($movement->created_at),
                $this->dateTime($movement->cashSession?->opened_at),
                $movement->user?->name ?? '—',
                $movement->type === 'in' ? 'Entrée' : 'Sortie',
                (string) $movement->reason,
                $this->money($movement->amount),
            ])->all(),
            'totals' => [
                ['label' => 'Mouvements affichés', 'value' => $this->number($rows->count())],
                ['label' => 'Entrées', 'value' => $this->money($in)],
                ['label' => 'Sorties', 'value' => $this->money($out)],
            ],
            'note' => null,
        ];
    }

    /**
     * @param  Collection<int, Expense>  $rows
     * @return array{columns: list<array{label: string, align?: string}>, rows: list<list<string>>, totals: list<array{label: string, value: string}>, note: string|null}
     */
    private function expensesPdf(Collection $rows): array
    {
        return [
            'columns' => [
                ['label' => 'Date'],
                ['label' => 'Dépense'],
                ['label' => 'Auteur'],
                ['label' => 'Session de caisse'],
                ['label' => 'Montant', 'align' => 'right'],
            ],
            'rows' => $rows->map(fn (Expense $expense): array => [
                $this->date($expense->date),
                (string) $expense->name,
                $expense->user?->name ?? '—',
                $this->dateTime($expense->cashSession?->opened_at),
                $this->money($expense->amount),
            ])->all(),
            'totals' => [
                ['label' => 'Dépenses affichées', 'value' => $this->number($rows->count())],
                ['label' => 'Total des dépenses affichées', 'value' => $this->money($rows->sum('amount'))],
            ],
            'note' => null,
        ];
    }

    /**
     * Parametres entreprise affiches dans l'en-tete du PDF.
     *
     * @return array<string, mixed>
     */
    private function companySettings(): array
    {
        $value = Setting::query()->where('key', 'company.profile')->first()?->value;
        $value = is_array($value) ? $value : [];

        return array_merge([
            'business_name' => config('quincaillerie.name', 'Quincaillerie Pro'),
            'phone' => '',
            'email' => '',
            'address' => '',
            'invoice_footer' => 'Merci de votre confiance.',
            'currency' => 'XOF',
            'logo_path' => null,
        ], $value);
    }

    /**
     * Logo encode en data URI pour DomPDF.
     *
     * @param  array<string, mixed>  $company
     */
    private function logoDataUri(array $company): ?string
    {
        $logoPath = $company['logo_path'] ?? null;

        if (! is_string($logoPath) || ! str_starts_with($logoPath, 'company-logos/')) {
            return null;
        }

        if (! Storage::disk('local')->exists($logoPath)) {
            return null;
        }

        return 'data:'.Storage::disk('local')->mimeType($logoPath).';base64,'.base64_encode(Storage::disk('local')->get($logoPath));
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, 0, ',', ' ').' FCFA';
    }

    private function number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, 0, ',', ' ');
    }

    private function signedNumber(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $number = (int) $value;

        return $number > 0 ? '+'.$this->number($number) : $this->number($number);
    }

    private function date(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->format('d/m/Y') : '—';
    }

    private function dateTime(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->format('d/m/Y H:i') : '—';
    }

    private function paymentStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'Payée',
            'partial' => 'Partielle',
            'credit' => 'À crédit',
            default => $status,
        };
    }

    private function purchaseStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'En attente',
            'received' => 'Réceptionnée',
            'cancelled' => 'Annulée',
            default => $status,
        };
    }

    private function stockMovementType(string $type): string
    {
        return match ($type) {
            'in' => 'Entrée',
            'out' => 'Sortie',
            'adjustment' => 'Ajustement',
            default => $type,
        };
    }
}
