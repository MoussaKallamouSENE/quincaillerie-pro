<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashSessionApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authentifie l'utilisateur de la session.
     */
    private function authenticate(): User
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Vente encaissée dans la session, avec son paiement.
     */
    private function saleWithPayment(
        CashSession $cashSession,
        User $user,
        float $amount,
        string $method = 'cash',
    ): Sale {
        $sale = Sale::factory()->create([
            'user_id' => $user->id,
            'cash_session_id' => $cashSession->id,
            'total_amount' => $amount,
            'payment_status' => 'paid',
        ]);

        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'amount' => $amount,
            'method' => $method,
        ]);

        return $sale;
    }

    /**
     * Depense payee en especes pendant la session.
     */
    private function expense(CashSession $cashSession, User $user, float $amount): Expense
    {
        return Expense::create([
            'cash_session_id' => $cashSession->id,
            'user_id' => $user->id,
            'name' => 'Fournitures',
            'amount' => $amount,
            'date' => now()->toDateString(),
        ]);
    }

    public function test_guests_cannot_access_the_cash_session_endpoints(): void
    {
        $this->postJson('/api/cash-sessions/open', ['opening_balance' => 10000])->assertUnauthorized();

        $this->postJson('/api/cash-sessions/close', ['closing_balance' => 10000])->assertUnauthorized();

        $this->getJson('/api/cash-sessions/current')->assertUnauthorized();
    }

    public function test_a_cash_session_can_be_opened(): void
    {
        $user = $this->authenticate();

        $response = $this->postJson('/api/cash-sessions/open', ['opening_balance' => 50000]);

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.opening_balance', '50000.00')
            ->assertJsonPath('data.closing_balance', null)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.cash_received', '0.00')
            ->assertJsonPath('data.expenses_total', '0.00')
            ->assertJsonPath('data.expected_cash', '50000.00')
            ->assertJsonPath('data.difference', null)
            ->assertJsonPath('data.opening_difference', null);

        $this->assertDatabaseHas('cash_sessions', [
            'user_id' => $user->id,
            'opening_balance' => 50000,
            'status' => 'open',
        ]);
    }

    public function test_opening_a_cash_session_requires_a_valid_opening_balance(): void
    {
        $this->authenticate();

        $this->postJson('/api/cash-sessions/open', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['opening_balance']);

        $this->postJson('/api/cash-sessions/open', ['opening_balance' => -100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['opening_balance']);

        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_a_user_cannot_hold_two_open_cash_sessions(): void
    {
        $this->authenticate();

        $this->postJson('/api/cash-sessions/open', ['opening_balance' => 50000])->assertCreated();

        $this->postJson('/api/cash-sessions/open', ['opening_balance' => 50000])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Une session de caisse est déjà ouverte. Fermez-la avant d’en ouvrir une nouvelle.',
            );

        $this->assertDatabaseCount('cash_sessions', 1);
    }

    public function test_a_cash_session_can_be_closed(): void
    {
        $user = $this->authenticate();

        $cashSession = CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => 50000,
            'status' => 'open',
        ]);

        $this->saleWithPayment($cashSession, $user, 3000);

        $response = $this->postJson('/api/cash-sessions/close', ['closing_balance' => 52500]);

        $response->assertOk()
            ->assertJsonPath('data.id', $cashSession->id)
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closing_balance', '52500.00')
            ->assertJsonPath('data.cash_received', '3000.00')
            ->assertJsonPath('data.expenses_total', '0.00')
            // 50000 de fond de caisse + 3000 encaissee en especes.
            ->assertJsonPath('data.expected_cash', '53000.00')
            // 52500 comptes - 53000 theoriques.
            ->assertJsonPath('data.difference', '-500.00')
            // 52500 comptes - 50000 de fond de caisse initial.
            ->assertJsonPath('data.opening_difference', '2500.00');

        $this->assertDatabaseHas('cash_sessions', [
            'user_id' => $user->id,
            'status' => 'closed',
            'closing_balance' => 52500,
            'expected_cash' => 53000,
        ]);

        $this->assertNotNull($cashSession->fresh()->closed_at);
    }

    public function test_closing_a_cash_session_records_the_closing_time(): void
    {
        $user = $this->authenticate();

        CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => 50000,
            'status' => 'open',
        ]);

        $this->postJson('/api/cash-sessions/close', ['closing_balance' => 50000])
            ->assertOk()
            ->assertJsonPath('data.closed_at', fn (mixed $closedAt): bool => $closedAt !== null);

        $this->assertDatabaseHas('cash_sessions', [
            'user_id' => $user->id,
            'status' => 'closed',
        ]);
    }

    public function test_the_expenses_are_deducted_from_the_theoretical_cash(): void
    {
        $user = $this->authenticate();

        $cashSession = CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => 50000,
            'status' => 'open',
        ]);

        $this->saleWithPayment($cashSession, $user, 10000);
        $this->expense($cashSession, $user, 2500);

        $this->postJson('/api/cash-sessions/close', ['closing_balance' => 57500])
            ->assertOk()
            ->assertJsonPath('data.cash_received', '10000.00')
            ->assertJsonPath('data.expenses_total', '2500.00')
            // 50000 + 10000 - 2500.
            ->assertJsonPath('data.expected_cash', '57500.00')
            ->assertJsonPath('data.difference', '0.00');
    }

    public function test_only_cash_payments_feed_the_cash_drawer(): void
    {
        $user = $this->authenticate();

        $cashSession = CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => 50000,
            'status' => 'open',
        ]);

        $this->saleWithPayment($cashSession, $user, 10000, 'cash');
        $this->saleWithPayment($cashSession, $user, 7000, 'orange_money');
        $this->saleWithPayment($cashSession, $user, 5000, 'bank_transfer');

        $this->postJson('/api/cash-sessions/close', ['closing_balance' => 60000])
            ->assertOk()
            ->assertJsonPath('data.cash_received', '10000.00')
            ->assertJsonPath('data.expected_cash', '60000.00')
            ->assertJsonPath('data.difference', '0.00');
    }

    public function test_the_theoretical_cash_is_frozen_when_the_session_is_closed(): void
    {
        $user = $this->authenticate();

        $cashSession = CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => 50000,
            'status' => 'open',
        ]);

        $this->saleWithPayment($cashSession, $user, 10000);

        $this->postJson('/api/cash-sessions/close', ['closing_balance' => 60000])
            ->assertOk()
            ->assertJsonPath('data.expected_cash', '60000.00');

        // Une depense enregistree apres la cloture ne doit pas modifier
        // l'historique de la session.
        $this->expense($cashSession, $user, 5000);

        $this->assertSame(60000.0, (float) $cashSession->fresh()->expected_cash);
        $this->assertSame(60000.0, $cashSession->fresh()->expectedCash());
    }

    public function test_closing_a_cash_session_requires_a_valid_closing_balance(): void
    {
        $user = $this->authenticate();

        CashSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $this->postJson('/api/cash-sessions/close', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['closing_balance']);

        $this->assertDatabaseHas('cash_sessions', [
            'user_id' => $user->id,
            'status' => 'open',
        ]);
    }

    public function test_closing_a_cash_session_without_an_open_session_conflicts(): void
    {
        $this->authenticate();

        $this->postJson('/api/cash-sessions/close', ['closing_balance' => 52000])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Aucune session de caisse n’est ouverte. Ouvrez-en une avant de vendre.',
            );
    }

    public function test_the_current_cash_session_is_returned(): void
    {
        $user = $this->authenticate();

        $cashSession = CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => 50000,
            'status' => 'open',
        ]);

        $this->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.id', $cashSession->id)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opening_balance', '50000.00');
    }

    public function test_the_current_cash_session_is_null_when_the_user_has_none(): void
    {
        $this->authenticate();

        $this->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_a_closed_cash_session_is_not_the_current_one(): void
    {
        $user = $this->authenticate();

        CashSession::factory()->closed()->create(['user_id' => $user->id]);

        $this->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_the_current_cash_session_ignores_the_sessions_of_other_users(): void
    {
        $this->authenticate();

        CashSession::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'open',
        ]);

        $this->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}
