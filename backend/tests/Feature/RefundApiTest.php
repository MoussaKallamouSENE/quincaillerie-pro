<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\User;
use App\Services\RefundService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RefundApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    /**
     * Authentifie un utilisateur ayant le rôle demandé.
     */
    private function authenticate(string $roleName = 'Admin'): User
    {
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Vente de test pour le vendeur indiqué.
     */
    private function saleFor(User $seller, float $amount, ?Customer $customer = null): Sale
    {
        return Sale::factory()->create([
            'user_id' => $seller->id,
            'customer_id' => $customer?->id,
            'total_amount' => $amount,
            'payment_status' => 'paid',
        ]);
    }

    /**
     * Paiement de test rattaché à une vente.
     */
    private function paymentFor(
        Sale $sale,
        float $amount,
        string $method = 'wave',
        ?string $reference = null,
        string $status = Payment::STATUS_CONFIRMED,
    ): Payment {
        if ($reference === null && Payment::requiresManualVerification($method)) {
            $reference = 'PAY-'.Str::uuid()->toString();
        }

        return Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $sale->user_id,
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'status' => $status,
        ]);
    }

    /**
     * Charge utile valide d'une demande de remboursement.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function refundPayload(Sale $sale, Payment $payment, array $overrides = []): array
    {
        return array_merge([
            'sale_id' => $sale->id,
            'payment_id' => $payment->id,
            'amount' => (float) $payment->amount,
            'method' => 'wave',
            'transaction_reference' => 'REF-'.Str::uuid()->toString(),
            'reason' => 'Retour client pour article défectueux.',
            'idempotency_key' => 'refund-'.Str::uuid()->toString(),
        ], $overrides);
    }

    /**
     * Crée une demande via l'API et retourne le modèle.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createRefund(Sale $sale, Payment $payment, array $overrides = []): Refund
    {
        $response = $this->postJson('/api/refunds', $this->refundPayload($sale, $payment, $overrides))
            ->assertCreated();

        return Refund::query()->findOrFail($response->json('data.id'));
    }

    public function test_guests_cannot_access_refund_endpoints(): void
    {
        $this->getJson('/api/refunds')->assertUnauthorized();
        $this->postJson('/api/refunds', [])->assertUnauthorized();
    }

    public function test_an_employee_can_request_a_refund_without_moving_cash(): void
    {
        $employee = $this->authenticate('Employé');
        $seller = User::factory()->create();
        $customer = Customer::factory()->create(['outstanding_balance' => 2500]);
        $sale = $this->saleFor($seller, 3000, $customer);
        $payment = $this->paymentFor($sale, 3000);

        $response = $this->postJson('/api/refunds', $this->refundPayload($sale, $payment))
            ->assertCreated()
            ->assertJsonPath('data.status', Refund::STATUS_PENDING)
            ->assertJsonPath('data.amount', '3000.00')
            ->assertJsonPath('data.sale_id', $sale->id)
            ->assertJsonPath('data.payment_id', $payment->id)
            ->assertJsonPath('data.requested_by_id', $employee->id)
            ->assertJsonPath('data.approved_by_id', null);

        $refund = Refund::query()->findOrFail($response->json('data.id'));

        $this->assertMatchesRegularExpression('/^REM-\d{6}$/', $refund->reference);
        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
        $this->assertNull($refund->cumulative_amount);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame(2500.0, (float) $customer->fresh()->outstanding_balance);

        $requestedLog = AuditLog::query()
            ->where('action', 'refund.requested')
            ->where('model_id', $refund->id)
            ->sole();

        $this->assertSame($employee->id, $requestedLog->user_id);
        $this->assertNull($requestedLog->old_values);
        $this->assertSame(Refund::STATUS_PENDING, $requestedLog->new_values['status']);
    }

    public function test_refund_request_validation_rejects_invalid_payloads(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 5000);
        $payment = $this->paymentFor($sale, 5000);
        $payload = $this->refundPayload($sale, $payment);

        $withoutKey = $payload;
        unset($withoutKey['idempotency_key']);

        $this->postJson('/api/refunds', $withoutKey)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->postJson('/api/refunds', array_merge($payload, ['amount' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->postJson('/api/refunds', array_merge($payload, ['amount' => 10.001]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->postJson('/api/refunds', array_merge($payload, ['method' => 'cheque']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        $this->postJson('/api/refunds', array_merge($payload, [
            'method' => 'bank_transfer',
            'transaction_reference' => null,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_reference']);

        $withoutReason = $payload;
        unset($withoutReason['reason']);

        $this->postJson('/api/refunds', $withoutReason)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_cash_refunds_do_not_require_a_transaction_reference(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 1000);
        $payment = $this->paymentFor($sale, 1000, 'cash', null);

        $this->postJson('/api/refunds', $this->refundPayload($sale, $payment, [
            'method' => 'cash',
            'transaction_reference' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.method', 'cash')
            ->assertJsonPath('data.transaction_reference', null);
    }

    public function test_a_refund_requires_a_confirmed_payment_and_matching_sale(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 5000);
        $otherSale = $this->saleFor($seller, 5000);

        $pending = $this->paymentFor($sale, 5000, 'wave', null, Payment::STATUS_PENDING);
        $rejected = $this->paymentFor($sale, 5000, 'wave', null, Payment::STATUS_REJECTED);
        $otherPayment = $this->paymentFor($otherSale, 5000);

        $this->postJson('/api/refunds', $this->refundPayload($sale, $pending))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->postJson('/api/refunds', $this->refundPayload($sale, $rejected))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->postJson('/api/refunds', $this->refundPayload($sale, $otherPayment))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_id']);

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_refunds_cannot_exceed_the_confirmed_payment_amount(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 5000);
        $payment = $this->paymentFor($sale, 5000);

        $this->postJson('/api/refunds', $this->refundPayload($sale, $payment, [
            'amount' => 5000.01,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->createRefund($sale, $payment, ['amount' => 5000]);

        $this->postJson('/api/refunds', $this->refundPayload($sale, $payment, [
            'amount' => 0.01,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_transaction_references_are_unique_ignoring_case(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $firstSale = $this->saleFor($seller, 1000);
        $secondSale = $this->saleFor($seller, 1000);
        $firstPayment = $this->paymentFor($firstSale, 1000);
        $secondPayment = $this->paymentFor($secondSale, 1000);

        $reference = 'Wave-Ref-'.Str::upper(Str::random(8));
        $this->createRefund($firstSale, $firstPayment, ['transaction_reference' => $reference]);

        $this->postJson('/api/refunds', $this->refundPayload($secondSale, $secondPayment, [
            'transaction_reference' => mb_strtolower($reference),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_reference']);

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_a_manager_threshold_is_applied_to_the_cumulative_amount(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 200000);
        $payment = $this->paymentFor($sale, 200000);

        $first = $this->createRefund($sale, $payment, ['amount' => 99999.99]);
        $second = $this->createRefund($sale, $payment, ['amount' => 0.01]);
        $third = $this->createRefund($sale, $payment, ['amount' => 0.01]);

        $manager = $this->authenticate('Gérant');

        $this->postJson("/api/refunds/{$first->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_APPROVED)
            ->assertJsonPath('data.cumulative_amount', '99999.99')
            ->assertJsonPath('data.approved_by_id', $manager->id);

        $this->postJson("/api/refunds/{$second->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_APPROVED)
            ->assertJsonPath('data.cumulative_amount', '100000.00');

        $this->postJson("/api/refunds/{$third->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund']);

        $this->assertSame(Refund::STATUS_APPROVED, $first->fresh()->status);
        $this->assertSame(Refund::STATUS_APPROVED, $second->fresh()->status);
        $this->assertSame(Refund::STATUS_PENDING, $third->fresh()->status);
        $this->assertNull($third->fresh()->approved_by_id);
        $this->assertSame(2, AuditLog::query()->where('action', 'refund.approved')->count());
    }

    public function test_an_admin_can_approve_beyond_the_manager_threshold(): void
    {
        $this->authenticate('Gérant');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 150000);
        $payment = $this->paymentFor($sale, 150000);
        $refund = $this->createRefund($sale, $payment, ['amount' => 100000.01]);

        $admin = $this->authenticate('Admin');

        $this->postJson("/api/refunds/{$refund->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_APPROVED)
            ->assertJsonPath('data.cumulative_amount', '100000.01')
            ->assertJsonPath('data.approved_by_id', $admin->id);

        $this->assertSame(100000.01, (float) $refund->fresh()->cumulative_amount);
    }

    public function test_a_sole_admin_above_threshold_creates_a_blocked_request(): void
    {
        $admin = $this->authenticate('Admin');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 150000);
        $payment = $this->paymentFor($sale, 150000);
        $refund = $this->createRefund($sale, $payment, ['amount' => 100000.01]);

        $this->assertSame(Refund::STATUS_BLOCKED, $refund->status);
        $this->assertNotNull($refund->blocked_reason);
        $this->assertStringContainsString('seuil', (string) $refund->blocked_reason);

        $blockedLog = AuditLog::query()
            ->where('action', 'refund.blocked')
            ->where('model_id', $refund->id)
            ->sole();

        $this->assertSame(Refund::STATUS_BLOCKED, $blockedLog->new_values['status']);

        $this->authenticate('Gérant');

        $this->postJson("/api/refunds/{$refund->id}/approve")
            ->assertForbidden();

        $this->assertSame(Refund::STATUS_BLOCKED, $refund->fresh()->status);

        $otherAdmin = $this->authenticate('Admin');

        $this->postJson("/api/refunds/{$refund->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_APPROVED)
            ->assertJsonPath('data.approved_by_id', $otherAdmin->id)
            ->assertJsonPath('data.blocked_reason', null);

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
        $this->assertNotNull($refund->fresh()->approved_at);
    }

    public function test_a_sole_admin_at_the_threshold_creates_a_pending_request(): void
    {
        $this->authenticate('Admin');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 100000);
        $payment = $this->paymentFor($sale, 100000);

        $refund = $this->createRefund($sale, $payment, ['amount' => 100000]);

        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
        $this->assertNull($refund->blocked_reason);
        $this->assertSame(0, AuditLog::query()->where('action', 'refund.blocked')->count());
    }

    public function test_requester_cannot_approve_or_reject_own_refund(): void
    {
        $manager = $this->authenticate('Gérant');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 3000);
        $payment = $this->paymentFor($sale, 3000);
        $refund = $this->createRefund($sale, $payment);

        $this->postJson("/api/refunds/{$refund->id}/approve")
            ->assertForbidden();

        $this->postJson("/api/refunds/{$refund->id}/reject", [
            'reason' => 'Tentative sur sa propre demande.',
        ])->assertForbidden();

        $this->assertSame(Refund::STATUS_PENDING, $refund->fresh()->status);
        $this->assertNull($refund->fresh()->approved_by_id);
        $this->assertNull($refund->fresh()->rejected_by_id);
        $this->assertSame(1, AuditLog::query()->where('model_id', $refund->id)->count());
        $this->assertSame($manager->id, $refund->fresh()->requested_by_id);
    }

    public function test_an_employee_cannot_approve_or_reject_a_refund(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 3000);
        $payment = $this->paymentFor($sale, 3000);
        $refund = $this->createRefund($sale, $payment);

        $this->authenticate('Employé');

        $this->postJson("/api/refunds/{$refund->id}/approve")->assertForbidden();
        $this->postJson("/api/refunds/{$refund->id}/reject", [
            'reason' => 'Tentative employé.',
        ])->assertForbidden();

        $this->assertSame(Refund::STATUS_PENDING, $refund->fresh()->status);
    }

    public function test_a_manager_can_reject_a_pending_refund(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 3000);
        $payment = $this->paymentFor($sale, 3000);
        $refund = $this->createRefund($sale, $payment);
        $manager = $this->authenticate('Gérant');
        $reason = 'Référence de paiement introuvable.';

        $this->postJson("/api/refunds/{$refund->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/refunds/{$refund->id}/reject", ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_REJECTED)
            ->assertJsonPath('data.rejected_by_id', $manager->id)
            ->assertJsonPath('data.rejection_reason', $reason);

        $rejectedLog = AuditLog::query()
            ->where('action', 'refund.rejected')
            ->where('model_id', $refund->id)
            ->sole();

        $this->assertSame(['status' => Refund::STATUS_PENDING], $rejectedLog->old_values);
        $this->assertSame(Refund::STATUS_REJECTED, $rejectedLog->new_values['status']);
        $this->assertSame($reason, $rejectedLog->new_values['rejection_reason']);

        $this->postJson("/api/refunds/{$refund->id}/reject", [
            'reason' => 'Deuxième tentative.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund']);
    }

    public function test_only_the_requester_can_cancel_a_pending_refund(): void
    {
        $requester = $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 3000);
        $payment = $this->paymentFor($sale, 3000);
        $refund = $this->createRefund($sale, $payment);

        $this->authenticate('Admin');

        $this->postJson("/api/refunds/{$refund->id}/cancel", [])
            ->assertForbidden();

        Sanctum::actingAs($requester);

        $this->postJson("/api/refunds/{$refund->id}/cancel", [])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_CANCELLED)
            ->assertJsonPath('data.cancelled_by_id', $requester->id)
            ->assertJsonPath('data.cancellation_reason', null);

        $cancelledLog = AuditLog::query()
            ->where('action', 'refund.cancelled')
            ->where('model_id', $refund->id)
            ->sole();

        $this->assertSame(['status' => Refund::STATUS_PENDING], $cancelledLog->old_values);
        $this->assertSame(Refund::STATUS_CANCELLED, $cancelledLog->new_values['status']);

        $this->postJson("/api/refunds/{$refund->id}/cancel", [
            'reason' => 'Deuxième tentative.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund']);
    }

    public function test_approved_refund_cancellation_requires_permission_and_reason(): void
    {
        $requester = $this->authenticate('Employé');
        $seller = User::factory()->create();
        $customer = Customer::factory()->create(['outstanding_balance' => 2500]);
        $sale = $this->saleFor($seller, 3000, $customer);
        $payment = $this->paymentFor($sale, 3000);
        $refund = $this->createRefund($sale, $payment);

        $manager = $this->authenticate('Gérant');

        $this->postJson("/api/refunds/{$refund->id}/approve")->assertOk();

        Sanctum::actingAs($requester);

        $this->postJson("/api/refunds/{$refund->id}/cancel", [
            'reason' => 'Tentative employé.',
        ])->assertForbidden();

        Sanctum::actingAs($manager);

        $this->postJson("/api/refunds/{$refund->id}/cancel", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $reason = 'Annulation après accord du client.';

        $this->postJson("/api/refunds/{$refund->id}/cancel", ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_CANCELLED)
            ->assertJsonPath('data.cancelled_by_id', $manager->id)
            ->assertJsonPath('data.cancellation_reason', $reason);

        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame(2500.0, (float) $customer->fresh()->outstanding_balance);

        $cancelledLog = AuditLog::query()
            ->where('action', 'refund.cancelled')
            ->where('model_id', $refund->id)
            ->sole();

        $this->assertSame(['status' => Refund::STATUS_APPROVED], $cancelledLog->old_values);
        $this->assertSame(Refund::STATUS_CANCELLED, $cancelledLog->new_values['status']);
    }

    public function test_an_idempotent_refund_retry_returns_the_same_request(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 3000);
        $payment = $this->paymentFor($sale, 3000);
        $payload = $this->refundPayload($sale, $payment);

        $first = $this->postJson('/api/refunds', $payload)->assertCreated();
        $second = $this->postJson('/api/refunds', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.reference', $first->json('data.reference'));

        $refund = Refund::query()->findOrFail($first->json('data.id'));

        $this->assertSame(1, Refund::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.requested')
            ->where('model_id', $refund->id)
            ->count());
    }

    public function test_an_idempotency_key_with_different_data_is_rejected(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 3000);
        $payment = $this->paymentFor($sale, 3000);
        $payload = $this->refundPayload($sale, $payment);
        $first = $this->postJson('/api/refunds', $payload)->assertCreated();

        $this->postJson('/api/refunds', array_merge($payload, ['amount' => 500]))
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_key_conflict');

        $this->authenticate('Employé');

        $this->postJson('/api/refunds', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_key_conflict');

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame('3000.00', $first->json('data.amount'));
    }

    public function test_refund_visibility_and_filters(): void
    {
        $employeeA = $this->authenticate('Employé');
        $seller = User::factory()->create();
        $saleA = $this->saleFor($seller, 1000);
        $paymentA = $this->paymentFor($saleA, 1000);
        $refundA = $this->createRefund($saleA, $paymentA);

        $this->authenticate('Employé');
        $saleB = $this->saleFor($seller, 1000);
        $paymentB = $this->paymentFor($saleB, 1000);
        $refundB = $this->createRefund($saleB, $paymentB, [
            'method' => 'cash',
            'transaction_reference' => null,
        ]);

        $this->authenticate('Admin');

        $this->getJson('/api/refunds')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/refunds?status=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/refunds?status=pending&method=wave')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $refundA->id);

        $this->getJson("/api/refunds?sale_id={$saleB->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $refundB->id);

        $this->getJson("/api/refunds?payment_id={$paymentA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $refundA->id);

        $this->getJson('/api/refunds?search='.urlencode($refundB->reference))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $refundB->id);

        Sanctum::actingAs($employeeA);

        $this->getJson('/api/refunds')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $refundA->id);

        $this->getJson("/api/refunds/{$refundA->id}")->assertOk();
        $this->getJson("/api/refunds/{$refundB->id}")->assertForbidden();
    }

    public function test_refund_listing_is_paginated(): void
    {
        $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 10000);
        $payment = $this->paymentFor($sale, 10000);

        $first = $this->createRefund($sale, $payment, ['amount' => 1000]);
        $second = $this->createRefund($sale, $payment, ['amount' => 1000]);
        $third = $this->createRefund($sale, $payment, ['amount' => 1000]);

        $this->authenticate('Admin');

        $firstPage = $this->getJson('/api/refunds?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $secondPage = $this->getJson('/api/refunds?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 3);

        $firstIds = collect($firstPage->json('data'))->pluck('id')->all();
        $secondIds = collect($secondPage->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id, $third->id],
            [...$firstIds, ...$secondIds],
        );
        $this->assertEmpty(array_intersect($firstIds, $secondIds));
    }

    public function test_refund_filters_are_validated(): void
    {
        $this->authenticate('Admin');

        $this->getJson('/api/refunds?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/refunds?method=cheque')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        $this->getJson('/api/refunds?sale_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sale_id']);

        $this->getJson('/api/refunds?payment_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_id']);

        $this->getJson('/api/refunds?search='.str_repeat('a', 256))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);

        $this->getJson('/api/refunds?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/refunds?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);

        $this->getJson('/api/refunds?status=&method=&search=&page=&per_page=')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_stale_refund_cannot_be_approved_after_cancellation(): void
    {
        $requester = $this->authenticate('Employé');
        $seller = User::factory()->create();
        $sale = $this->saleFor($seller, 3000);
        $payment = $this->paymentFor($sale, 3000);
        $refund = $this->createRefund($sale, $payment);
        $staleRefund = Refund::query()->findOrFail($refund->id);

        $this->postJson("/api/refunds/{$refund->id}/cancel", [])->assertOk();

        $admin = $this->authenticate('Admin');
        $service = app(RefundService::class);

        try {
            $service->approve($staleRefund, $admin);
            $this->fail('Une demande annulée aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund', $exception->errors());
        }

        $this->assertSame(Refund::STATUS_CANCELLED, $refund->fresh()->status);
        $this->assertNull($refund->fresh()->approved_by_id);
        $this->assertSame(0, AuditLog::query()->where('action', 'refund.approved')->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.cancelled')
            ->where('model_id', $refund->id)
            ->count());
    }
}
