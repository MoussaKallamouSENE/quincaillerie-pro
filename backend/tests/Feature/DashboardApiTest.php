<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authentifie l'utilisateur de la session, avec le role demande.
     *
     * Le role est attribue avant `Sanctum::actingAs` : Spatie determine le
     * guard courant et refuserait un role `web` sur un guard `sanctum`.
     */
    private function authenticateAs(?string $role = null): User
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole(Role::firstOrCreate(['name' => $role]));
        }

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guests_cannot_read_the_dashboard(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_returns_todays_statistics(): void
    {
        $this->authenticateAs('Admin');

        Sale::factory()->create(['total_amount' => 1000, 'created_at' => now()]);
        Sale::factory()->create(['total_amount' => 2000, 'created_at' => now()]);
        Sale::factory()->create(['total_amount' => 5000, 'created_at' => now()->subDay()]);

        Product::factory()->create(['stock_quantity' => 2, 'min_stock_alert' => 5]);
        Product::factory()->create(['stock_quantity' => 5, 'min_stock_alert' => 5]);
        Product::factory()->create(['stock_quantity' => 10, 'min_stock_alert' => 5]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_revenue_today', 3000)
            ->assertJsonPath('sales_count_today', 2)
            ->assertJsonPath('low_stock_count', 2);
    }

    public function test_recent_sales_are_limited_to_the_five_latest(): void
    {
        $this->authenticateAs('Admin');

        $customer = Customer::factory()->create(['name' => 'Boutique Chez Ali']);

        foreach (range(1, 6) as $index) {
            Sale::factory()->create([
                'total_amount' => $index * 1000,
                'customer_id' => $index === 6 ? $customer->id : null,
                'created_at' => now()->subMinutes(6 - $index),
            ]);
        }

        $response = $this->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonCount(5, 'recent_sales')
            // La vente la plus recente est la sixieme creee (6000 FCFA).
            ->assertJsonPath('recent_sales.0.total_amount', 6000)
            ->assertJsonPath('recent_sales.0.customer_name', 'Boutique Chez Ali')
            ->assertJsonPath('recent_sales.4.total_amount', 2000)
            ->assertJsonPath('recent_sales.4.customer_name', null)
            ->assertJsonStructure([
                'recent_sales' => [[
                    'id',
                    'reference',
                    'customer_name',
                    'total_amount',
                    'payment_status',
                    'created_at',
                ]],
            ]);
    }

    public function test_sales_chart_covers_the_last_seven_days(): void
    {
        $this->authenticateAs('Admin');

        Sale::factory()->create(['total_amount' => 1000, 'created_at' => now()]);
        Sale::factory()->create(['total_amount' => 2500, 'created_at' => now()->subDays(3)]);
        // Hors fenetre : ne doit pas apparaitre dans le graphique.
        Sale::factory()->create(['total_amount' => 9000, 'created_at' => now()->subDays(8)]);

        $response = $this->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonCount(7, 'sales_chart')
            ->assertJsonPath('sales_chart.0.date', now()->subDays(6)->toDateString())
            ->assertJsonPath('sales_chart.0.total_amount', 0)
            ->assertJsonPath('sales_chart.0.sales_count', 0)
            ->assertJsonPath('sales_chart.3.date', now()->subDays(3)->toDateString())
            ->assertJsonPath('sales_chart.3.total_amount', 2500)
            ->assertJsonPath('sales_chart.3.sales_count', 1)
            ->assertJsonPath('sales_chart.6.date', now()->toDateString())
            ->assertJsonPath('sales_chart.6.total_amount', 1000)
            ->assertJsonPath('sales_chart.6.sales_count', 1);
    }

    public function test_employees_cannot_see_the_revenue(): void
    {
        $this->authenticateAs('Employé');

        Sale::factory()->create(['total_amount' => 5000, 'created_at' => now()]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_revenue_today', 0)
            ->assertJsonPath('sales_chart', [])
            // Les compteurs et les dernieres ventes restent accessibles.
            ->assertJsonPath('sales_count_today', 1)
            ->assertJsonCount(1, 'recent_sales');
    }

    public function test_employee_role_spelling_without_accent_is_recognised(): void
    {
        $this->authenticateAs('employe');

        Sale::factory()->create(['total_amount' => 5000, 'created_at' => now()]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_revenue_today', 0)
            ->assertJsonPath('sales_chart', []);
    }

    public function test_managers_see_the_revenue(): void
    {
        $this->authenticateAs('Gérant');

        Sale::factory()->create(['total_amount' => 5000, 'created_at' => now()]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_revenue_today', 5000)
            ->assertJsonCount(7, 'sales_chart');
    }
}
