<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un client complet, valide pour les requetes d'ecriture.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Client Passage',
            'phone' => '+221 77 512 34 56',
            'email' => 'contact@clientpassage.sn',
            'address' => 'Dakar Plateau, rue Sandiniery',
            'credit_limit' => 500000,
        ], $overrides);
    }

    /**
     * Authentifie l'utilisateur de la session avec le rôle demandé.
     */
    private function authenticate(string $roleName = 'Admin'): User
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

    public function test_customers_are_listed_with_their_details(): void
    {
        $this->authenticate();

        Customer::factory()->create([
            'name' => 'Boutique Chez Ali',
            'phone' => '+221 78 987 65 43',
        ]);

        $response = $this->getJson('/api/customers');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Boutique Chez Ali')
            ->assertJsonPath('data.0.phone', '+221 78 987 65 43')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'phone',
                    'email',
                    'address',
                    'credit_limit',
                    'outstanding_balance',
                    'is_active',
                    'created_at',
                    'updated_at',
                ]],
                'links',
                'meta',
            ]);
    }

    public function test_customer_listing_is_paginated(): void
    {
        $this->authenticate();

        Customer::factory()->count(18)->create();

        $response = $this->getJson('/api/customers?per_page=15');

        $response->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 18)
            ->assertJsonPath('meta.per_page', 15);

        $this->getJson('/api/customers?per_page=15&page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_customer_listing_can_be_searched(): void
    {
        $this->authenticate();

        Customer::factory()->create(['name' => 'Moussa Diop']);
        Customer::factory()->create(['name' => 'Boutique Chez Ali']);

        $this->getJson('/api/customers?search=moussa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Moussa Diop');
    }

    public function test_customer_listing_can_be_filtered_by_active_state(): void
    {
        $this->authenticate();

        Customer::factory()->inactive()->create(['name' => 'Client archivé']);
        Customer::factory()->create(['name' => 'Client actif']);

        $this->getJson('/api/customers?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Client archivé');

        $this->getJson('/api/customers?is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Client actif');
    }

    public function test_guests_cannot_access_the_customer_endpoints(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();

        $this->postJson('/api/customers', $this->customerPayload())->assertUnauthorized();

        $this->getJson('/api/customers/1')->assertUnauthorized();

        $this->putJson('/api/customers/1', ['name' => 'Test'])->assertUnauthorized();

        $this->deleteJson('/api/customers/1')->assertUnauthorized();
    }

    public function test_a_customer_can_be_created(): void
    {
        $this->authenticate();

        $payload = $this->customerPayload();

        $response = $this->postJson('/api/customers', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', $payload['name'])
            ->assertJsonPath('data.phone', $payload['phone'])
            ->assertJsonPath('data.email', $payload['email'])
            ->assertJsonPath('data.credit_limit', '500000.00')
            ->assertJsonPath('data.outstanding_balance', '0.00')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('customers', [
            'name' => $payload['name'],
            'phone' => $payload['phone'],
            'is_active' => true,
        ]);
    }

    public function test_a_customer_can_be_created_with_only_a_name(): void
    {
        $this->authenticate();

        $this->postJson('/api/customers', ['name' => 'Client de passage'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Client de passage')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.email', null);
    }

    public function test_an_employee_can_create_a_customer_with_the_default_credit_limit(): void
    {
        $this->authenticate('Employé');

        $this->postJson('/api/customers', ['name' => 'Client sans plafond'])
            ->assertCreated()
            ->assertJsonPath('data.credit_limit', '0.00');

        $this->postJson('/api/customers', [
            'name' => 'Client plafond par défaut',
            'credit_limit' => 0,
        ])
            ->assertCreated()
            ->assertJsonPath('data.credit_limit', '0.00');

        $this->assertDatabaseCount('customers', 2);
    }

    public function test_an_employee_cannot_create_a_customer_with_a_positive_credit_limit(): void
    {
        $this->authenticate('Employé');

        $this->postJson('/api/customers', $this->customerPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_limit']);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_creating_a_customer_requires_valid_fields(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/customers', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_creating_a_customer_rejects_an_invalid_email_and_a_negative_credit_limit(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/customers', $this->customerPayload([
            'email' => 'adresse-invalide',
            'credit_limit' => -1,
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'credit_limit']);
    }

    public function test_a_customer_can_be_displayed(): void
    {
        $this->authenticate();

        $customer = Customer::factory()->create(['name' => 'Moussa Diop']);

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonPath('data.name', 'Moussa Diop');
    }

    public function test_a_customer_can_be_updated(): void
    {
        $this->authenticate();

        $customer = Customer::factory()->create([
            'name' => 'Moussa Diop',
            'phone' => '+221 76 123 45 67',
        ]);

        $response = $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Moussa Diop Junior',
            'phone' => '+221 76 765 43 21',
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Moussa Diop Junior')
            ->assertJsonPath('data.phone', '+221 76 765 43 21')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Moussa Diop Junior',
            'phone' => '+221 76 765 43 21',
            'is_active' => false,
        ]);
    }

    public function test_an_employee_can_update_a_customer_without_changing_the_credit_limit(): void
    {
        $this->authenticate('Employé');

        $customer = Customer::factory()->create([
            'name' => 'Moussa Diop',
            'credit_limit' => 500000,
        ]);

        $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Moussa Diop Junior',
            'credit_limit' => 500000,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Moussa Diop Junior')
            ->assertJsonPath('data.credit_limit', '500000.00');

        $this->putJson("/api/customers/{$customer->id}", [
            'phone' => '+221 76 765 43 21',
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', '+221 76 765 43 21')
            ->assertJsonPath('data.credit_limit', '500000.00');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'credit_limit' => 500000,
        ]);
    }

    public function test_an_employee_cannot_change_an_existing_credit_limit(): void
    {
        $this->authenticate('Employé');

        $customer = Customer::factory()->create(['credit_limit' => 500000]);

        $this->putJson("/api/customers/{$customer->id}", [
            'credit_limit' => 0,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_limit']);

        $this->putJson("/api/customers/{$customer->id}", [
            'credit_limit' => 750000,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_limit']);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'credit_limit' => 500000,
        ]);
    }

    public function test_a_manager_can_change_a_customer_credit_limit(): void
    {
        $this->authenticate('Gérant');

        $customer = Customer::factory()->create(['credit_limit' => 0]);

        $this->putJson("/api/customers/{$customer->id}", [
            'credit_limit' => 750000,
        ])
            ->assertOk()
            ->assertJsonPath('data.credit_limit', '750000.00');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'credit_limit' => 750000,
        ]);
    }

    public function test_a_customer_can_be_deleted(): void
    {
        $this->authenticate();

        $customer = Customer::factory()->create();

        $this->deleteJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Client supprimé.');

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_a_customer_with_sales_cannot_be_deleted(): void
    {
        $this->authenticate();

        $customer = Customer::factory()->create(['name' => 'Client Passage']);

        $user = User::factory()->create();
        $cashSession = CashSession::create([
            'user_id' => $user->id,
            'opening_balance' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        Sale::create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'cash_session_id' => $cashSession->id,
            'reference' => 'VTE-0001',
            'total_amount' => 15000,
        ]);

        $this->deleteJson("/api/customers/{$customer->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Ce client est associé à des ventes. Désactivez-le plutôt que de le supprimer.',
            );

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }
}
