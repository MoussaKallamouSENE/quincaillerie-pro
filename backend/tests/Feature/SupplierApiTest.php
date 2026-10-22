<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un fournisseur complet, valide pour les requetes d'ecriture.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function supplierPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cimenterie du Sahel',
            'contact_person' => 'Amadou Fall',
            'phone' => '+221 77 333 22 11',
            'email' => 'contact@cimenterie-sahel.sn',
            'address' => 'Thiès, zone industrielle',
        ], $overrides);
    }

    /**
     * Authentifie l'utilisateur de la session.
     */
    private function authenticate(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_suppliers_are_listed_with_their_details(): void
    {
        $this->authenticate();

        Supplier::factory()->create([
            'name' => 'Quincaillerie Centrale',
            'contact_person' => 'Fatou Ndiaye',
        ]);

        $response = $this->getJson('/api/suppliers');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Quincaillerie Centrale')
            ->assertJsonPath('data.0.contact_person', 'Fatou Ndiaye')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'contact_person',
                    'phone',
                    'email',
                    'address',
                    'is_active',
                    'created_at',
                    'updated_at',
                ]],
                'links',
                'meta',
            ]);
    }

    public function test_supplier_listing_is_paginated(): void
    {
        $this->authenticate();

        Supplier::factory()->count(18)->create();

        $response = $this->getJson('/api/suppliers?per_page=15');

        $response->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 18)
            ->assertJsonPath('meta.per_page', 15);

        $this->getJson('/api/suppliers?per_page=15&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_supplier_listing_can_be_searched(): void
    {
        $this->authenticate();

        Supplier::factory()->create(['name' => 'Cimenterie du Sahel']);
        Supplier::factory()->create(['name' => 'Quincaillerie Centrale']);

        $this->getJson('/api/suppliers?search=quincaillerie')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Quincaillerie Centrale');
    }

    public function test_supplier_listing_can_be_filtered_by_active_state(): void
    {
        $this->authenticate();

        Supplier::factory()->inactive()->create(['name' => 'Fournisseur archivé']);
        Supplier::factory()->create(['name' => 'Fournisseur actif']);

        $this->getJson('/api/suppliers?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fournisseur archivé');

        $this->getJson('/api/suppliers?is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Fournisseur actif');
    }

    public function test_guests_cannot_access_the_supplier_endpoints(): void
    {
        $this->getJson('/api/suppliers')->assertUnauthorized();

        $this->postJson('/api/suppliers', $this->supplierPayload())->assertUnauthorized();

        $this->getJson('/api/suppliers/1')->assertUnauthorized();

        $this->putJson('/api/suppliers/1', ['name' => 'Test'])->assertUnauthorized();

        $this->deleteJson('/api/suppliers/1')->assertUnauthorized();
    }

    public function test_a_supplier_can_be_created(): void
    {
        $this->authenticate();

        $payload = $this->supplierPayload();

        $response = $this->postJson('/api/suppliers', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', $payload['name'])
            ->assertJsonPath('data.contact_person', $payload['contact_person'])
            ->assertJsonPath('data.phone', $payload['phone'])
            ->assertJsonPath('data.email', $payload['email'])
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('suppliers', [
            'name' => $payload['name'],
            'contact_person' => $payload['contact_person'],
            'is_active' => true,
        ]);
    }

    public function test_a_supplier_can_be_created_with_only_a_name(): void
    {
        $this->authenticate();

        $this->postJson('/api/suppliers', ['name' => 'Négoce General'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Négoce General')
            ->assertJsonPath('data.contact_person', null)
            ->assertJsonPath('data.phone', null);
    }

    public function test_creating_a_supplier_requires_valid_fields(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/suppliers', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_creating_a_supplier_rejects_an_invalid_email(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/suppliers', $this->supplierPayload([
            'email' => 'adresse-invalide',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_a_supplier_can_be_displayed(): void
    {
        $this->authenticate();

        $supplier = Supplier::factory()->create(['name' => 'Quincaillerie Centrale']);

        $this->getJson("/api/suppliers/{$supplier->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $supplier->id)
            ->assertJsonPath('data.name', 'Quincaillerie Centrale');
    }

    public function test_a_supplier_can_be_updated(): void
    {
        $this->authenticate();

        $supplier = Supplier::factory()->create([
            'name' => 'Cimenterie du Sahel',
            'contact_person' => 'Amadou Fall',
        ]);

        $response = $this->putJson("/api/suppliers/{$supplier->id}", [
            'name' => 'Cimenterie du Sahel SA',
            'contact_person' => 'Moussa Fall',
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Cimenterie du Sahel SA')
            ->assertJsonPath('data.contact_person', 'Moussa Fall')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Cimenterie du Sahel SA',
            'contact_person' => 'Moussa Fall',
            'is_active' => false,
        ]);
    }

    public function test_a_supplier_can_be_deleted(): void
    {
        $this->authenticate();

        $supplier = Supplier::factory()->create();

        $this->deleteJson("/api/suppliers/{$supplier->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Fournisseur supprimé.');

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_a_supplier_with_purchases_cannot_be_deleted(): void
    {
        $this->authenticate();

        $supplier = Supplier::factory()->create(['name' => 'Cimenterie du Sahel']);

        $user = User::factory()->create();

        Purchase::create([
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'reference' => 'ACH-0001',
            'total_amount' => 75000,
        ]);

        $this->deleteJson("/api/suppliers/{$supplier->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Ce fournisseur est associé à des achats. Désactivez-le plutôt que de le supprimer.',
            );

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }
}
