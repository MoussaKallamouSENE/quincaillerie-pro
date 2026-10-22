<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    /**
     * Nombre de ventes affichees dans « dernieres ventes ».
     */
    private const RECENT_SALES_LIMIT = 5;

    /**
     * Nombre de jours couverts par le graphique.
     */
    private const CHART_DAYS = 7;

    /**
     * Statistiques du tableau de bord.
     *
     * Les montants globaux (chiffre d'affaires du jour et graphique) sont
     * masques pour les employes : ils ne doivent pas voir l'argent genere par
     * la quincaillerie. Les compteurs de ventes, les alertes de stock et les
     * dernieres ventes restent disponibles.
     */
    public function index(Request $request): JsonResponse
    {
        $canViewRevenue = $this->canViewRevenue($request->user());

        $todaySales = Sale::query()->whereDate('created_at', today());

        return response()->json([
            'total_revenue_today' => $canViewRevenue
                ? round((float) (clone $todaySales)->sum('total_amount'), 2)
                : 0,
            'sales_count_today' => (clone $todaySales)->count(),
            'low_stock_count' => Product::query()
                ->whereColumn('stock_quantity', '<=', 'min_stock_alert')
                ->count(),
            'recent_sales' => $this->recentSales(),
            'sales_chart' => $canViewRevenue ? $this->salesChart() : [],
        ]);
    }

    /**
     * Vrai si l'utilisateur peut voir le chiffre d'affaires.
     *
     * La comparaison ignore la casse et les accents afin de reconnaitre aussi
     * bien le role « Employé » que la graphie « employe ».
     */
    private function canViewRevenue(User $user): bool
    {
        return ! $user->roles->contains(
            fn (Role $role): bool => Str::of($role->name)->ascii()->lower()->toString() === 'employe',
        );
    }

    /**
     * Dernieres ventes, avec le nom du client et le montant.
     *
     * Une vente au comptant n'a pas de client : `customer_name` vaut alors
     * `null`.
     *
     * @return list<array{id: int, reference: string, customer_name: string|null, total_amount: float, payment_status: string, created_at: string|null}>
     */
    private function recentSales(): array
    {
        return Sale::query()
            ->with('customer:id,name')
            ->latest()
            ->take(self::RECENT_SALES_LIMIT)
            ->get()
            ->map(fn (Sale $sale): array => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer_name' => $sale->customer?->name,
                'total_amount' => round((float) $sale->total_amount, 2),
                'payment_status' => $sale->payment_status,
                'created_at' => $sale->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Ventes agregees par jour sur les 7 derniers jours.
     *
     * Les jours sans vente sont inclus avec un total nul : le graphique
     * conserve ainsi toujours 7 points.
     *
     * @return list<array{date: string, label: string, total_amount: float, sales_count: int}>
     */
    private function salesChart(): array
    {
        $start = now()->subDays(self::CHART_DAYS - 1)->startOfDay();

        /** @var Collection<string, Sale> $totals */
        $totals = Sale::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, SUM(total_amount) as total_amount, COUNT(*) as sales_count')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $chart = [];

        for ($daysAgo = self::CHART_DAYS - 1; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo)->startOfDay();
            $key = $date->toDateString();
            $row = $totals->get($key);

            $chart[] = [
                'date' => $key,
                'label' => $date->locale('fr')->isoFormat('ddd D'),
                'total_amount' => $row ? round((float) $row->total_amount, 2) : 0.0,
                'sales_count' => $row ? (int) $row->sales_count : 0,
            ];
        }

        return $chart;
    }
}
