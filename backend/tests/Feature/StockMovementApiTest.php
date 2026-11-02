<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockMovementApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authentifie un utilisateur avec le role demande et la permission
     * d'ajuster le stock lorsque le role y a droit.
     */
    private function authenticateAs(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        if (in_array($roleName, ['Admin', 'Gérant'], true)) {
            $role->givePermissionTo(
                Permission::firstOrCreate(['name' => 'ajuster_stock'])
            );
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Charge utile valide pour un mouvement.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 1,
            'reason' => 'Réapprovisionnement manuel',
        ], $overrides);
    }

    public function test_guests_cannot_access_stock_movements(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/stock-movements')->assertUnauthorized();
        $this->postJson('/api/stock-movements', $this->payload($product))->assertUnauthorized();
    }

    public function test_an_employee_cannot_adjust_the_stock(): void
    {
        $this->authenticateAs('Employé');

        $product = Product::factory()->create(['stock_quantity' => 10]);

        $this->postJson('/api/stock-movements', $this->payload($product, [
            'quantity' => 5,
        ]))->assertForbidden();

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_an_admin_can_add_stock(): void
    {
        $this->authenticateAs('Admin');

        $product = Product::factory()->create(['stock_quantity' => 10]);

        $response = $this->postJson('/api/stock-movements', $this->payload($product, [
            'quantity' => 5,
            'reason' => 'Livraison fournisseur',
        ]));

        $response->assertCreated()
            ->assertJsonPath('movement.type', 'in')
            ->assertJsonPath('movement.quantity', 5)
            ->assertJsonPath('stock_quantity', 15);

        $this->assertSame(15, $product->fresh()->stock_quantity);

        $movement = StockMovement::firstOrFail();

        $this->assertSame('Livraison fournisseur', $movement->note);
        $this->assertNotNull($movement->reference);
        $this->assertStringStartsWith('MVT-', $movement->reference);
    }

    public function test_a_manager_can_remove_stock(): void
    {
        $this->authenticateAs('Gérant');

        $product = Product::factory()->create(['stock_quantity' => 10]);

        $this->postJson('/api/stock-movements', $this->payload($product, [
            'type' => 'out',
            'quantity' => 4,
            'reason' => 'Casse',
        ]))
            ->assertCreated()
            ->assertJsonPath('movement.type', 'out')
            ->assertJsonPath('movement.quantity', -4)
            ->assertJsonPath('stock_quantity', 6);

        $this->assertSame(6, $product->fresh()->stock_quantity);
    }

    public function test_a_stock_out_cannot_make_the_stock_negative(): void
    {
        $this->authenticateAs('Admin');

        $product = Product::factory()->create(['stock_quantity' => 10]);

        $this->postJson('/api/stock-movements', $this->payload($product, [
            'type' => 'out',
            'quantity' => 11,
            'reason' => 'Erreur de saisie',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_an_adjustment_can_set_the_stock_to_zero(): void
    {
        $this->authenticateAs('Admin');

        $product = Product::factory()->create(['stock_quantity' => 10]);

        $this->postJson('/api/stock-movements', $this->payload($product, [
            'type' => 'adjustment',
            'quantity' => 0,
            'reason' => 'Inventaire',
        ]))
            ->assertCreated()
            ->assertJsonPath('movement.type', 'adjustment')
            ->assertJsonPath('movement.quantity', -10)
            ->assertJsonPath('stock_quantity', 0);

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => -10,
        ]);
    }

    public function test_an_adjustment_records_the_delta_not_the_absolute_quantity(): void
    {
        $this->authenticateAs('Admin');

        $product = Product::factory()->create(['stock_quantity' => 10]);

        $this->postJson('/api/stock-movements', $this->payload($product, [
            'type' => 'adjustment',
            'quantity' => 13,
            'reason' => 'Inventaire',
        ]))
            ->assertCreated()
            ->assertJsonPath('movement.quantity', 3)
            ->assertJsonPath('stock_quantity', 13);

        $movement = StockMovement::firstOrFail();

        $this->assertSame(3, $movement->quantity);
        $this->assertStringContainsString('stock : 10 -> 13', $movement->note);
    }

    public function test_an_adjustment_without_change_records_no_movement(): void
    {
        $this->authenticateAs('Admin');

        $product = Product::factory()->create(['stock_quantity' => 10]);

        $this->postJson('/api/stock-movements', $this->payload($product, [
            'type' => 'adjustment',
            'quantity' => 10,
            'reason' => 'Inventaire',
        ]))
            ->assertOk()
            ->assertJsonPath('movement', null)
            ->assertJsonPath('stock_quantity', 10);

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_an_entry_or_an_exit_requires_a_positive_quantity(): void
    {
        $this->authenticateAs('Admin');

        $product = Product::factory()->create(['stock_quantity' => 10]);

        $this->postJson('/api/stock-movements', $this->payload($product, [
            'type' => 'out',
            'quantity' => 0,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->assertSame(10, $product->fresh()->stock_quantity);
    }
}
