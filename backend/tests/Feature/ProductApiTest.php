<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un produit complet, valide pour les requetes d'ecriture.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Perceuse à percussion 800W',
            'sku' => 'PRD-0001',
            'category_id' => Category::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'purchase_price' => 45000,
            'sale_price' => 67500,
            'stock_quantity' => 12,
            'min_stock_alert' => 5,
        ], $overrides);
    }

    /**
     * Authentifie l'utilisateur de la session.
     */
    private function authenticate(): User
    {
        return $this->authenticateAs('Admin');
    }

    /**
     * Authentifie un utilisateur avec le role demande.
     */
    private function authenticateAs(string $roleName): User
    {
        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_products_are_listed_with_their_relations(): void
    {
        $this->authenticate();

        $category = Category::factory()->create(['name' => 'Outillage']);
        $unit = Unit::factory()->create(['name' => 'Pièce', 'symbol' => 'pce']);

        Product::factory()
            ->for($category)
            ->for($unit)
            ->create(['name' => 'Perceuse', 'sku' => 'PRD-0001']);

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Perceuse')
            ->assertJsonPath('data.0.sku', 'PRD-0001')
            ->assertJsonPath('data.0.category.name', 'Outillage')
            ->assertJsonPath('data.0.unit.name', 'Pièce')
            ->assertJsonPath('data.0.unit.symbol', 'pce')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'sku',
                    'name',
                    'category' => ['id', 'name'],
                    'unit' => ['id', 'name', 'symbol'],
                    'purchase_price',
                    'sale_price',
                    'stock_quantity',
                    'min_stock_alert',
                    'is_active',
                    'is_low_stock',
                ]],
                'links',
                'meta',
            ]);
    }

    public function test_product_listing_is_paginated(): void
    {
        $this->authenticate();

        Product::factory()->count(18)->create();

        $response = $this->getJson('/api/products?per_page=15');

        $response->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 18)
            ->assertJsonPath('meta.per_page', 15);

        $this->getJson('/api/products?per_page=15&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_product_listing_can_be_searched(): void
    {
        $this->authenticate();

        Product::factory()->create(['name' => 'Perceuse à percussion']);
        Product::factory()->create(['name' => 'Serrure à cylindre']);

        $this->getJson('/api/products?search=perceuse')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Perceuse à percussion');
    }

    public function test_product_listing_can_be_filtered_by_low_stock(): void
    {
        $this->authenticate();

        Product::factory()->lowStock()->create(['name' => 'Écharpe de câbles']);
        Product::factory()->create(['name' => 'Mètre ruban', 'stock_quantity' => 50, 'min_stock_alert' => 5]);

        $this->getJson('/api/products?low_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Écharpe de câbles');
    }

    public function test_guests_cannot_access_the_product_endpoints(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();

        $this->postJson('/api/products', $this->productPayload())->assertUnauthorized();

        $this->getJson('/api/products/1')->assertUnauthorized();

        $this->putJson('/api/products/1', ['name' => 'Test'])->assertUnauthorized();

        $this->deleteJson('/api/products/1')->assertUnauthorized();
    }

    public function test_guests_receive_a_401_even_without_a_json_accept_header(): void
    {
        $this->get('/api/products')->assertUnauthorized();

        $this->assertSame(
            401,
            $this->get('/api/products')->getStatusCode(),
        );
    }

    public function test_a_product_can_be_created(): void
    {
        $this->authenticate();

        $payload = $this->productPayload();

        $response = $this->postJson('/api/products', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', $payload['name'])
            ->assertJsonPath('data.sku', $payload['sku'])
            ->assertJsonPath('data.stock_quantity', $payload['stock_quantity'])
            ->assertJsonPath('data.category.id', $payload['category_id'])
            ->assertJsonPath('data.unit.id', $payload['unit_id'])
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_low_stock', false);

        $this->assertDatabaseHas('products', [
            'name' => $payload['name'],
            'sku' => $payload['sku'],
            'stock_quantity' => $payload['stock_quantity'],
            'is_active' => true,
        ]);
    }

    public function test_creating_a_product_requires_valid_fields(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/products', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'sku',
                'category_id',
                'unit_id',
                'purchase_price',
                'sale_price',
                'stock_quantity',
                'min_stock_alert',
            ]);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_creating_a_product_rejects_unknown_relations_and_duplicate_sku(): void
    {
        $this->authenticate();

        Product::factory()->create(['sku' => 'PRD-0001']);

        $response = $this->postJson('/api/products', $this->productPayload([
            'sku' => 'PRD-0001',
            'category_id' => 9999,
            'unit_id' => 9999,
            'stock_quantity' => -1,
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'category_id', 'unit_id', 'stock_quantity']);
    }

    public function test_a_product_can_be_displayed(): void
    {
        $this->authenticate();

        $product = Product::factory()->create(['name' => 'Perceuse']);

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Perceuse');
    }

    public function test_a_product_can_be_updated(): void
    {
        $this->authenticate();

        $product = Product::factory()->create([
            'name' => 'Perceuse',
            'stock_quantity' => 4,
            'sale_price' => 60000,
        ]);

        $response = $this->putJson("/api/products/{$product->id}", [
            'name' => 'Perceuse 850W',
            'stock_quantity' => 25,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Perceuse 850W')
            ->assertJsonPath('data.stock_quantity', 25)
            ->assertJsonPath('data.sale_price', '60000.00');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Perceuse 850W',
            'stock_quantity' => 25,
            'sale_price' => 60000,
        ]);
    }

    public function test_updating_a_product_keeps_its_own_sku_valid(): void
    {
        $this->authenticate();

        $product = Product::factory()->create(['sku' => 'PRD-0001']);

        $this->putJson("/api/products/{$product->id}", ['sku' => 'PRD-0001'])
            ->assertOk()
            ->assertJsonPath('data.sku', 'PRD-0001');
    }

    public function test_updating_a_product_rejects_a_duplicate_sku(): void
    {
        $this->authenticate();

        Product::factory()->create(['sku' => 'PRD-0001']);
        $product = Product::factory()->create(['sku' => 'PRD-0002']);

        $this->putJson("/api/products/{$product->id}", ['sku' => 'PRD-0001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_a_product_can_be_deleted(): void
    {
        $this->authenticate();

        $product = Product::factory()->create();

        $this->deleteJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Produit supprimé.');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_a_product_with_sale_history_cannot_be_deleted(): void
    {
        $this->authenticate();

        $product = Product::factory()->create();
        $sale = Sale::factory()->create();

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'subtotal' => 1000,
        ]);

        $this->deleteJson("/api/products/{$product->id}")
            ->assertConflict();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_a_product_with_purchase_history_cannot_be_deleted(): void
    {
        $this->authenticate();

        $product = Product::factory()->create();

        $purchase = Purchase::create([
            'supplier_id' => Supplier::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
            'reference' => 'ACH-TEST-0001',
            'total_amount' => 1000,
            'status' => 'pending',
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'subtotal' => 1000,
        ]);

        $this->deleteJson("/api/products/{$product->id}")
            ->assertConflict();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_a_product_with_stock_history_cannot_be_deleted(): void
    {
        $this->authenticate();

        $product = Product::factory()->create();

        StockMovement::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'type' => 'in',
            'quantity' => 1,
            'reference' => 'MVT-TEST-0001',
            'note' => 'Stock initial de test',
        ]);

        $this->deleteJson("/api/products/{$product->id}")
            ->assertConflict();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_creating_a_product_records_its_initial_stock(): void
    {
        $user = $this->authenticate();

        $response = $this->postJson('/api/products', $this->productPayload([
            'sku' => 'PRD-STOCK-001',
            'stock_quantity' => 12,
        ]));

        $response->assertCreated();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $response->json('data.id'),
            'user_id' => $user->id,
            'type' => 'in',
            'quantity' => 12,
            'reference' => 'INIT-PRD-STOCK-001',
            'note' => 'Stock initial à la création du produit',
        ]);
    }

    public function test_creating_a_product_without_stock_records_no_movement(): void
    {
        $this->authenticate();

        $this->postJson('/api/products', $this->productPayload([
            'sku' => 'PRD-STOCK-002',
            'stock_quantity' => 0,
        ]))->assertCreated();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_updating_the_stock_records_a_signed_adjustment(): void
    {
        $user = $this->authenticate();

        $product = Product::factory()->create([
            'sku' => 'PRD-STOCK-003',
            'stock_quantity' => 12,
        ]);

        $this->putJson("/api/products/{$product->id}", $this->productPayload([
            'sku' => $product->sku,
            'name' => $product->name,
            'stock_quantity' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 10);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'type' => 'adjustment',
            'quantity' => -2,
        ]);

        $movement = StockMovement::firstOrFail();

        $this->assertStringContainsString('stock : 12 -> 10', $movement->note);
    }

    public function test_updating_a_product_without_stock_change_records_no_movement(): void
    {
        $this->authenticate();

        $product = Product::factory()->create([
            'sku' => 'PRD-STOCK-004',
            'stock_quantity' => 12,
        ]);

        $this->putJson("/api/products/{$product->id}", $this->productPayload([
            'sku' => $product->sku,
            'name' => $product->name,
            'stock_quantity' => 12,
        ]))->assertOk();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_updating_a_product_without_the_stock_field_records_no_movement(): void
    {
        $this->authenticate();

        $product = Product::factory()->create([
            'sku' => 'PRD-STOCK-005',
            'stock_quantity' => 12,
        ]);

        $payload = $this->productPayload([
            'sku' => $product->sku,
            'name' => 'Nouveau nom',
        ]);
        unset($payload['stock_quantity']);

        $this->putJson("/api/products/{$product->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau nom');

        $this->assertSame(12, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_an_employee_can_view_the_catalogue_without_purchase_price(): void
    {
        $this->authenticateAs('Employé');

        $product = Product::factory()->create([
            'sku' => 'PRD-EMP-001',
            'purchase_price' => 12345,
        ]);

        $response = $this->getJson('/api/products')->assertOk();

        $this->assertArrayNotHasKey('purchase_price', $response->json('data.0'));

        $show = $this->getJson("/api/products/{$product->id}")->assertOk();

        $this->assertArrayNotHasKey('purchase_price', $show->json('data'));
        $this->assertSame($product->sale_price, $show->json('data.sale_price'));
    }

    public function test_an_employee_cannot_manage_products(): void
    {
        $this->authenticateAs('Employé');

        $product = Product::factory()->create();

        $this->postJson('/api/products', $this->productPayload([
            'sku' => 'PRD-EMP-002',
        ]))->assertForbidden();

        $this->putJson("/api/products/{$product->id}", $this->productPayload([
            'sku' => $product->sku,
            'name' => $product->name,
        ]))->assertForbidden();

        $this->deleteJson("/api/products/{$product->id}")->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
