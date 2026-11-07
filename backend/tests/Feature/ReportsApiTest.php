<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsApiTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(string $role = 'Admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_admin_and_manager_can_read_reports_while_employees_are_forbidden(): void
    {
        foreach (['Admin', 'Gérant'] as $role) {
            $this->authenticate($role);

            $this->getJson('/api/reports/summary')->assertOk();
            $this->getJson('/api/reports/sales')->assertOk();
            $this->get('/api/reports/sales/pdf')->assertOk();
        }

        $this->authenticate('Employé');

        $this->getJson('/api/reports/summary')->assertForbidden();
        $this->getJson('/api/reports/sales')->assertForbidden();
        $this->get('/api/reports/sales/pdf')->assertForbidden();
    }

    public function test_summary_filters_sales_expenses_and_received_purchases_by_their_business_dates(): void
    {
        $this->authenticate('Gérant');

        Sale::factory()->create(['total_amount' => 12000, 'created_at' => now()]);
        Sale::factory()->create(['total_amount' => 9000, 'created_at' => now()->subDay()]);

        $supplier = Supplier::factory()->create();
        $user = User::query()->firstOrFail();

        Purchase::forceCreate([
            'supplier_id' => $supplier->id, 'user_id' => $user->id,
            'reference' => 'PUR-RECEIVED-TODAY', 'total_amount' => 4000, 'status' => 'received',
            'created_at' => now()->subDays(10), 'received_at' => now(),
        ]);
        Purchase::forceCreate([
            'supplier_id' => $supplier->id, 'user_id' => $user->id,
            'reference' => 'PUR-RECEIVED-YESTERDAY', 'total_amount' => 7000, 'status' => 'received',
            'created_at' => now(), 'received_at' => now()->subDay(),
        ]);
        Purchase::forceCreate([
            'supplier_id' => $supplier->id, 'user_id' => $user->id,
            'reference' => 'PUR-PENDING', 'total_amount' => 8000, 'status' => 'pending',
            'created_at' => now(), 'received_at' => null,
        ]);
        Purchase::forceCreate([
            'supplier_id' => $supplier->id, 'user_id' => $user->id,
            'reference' => 'PUR-CANCELLED', 'total_amount' => 9000, 'status' => 'cancelled',
            'created_at' => now(), 'received_at' => null,
        ]);

        Expense::factory()->create(['amount' => 1500, 'date' => now()->toDateString()]);
        Expense::factory()->create(['amount' => 500, 'date' => now()->subDay()->toDateString()]);

        $this->getJson('/api/reports/summary?period=today')
            ->assertOk()
            ->assertJsonPath('metrics.revenue', 12000)
            ->assertJsonPath('metrics.sales_count', 1)
            ->assertJsonPath('metrics.received_purchases', 4000)
            ->assertJsonPath('metrics.received_purchases_count', 1)
            ->assertJsonPath('metrics.expenses', 1500)
            ->assertJsonPath('metrics.profit', null)
            ->assertJsonPath('metrics.margin', null)
            ->assertJsonCount(3, 'notes');
    }

    public function test_summary_includes_sales_at_the_start_and_the_end_of_the_day(): void
    {
        $this->authenticate();

        Sale::factory()->create(['total_amount' => 1000, 'created_at' => now()->startOfDay()]);
        Sale::factory()->create(['total_amount' => 2000, 'created_at' => now()->endOfDay()]);
        Sale::factory()->create(['total_amount' => 4000, 'created_at' => now()->subDay()->endOfDay()]);

        $this->getJson('/api/reports/summary?period=today')
            ->assertOk()
            ->assertJsonPath('metrics.revenue', 3000)
            ->assertJsonPath('metrics.sales_count', 2);
    }

    public function test_summary_calculates_top_selling_products_without_duplicate_sale_totals(): void
    {
        $this->authenticate();
        $sale = Sale::factory()->create(['total_amount' => 5000, 'created_at' => now()]);
        $product = Product::factory()->create(['name' => 'Ciment', 'sku' => 'CIM-1']);
        SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 2500, 'subtotal' => 5000]);

        $this->getJson('/api/reports/summary?period=today')
            ->assertOk()
            ->assertJsonPath('metrics.revenue', 5000)
            ->assertJsonPath('top_products.0.quantity', 2)
            ->assertJsonPath('top_products.0.revenue', 5000);
    }

    public function test_custom_period_validation_and_pagination_limit(): void
    {
        $this->authenticate();
        $this->getJson('/api/reports/sales?period=custom&from=2026-11-10&to=2026-11-01')->assertUnprocessable();
        $this->getJson('/api/reports/sales?period=custom')->assertUnprocessable();
        $this->getJson('/api/reports/sales?period=custom&from=2026-11-01&to=2026-11-02&per_page=101')->assertUnprocessable();
        $this->getJson('/api/reports/sales?period=custom&from=2026-11-01&to=2026-11-02')->assertOk();
    }

    public function test_all_eleven_report_types_are_available(): void
    {
        $user = $this->authenticate();
        $supplier = Supplier::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'stock_quantity' => 2,
            'min_stock_alert' => 5,
            'purchase_price' => 1000,
            'sale_price' => 1500,
        ]);
        Product::factory()->create(['stock_quantity' => 0, 'min_stock_alert' => 2]);

        $sale = Sale::factory()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'total_amount' => 3000,
            'created_at' => now(),
        ]);
        SaleItem::query()->create([
            'sale_id' => $sale->id, 'product_id' => $product->id,
            'quantity' => 2, 'unit_price' => 1500, 'subtotal' => 3000,
        ]);

        Purchase::forceCreate([
            'supplier_id' => $supplier->id, 'user_id' => $user->id,
            'reference' => 'PUR-TEST-001', 'total_amount' => 5000, 'status' => 'received',
            'created_at' => now(), 'received_at' => now(),
        ]);

        $cashSession = CashSession::factory()->create(['user_id' => $user->id, 'opened_at' => now()]);
        CashMovement::query()->create([
            'cash_session_id' => $cashSession->id, 'user_id' => $user->id,
            'type' => 'in', 'amount' => 2000, 'reason' => 'Fond de caisse',
        ]);
        Expense::factory()->create([
            'cash_session_id' => $cashSession->id, 'user_id' => $user->id,
            'name' => 'Transport', 'amount' => 1000, 'date' => now()->toDateString(),
        ]);
        StockMovement::query()->create([
            'product_id' => $product->id, 'user_id' => $user->id,
            'type' => 'in', 'quantity' => 3, 'reference' => 'MVT-TEST-001',
            'note' => 'Réapprovisionnement', 'created_at' => now(),
        ]);

        foreach ([
            'sales', 'purchases', 'stock', 'out_of_stock', 'low_stock', 'stock_movements',
            'customers', 'suppliers', 'cash_sessions', 'cash_movements', 'expenses',
        ] as $type) {
            $this->getJson("/api/reports/{$type}?period=today&per_page=10")
                ->assertOk()
                ->assertJsonPath('type', $type)
                ->assertJsonStructure([
                    'type', 'period' => ['from', 'to'], 'data',
                    'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                ]);
        }
    }

    public function test_search_and_pagination_are_applied_to_reports(): void
    {
        $this->authenticate();
        $customer = Customer::factory()->create(['name' => 'Awa Diallo']);
        Sale::factory()->create([
            'customer_id' => $customer->id,
            'reference' => 'VTE-ALPHA',
            'total_amount' => 1000,
            'created_at' => now(),
        ]);
        Sale::factory()->create([
            'reference' => 'VTE-BETA',
            'total_amount' => 2000,
            'created_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/reports/sales?period=today&search=Awa')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', 'VTE-ALPHA');

        $this->getJson('/api/reports/sales?period=today&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_stock_reports_detect_out_of_stock_and_low_stock_products(): void
    {
        $this->authenticate();
        $out = Product::factory()->create(['name' => 'Produit rupture', 'stock_quantity' => 0]);
        $low = Product::factory()->create(['name' => 'Produit faible', 'stock_quantity' => 2, 'min_stock_alert' => 5]);
        Product::factory()->create(['name' => 'Produit sain', 'stock_quantity' => 10, 'min_stock_alert' => 5]);

        $this->getJson('/api/reports/out_of_stock?period=month')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $out->id);

        $this->getJson('/api/reports/low_stock?period=month')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $low->id);

        $this->getJson('/api/reports/stock?period=month')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_stock_movements_cash_sessions_cash_movements_and_expenses_reports(): void
    {
        $user = $this->authenticate();
        $product = Product::factory()->create(['name' => 'Marteau', 'sku' => 'MRT-001']);
        $cashSession = CashSession::factory()->create(['user_id' => $user->id, 'opened_at' => now()]);

        StockMovement::forceCreate([
            'product_id' => $product->id, 'user_id' => $user->id,
            'type' => 'in', 'quantity' => 5, 'reference' => 'MVT-001',
            'note' => 'Entrée initiale', 'created_at' => now(),
        ]);
        StockMovement::forceCreate([
            'product_id' => $product->id, 'user_id' => $user->id,
            'type' => 'out', 'quantity' => -2, 'reference' => 'MVT-002',
            'note' => 'Sortie atelier', 'created_at' => now()->subDay(),
        ]);
        CashMovement::query()->create([
            'cash_session_id' => $cashSession->id, 'user_id' => $user->id,
            'type' => 'in', 'amount' => 20000, 'reason' => 'Fond de caisse',
        ]);
        Expense::factory()->create([
            'cash_session_id' => $cashSession->id, 'user_id' => $user->id,
            'name' => 'Carburant', 'amount' => 3000, 'date' => now()->toDateString(),
        ]);

        $this->getJson('/api/reports/stock_movements?period=today&search=Marteau')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.name', 'Marteau');

        $this->getJson('/api/reports/cash_sessions?period=today&search='.urlencode($user->name))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user.name', $user->name);

        $this->getJson('/api/reports/cash_movements?period=today&search=caisse')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reason', 'Fond de caisse');

        $this->getJson('/api/reports/expenses?period=today&search=Carburant')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Carburant')
            ->assertJsonPath('data.0.user.name', $user->name);
    }

    public function test_purchase_report_lists_orders_by_creation_date_and_exposes_received_date(): void
    {
        $this->authenticate();
        $supplier = Supplier::factory()->create(['name' => 'Fournisseur Test']);
        $user = User::query()->firstOrFail();

        Purchase::forceCreate([
            'supplier_id' => $supplier->id, 'user_id' => $user->id,
            'reference' => 'PUR-DATE-001', 'total_amount' => 4000, 'status' => 'received',
            'created_at' => now()->subDay(), 'received_at' => now(),
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();

        $this->getJson("/api/reports/purchases?period=custom&from={$from}&to={$to}&search=Fournisseur")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', 'PUR-DATE-001')
            ->assertJsonPath('data.0.status', 'received')
            ->assertJsonPath('data.0.received_at', fn ($value) => $value !== null);

        $this->getJson('/api/reports/summary?period=today')
            ->assertOk()
            ->assertJsonPath('metrics.received_purchases', 4000)
            ->assertJsonPath('metrics.received_purchases_count', 1);
    }

    public function test_pdf_is_generated_for_each_report_type_and_for_an_empty_report(): void
    {
        $this->authenticate();

        foreach ([
            'sales', 'purchases', 'stock', 'out_of_stock', 'low_stock', 'stock_movements',
            'customers', 'suppliers', 'cash_sessions', 'cash_movements', 'expenses',
        ] as $type) {
            $this->get("/api/reports/{$type}/pdf?period=today")
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }

        $this->get('/api/reports/customers/pdf?period=today')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_unknown_report_type_is_rejected(): void
    {
        $this->authenticate();
        $this->getJson('/api/reports/unknown')->assertNotFound();
        $this->get('/api/reports/unknown/pdf')->assertNotFound();
    }
}
