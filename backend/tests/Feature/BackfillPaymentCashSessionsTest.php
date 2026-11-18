<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillPaymentCashSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function legacyCashPayment(
        User $user,
        CashSession $session,
        CarbonImmutable $createdAt,
        float $amount = 1000,
    ): Payment {
        $sale = Sale::factory()->create([
            'user_id' => $user->id,
            'cash_session_id' => $session->id,
            'total_amount' => $amount,
            'payment_status' => 'credit',
        ]);

        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'amount' => $amount,
            'method' => 'cash',
            'reference' => null,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        $payment->timestamps = false;
        $payment->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $payment;
    }

    public function test_dry_run_does_not_write_and_execute_attaches_the_origin_session(): void
    {
        $user = User::factory()->create();
        $session = CashSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
        ]);
        $payment = $this->legacyCashPayment($user, $session, CarbonImmutable::now()->subHour());

        $this->artisan('payments:backfill-cash-sessions', ['--dry-run' => true])
            ->expectsOutputToContain('Paiements éligibles: 1')
            ->expectsOutputToContain('Mode dry-run : aucune écriture.')
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'cash_session_id' => null,
        ]);

        $this->artisan('payments:backfill-cash-sessions', ['--execute' => true])
            ->expectsOutputToContain('Paiements mis à jour: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'cash_session_id' => $session->id,
        ]);
    }

    public function test_payments_created_after_the_origin_session_closed_are_left_null(): void
    {
        $user = User::factory()->create();
        $closedAt = CarbonImmutable::now()->subHour();
        $session = CashSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'closed',
            'closed_at' => $closedAt,
            'closing_balance' => 0,
        ]);
        $payment = $this->legacyCashPayment($user, $session, $closedAt->addMinutes(30));

        $this->artisan('payments:backfill-cash-sessions', ['--execute' => true])
            ->expectsOutputToContain('Paiements éligibles: 0')
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'cash_session_id' => null,
        ]);

        $this->artisan('payments:cash-session-audit')
            ->expectsOutputToContain('null_post_close_origin: 1')
            ->assertSuccessful();
    }

    public function test_before_limits_the_backfill_to_older_payments(): void
    {
        $user = User::factory()->create();
        $session = CashSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
        ]);
        $oldPayment = $this->legacyCashPayment($user, $session, CarbonImmutable::now()->subDays(2));
        $recentPayment = $this->legacyCashPayment($user, $session, CarbonImmutable::now()->subHour());

        $this->artisan('payments:backfill-cash-sessions', [
            '--execute' => true,
            '--before' => CarbonImmutable::now()->subDay()->toDateTimeString(),
        ])->expectsOutputToContain('Paiements mis à jour: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', [
            'id' => $oldPayment->id,
            'cash_session_id' => $session->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $recentPayment->id,
            'cash_session_id' => null,
        ]);
    }
}
