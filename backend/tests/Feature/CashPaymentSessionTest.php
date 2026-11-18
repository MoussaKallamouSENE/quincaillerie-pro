<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashPaymentSessionTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function openSession(User $user, float $openingBalance = 0): CashSession
    {
        return CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => $openingBalance,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function product(): Product
    {
        return Product::factory()->create([
            'sale_price' => 1000,
            'stock_quantity' => 10,
        ]);
    }

    private function creditSale(User $seller, CashSession $session, float $total = 3000): Sale
    {
        $customer = Customer::factory()->create([
            'credit_limit' => 10000,
            'outstanding_balance' => 0,
        ]);

        return Sale::factory()->create([
            'customer_id' => $customer->id,
            'user_id' => $seller->id,
            'cash_session_id' => $session->id,
            'total_amount' => $total,
            'payment_status' => 'credit',
        ]);
    }

    public function test_an_initial_cash_payment_is_attached_to_the_vendor_session(): void
    {
        $seller = $this->userWithRole('Employé');
        $session = $this->openSession($seller);
        $product = $this->product();

        Sanctum::actingAs($seller);

        $response = $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
            'payment' => [
                'method' => 'cash',
                'amount' => 2000,
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.payments.0.cash_session_id', $session->id);

        $this->assertDatabaseHas('payments', [
            'sale_id' => $response->json('data.id'),
            'cash_session_id' => $session->id,
            'method' => 'cash',
        ]);
        $this->assertSame(2000.0, $session->fresh()->cashReceived());
    }

    public function test_a_later_cash_payment_is_attached_to_the_actor_session_not_the_sale_origin(): void
    {
        $seller = $this->userWithRole('Employé');
        $sellerSession = $this->openSession($seller);
        $sale = $this->creditSale($seller, $sellerSession);

        $manager = $this->userWithRole('Gérant');
        $managerSession = $this->openSession($manager);

        Sanctum::actingAs($manager);

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 2000,
            'method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('data.cash_session_id', $sellerSession->id)
            ->assertJsonPath('data.payments.0.cash_session_id', $managerSession->id);

        $this->assertDatabaseHas('payments', [
            'sale_id' => $sale->id,
            'user_id' => $manager->id,
            'cash_session_id' => $managerSession->id,
            'amount' => 2000,
        ]);
        $this->assertSame(0.0, $sellerSession->fresh()->cashReceived());
        $this->assertSame(2000.0, $managerSession->fresh()->cashReceived());
    }

    public function test_a_later_payment_requires_an_open_session_for_the_actor(): void
    {
        $seller = $this->userWithRole('Employé');
        $sellerSession = $this->openSession($seller);
        $sale = $this->creditSale($seller, $sellerSession);
        $manager = $this->userWithRole('Gérant');

        Sanctum::actingAs($manager);

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'method' => 'cash',
        ])->assertConflict()
            ->assertJsonPath(
                'message',
                'Aucune session de caisse n’est ouverte. Ouvrez-en une avant d’encaisser ce règlement.',
            );

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_later_payment_is_refused_when_the_sale_origin_session_is_closed(): void
    {
        $seller = $this->userWithRole('Employé');
        $closedSession = CashSession::factory()->closed()->create([
            'user_id' => $seller->id,
        ]);
        $sale = $this->creditSale($seller, $closedSession);
        $manager = $this->userWithRole('Gérant');
        $this->openSession($manager);

        Sanctum::actingAs($manager);

        $this->postJson("/api/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'method' => 'cash',
        ])->assertConflict()
            ->assertJsonPath(
                'message',
                'La session de caisse d’origine de cette vente est fermée. Impossible d’encaisser ce règlement.',
            );

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_an_idempotent_replay_is_returned_even_after_the_actor_session_is_closed(): void
    {
        $seller = $this->userWithRole('Employé');
        $sellerSession = $this->openSession($seller);
        $sale = $this->creditSale($seller, $sellerSession);
        $manager = $this->userWithRole('Gérant');
        $managerSession = $this->openSession($manager);

        Sanctum::actingAs($manager);

        $payload = [
            'amount' => 1000,
            'method' => 'cash',
            'idempotency_key' => 'rejeu-session-fermee',
        ];

        $first = $this->postJson("/api/sales/{$sale->id}/payments", $payload)
            ->assertCreated();

        $managerSession->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();

        $this->postJson("/api/sales/{$sale->id}/payments", $payload)
            ->assertCreated()
            ->assertJsonPath('data.payments.0.id', $first->json('data.payments.0.id'));

        $this->assertSame(1, Payment::query()->where('sale_id', $sale->id)->count());
    }

    public function test_crossed_open_sessions_are_allowed_and_each_payment_follows_its_actor(): void
    {
        $firstManager = $this->userWithRole('Gérant');
        $secondManager = $this->userWithRole('Gérant');
        $firstSession = $this->openSession($firstManager);
        $secondSession = $this->openSession($secondManager);

        $firstSale = $this->creditSale($firstManager, $firstSession, 1000);
        $secondSale = $this->creditSale($secondManager, $secondSession, 1000);

        Sanctum::actingAs($secondManager);

        $this->postJson("/api/sales/{$firstSale->id}/payments", [
            'amount' => 1000,
            'method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('data.payments.0.cash_session_id', $secondSession->id);

        Sanctum::actingAs($firstManager);

        $this->postJson("/api/sales/{$secondSale->id}/payments", [
            'amount' => 1000,
            'method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('data.payments.0.cash_session_id', $firstSession->id);

        $this->assertSame(1000.0, $firstSession->fresh()->cashReceived());
        $this->assertSame(1000.0, $secondSession->fresh()->cashReceived());
    }

    public function test_closing_freezes_the_drawer_from_direct_payment_attribution(): void
    {
        $seller = $this->userWithRole('Employé');
        $session = $this->openSession($seller, 5000);
        $product = $this->product();

        Sanctum::actingAs($seller);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
            'payment' => [
                'method' => 'cash',
                'amount' => 2000,
            ],
        ])->assertCreated();

        $this->postJson('/api/cash-sessions/close', [
            'closing_balance' => 7000,
        ])->assertOk()
            ->assertJsonPath('data.cash_received', '2000.00')
            ->assertJsonPath('data.expected_cash', '7000.00')
            ->assertJsonPath('data.difference', '0.00');
    }
}
