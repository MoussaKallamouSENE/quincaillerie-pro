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

class ExpenseApiTest extends TestCase
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
     * Session de caisse ouverte de l'utilisateur.
     */
    private function openCashSession(User $user, float $openingBalance = 50000): CashSession
    {
        return CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => $openingBalance,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    /**
     * Vente encaissée en espèces dans la session.
     */
    private function cashSale(CashSession $cashSession, User $user, float $amount): Sale
    {
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
            'method' => 'cash',
        ]);

        return $sale;
    }

    public function test_guests_cannot_access_the_expense_endpoints(): void
    {
        $this->getJson('/api/expenses')->assertUnauthorized();

        $this->postJson('/api/expenses', ['name' => 'Transport', 'amount' => 1000])
            ->assertUnauthorized();
    }

    public function test_an_expense_is_attached_to_the_open_cash_session(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user);

        $response = $this->postJson('/api/expenses', [
            'name' => 'Transport',
            'amount' => 1500.5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Transport')
            ->assertJsonPath('data.amount', '1500.50')
            ->assertJsonPath('data.cash_session_id', $cashSession->id)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.date', today()->toDateString());

        $this->assertDatabaseHas('expenses', [
            'cash_session_id' => $cashSession->id,
            'user_id' => $user->id,
            'name' => 'Transport',
            'amount' => 1500.5,
        ]);
    }

    public function test_the_cash_session_sent_by_the_client_is_ignored(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user);

        // Une session deja close, appartenant a un autre vendeur.
        $otherSession = CashSession::factory()->create([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $response = $this->postJson('/api/expenses', [
            'name' => 'Transport',
            'amount' => 1000,
            'cash_session_id' => $otherSession->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.cash_session_id', $cashSession->id);

        $this->assertDatabaseMissing('expenses', ['cash_session_id' => $otherSession->id]);
    }

    public function test_an_expense_can_be_dated(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $response = $this->postJson('/api/expenses', [
            'name' => 'Transport',
            'amount' => 1000,
            'date' => '2026-10-20',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.date', '2026-10-20');
    }

    public function test_recording_an_expense_requires_an_open_cash_session(): void
    {
        $this->authenticate();

        $this->postJson('/api/expenses', ['name' => 'Transport', 'amount' => 1000])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Aucune session de caisse n’est ouverte. Ouvrez-en une avant d’enregistrer une dépense.',
            );

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_the_expenses_of_the_current_session_are_listed(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user);

        Expense::factory()->create([
            'cash_session_id' => $cashSession->id,
            'user_id' => $user->id,
            'name' => 'Transport',
            'amount' => 1000,
        ]);

        Expense::factory()->create([
            'cash_session_id' => $cashSession->id,
            'user_id' => $user->id,
            'name' => 'Fournitures',
            'amount' => 2000,
        ]);

        // Une depense d'une autre session ne doit pas apparaitre.
        Expense::factory()->create(['amount' => 5000]);

        $response = $this->getJson('/api/expenses');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.cash_session_id', $cashSession->id)
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.total', '3000.00');

        $response->assertJsonPath('data.0.name', 'Fournitures');
        $response->assertJsonPath('data.1.name', 'Transport');
    }

    public function test_listing_the_expenses_requires_an_open_cash_session(): void
    {
        $this->authenticate();

        $this->getJson('/api/expenses')
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Aucune session de caisse n’est ouverte. Ouvrez-en une avant d’enregistrer une dépense.',
            );
    }

    public function test_an_expense_must_be_valid(): void
    {
        $user = $this->authenticate();
        $this->openCashSession($user);

        $this->postJson('/api/expenses', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'amount']);

        $this->postJson('/api/expenses', ['name' => 'Transport', 'amount' => -100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->postJson('/api/expenses', ['name' => 'Transport', 'amount' => 1000, 'date' => '20/10/2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_the_expenses_are_deducted_from_the_theoretical_cash_at_closing(): void
    {
        $user = $this->authenticate();
        $cashSession = $this->openCashSession($user, 10000);

        // 5 000 FCFA encaissés en espèces dans la session.
        $this->cashSale($cashSession, $user, 5000);

        // 2 000 FCFA de dépenses enregistrées par l'API.
        $this->postJson('/api/expenses', ['name' => 'Transport', 'amount' => 2000])
            ->assertCreated();

        // Solde theorique : 10 000 + 5 000 - 2 000 = 13 000.
        $response = $this->postJson('/api/cash-sessions/close', ['closing_balance' => 13000]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.cash_received', '5000.00')
            ->assertJsonPath('data.expenses_total', '2000.00')
            ->assertJsonPath('data.expected_cash', '13000.00')
            ->assertJsonPath('data.difference', '0.00');
    }
}
