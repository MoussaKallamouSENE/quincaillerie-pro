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

    public function test_employee_cannot_manage_purchases(): void
    {
        $this->authenticateAs('Employé');

        $this->getJson('/api/purchases')->assertForbidden();
        $this->postJson('/api/purchases', [])->assertForbidden();
        $this->postJson('/api/purchases/1/receive', [])->assertForbidden();
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

    public function test_manager_can_create_a_purchase(): void
    {
        $user = $this->authenticateAs('Gérant');
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
        $user = $this->authenticateAs('Admin');
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'stock_quantity' => 10,
        ]);

        $purchase = Purchase::create([
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'reference' => 'TEST-'.Str::upper(Str::random(8)),
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

    /**
     * Cree une commande d'achat d'une ligne pour les tests.
     */
    private function createPurchase(User $user, Product $product, int $quantity = 2): Purchase
    {
        $purchase = Purchase::create([
            'supplier_id' => Supplier::factory()->create()->id,
            'user_id' => $user->id,
            'reference' => 'TEST-'.Str::upper(Str::random(8)),
            'total_amount' => $quantity * 2500,
            'status' => 'pending',
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 2500,
            'subtotal' => $quantity * 2500,
        ]);

        return $purchase;
    }

    public function test_an_admin_can_view_a_purchase_with_its_items(): void
    {
        $user = $this->authenticateAs('Admin');
        $product = Product::factory()->create();
        $purchase = $this->createPurchase($user, $product, 3);

        $this->getJson("/api/purchases/{$purchase->id}")
            ->assertOk()
            ->assertJsonPath('id', $purchase->id)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.0.quantity', 3);
    }

    public function test_an_employee_cannot_view_or_cancel_a_purchase(): void
    {
        $owner = User::factory()->create();
        $product = Product::factory()->create();
        $purchase = $this->createPurchase($owner, $product);

        $this->authenticateAs('Employé');

        $this->getJson("/api/purchases/{$purchase->id}")->assertForbidden();
        $this->postJson("/api/purchases/{$purchase->id}/cancel", [])->assertForbidden();
    }

    public function test_a_pending_purchase_can_be_cancelled_without_stock_change(): void
    {
        $user = $this->authenticateAs('Admin');
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $purchase = $this->createPurchase($user, $product, 2);

        $this->postJson("/api/purchases/{$purchase->id}/cancel", [])
            ->assertOk()
            ->assertJsonPath('purchase.status', 'cancelled');

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_cancelling_a_received_purchase_reverses_the_stock_once(): void
    {
        $user = $this->authenticateAs('Admin');
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $purchase = $this->createPurchase($user, $product, 2);

        $this->postJson("/api/purchases/{$purchase->id}/receive", [])->assertOk();

        $this->assertSame(12, $product->fresh()->stock_quantity);

        $this->postJson("/api/purchases/{$purchase->id}/cancel", [])
            ->assertOk()
            ->assertJsonPath('purchase.status', 'cancelled');

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => -2,
            'reference' => $purchase->reference,
        ]);

        $this->postJson("/api/purchases/{$purchase->id}/cancel", [])
            ->assertUnprocessable();

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame(
            2,
            StockMovement::where('reference', $purchase->reference)->count()
        );
    }

    public function test_a_received_purchase_cannot_be_cancelled_without_enough_stock(): void
    {
        $user = $this->authenticateAs('Admin');
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $purchase = $this->createPurchase($user, $product, 2);

        $this->postJson("/api/purchases/{$purchase->id}/receive", [])->assertOk();

        $product->update(['stock_quantity' => 1]);

        $this->postJson("/api/purchases/{$purchase->id}/cancel", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertSame('received', $purchase->fresh()->status);
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
        ]);
    }
}
