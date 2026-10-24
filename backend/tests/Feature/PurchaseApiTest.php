<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Reception;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): Role
    {
        return Role::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    private function authenticateAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($this->role($role));

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guests_cannot_access_purchases(): void
    {
        $this->getJson('/api/purchases')->assertUnauthorized();
        $this->postJson('/api/purchases', [])->assertUnauthorized();
        $this->postJson('/api/purchases/1/receive', [])->assertUnauthorized();
    }

    public function test_employee_cannot_list_purchases(): void
    {
        $this->authenticateAs('Employé');

        $this->getJson('/api/purchases')->assertForbidden();
    }

    public function test_admin_can_list_purchases(): void
    {
        $this->authenticateAs('Admin');

        $this->getJson('/api/purchases')->assertOk();
    }

    public function test_manager_can_list_purchases(): void
    {
        $this->authenticateAs('Gérant');

        $this->getJson('/api/purchases')->assertOk();
    }

    public function test_authenticated_user_can_create_a_purchase(): void
    {
        $user = $this->authenticateAs('Employé');
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();

        $response = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'unit_price' => 2500,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('total_amount', '7500.00');

        $this->assertDatabaseHas('purchases', [
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('purchase_items', [
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => '2500.00',
            'subtotal' => '7500.00',
        ]);
    }

    public function test_receiving_a_purchase_updates_stock_once(): void
    {
        $user = $this->authenticateAs('Employé');
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'stock_quantity' => 10,
        ]);

        $purchase = Purchase::create([
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'reference' => 'TEST-' . Str::upper(Str::random(8)),
            'total_amount' => 5000,
            'status' => 'pending',
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 2500,
            'subtotal' => 5000,
        ]);

        $this->postJson("/api/purchases/{$purchase->id}/receive", [
            'notes' => 'Réception de test',
        ])->assertOk();

        $this->assertSame(
            12,
            $product->fresh()->stock_quantity
        );

        $this->assertDatabaseHas('receptions', [
            'purchase_id' => $purchase->id,
            'user_id' => $user->id,
            'notes' => 'Réception de test',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'type' => 'in',
            'quantity' => 2,
            'reference' => $purchase->reference,
        ]);

        $this->postJson("/api/purchases/{$purchase->id}/receive", [])
            ->assertUnprocessable();

        $this->assertSame(
            12,
            $product->fresh()->stock_quantity
        );

        $this->assertSame(
            1,
            Reception::where('purchase_id', $purchase->id)->count()
        );

        $this->assertSame(
            1,
            StockMovement::where('reference', $purchase->reference)->count()
        );
    }
}
