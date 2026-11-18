<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundDocument;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RefundExecutionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('local');
    }

    private function authenticate(string $roleName = 'Employé'): User
    {
        $user = $this->userWithRole($roleName);

        Sanctum::actingAs($user);

        return $user;
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function refundFor(
        User $requester,
        string $method = 'wave',
        string $status = Refund::STATUS_APPROVED,
        float $amount = 5000,
        ?User $executor = null,
        ?CashSession $cashSession = null,
        ?User $approver = null,
        ?float $paymentAmount = null,
    ): Refund {
        $saleAmount = $paymentAmount ?? $amount;

        $sale = Sale::factory()->create([
            'user_id' => $requester->id,
            'total_amount' => $saleAmount,
            'payment_status' => 'paid',
        ]);

        $payment = Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $requester->id,
            'amount' => $saleAmount,
            'method' => $method,
            'reference' => $method === 'cash'
                ? null
                : 'PAY-'.Str::uuid()->toString(),
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        $timestamps = in_array($status, [
            Refund::STATUS_APPROVED,
            Refund::STATUS_EXECUTING,
            Refund::STATUS_RECONCILIATION_PENDING,
            Refund::STATUS_EXECUTED,
            Refund::STATUS_FAILED_CONFIRMED,
        ], true)
            ? ['approved_at' => now()]
            : [];

        if (in_array($status, [
            Refund::STATUS_EXECUTING,
            Refund::STATUS_RECONCILIATION_PENDING,
            Refund::STATUS_EXECUTED,
            Refund::STATUS_FAILED_CONFIRMED,
        ], true)) {
            $timestamps['execution_started_at'] = now();
        }

        return Refund::create(array_merge([
            'reference' => 'REM-'.Str::upper(Str::random(12)),
            'sale_id' => $sale->id,
            'payment_id' => $payment->id,
            'requested_by_id' => $requester->id,
            'approved_by_id' => $approver?->id,
            'executed_by_id' => $executor?->id,
            'cash_session_id' => $cashSession?->id,
            'amount' => $amount,
            'method' => $method,
            'transaction_reference' => in_array($method, [
                Refund::METHOD_WAVE,
                Refund::METHOD_ORANGE_MONEY,
                Refund::METHOD_BANK_TRANSFER,
            ], true)
                ? 'REF-'.Str::uuid()->toString()
                : null,
            'reason' => 'Retour client pour execution de test.',
            'status' => $status,
            'idempotency_key' => Str::uuid()->toString(),
            'idempotency_fingerprint' => hash('sha256', Str::uuid()->toString()),
        ], $timestamps));
    }

    private function openCashSession(User $user, float $openingBalance = 50000): CashSession
    {
        return CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => $openingBalance,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function refundOnExistingPayment(
        int $saleId,
        int $paymentId,
        User $requester,
        float $amount,
        string $status,
        ?User $approver = null,
    ): Refund {
        return Refund::create([
            'reference' => 'REM-'.Str::upper(Str::random(12)),
            'sale_id' => $saleId,
            'payment_id' => $paymentId,
            'requested_by_id' => $requester->id,
            'approved_by_id' => $approver?->id,
            'approved_at' => $approver instanceof User ? now() : null,
            'amount' => $amount,
            'method' => 'wave',
            'transaction_reference' => 'REF-'.Str::uuid()->toString(),
            'reason' => 'Réservation concurrente de test.',
            'status' => $status,
            'idempotency_key' => Str::uuid()->toString(),
            'idempotency_fingerprint' => hash('sha256', Str::uuid()->toString()),
        ]);
    }

    private function pdfContent(int $targetSize = 512): string
    {
        $prefix = "%PDF-1.4\n";
        $suffix = "\n%%EOF\n";
        $padding = max(0, $targetSize - strlen($prefix) - strlen($suffix));

        return $prefix.str_repeat(' ', $padding).$suffix;
    }

    private function uploadDocument(
        Refund $refund,
        User $actor,
        string $kind = RefundDocument::KIND_EXECUTION_PROOF,
        ?string $content = null,
    ): TestResponse {
        Sanctum::actingAs($actor);

        return $this->post(
            "/api/refunds/{$refund->id}/documents",
            [
                'kind' => $kind,
                'file' => UploadedFile::fake()->createWithContent(
                    'justificatif.pdf',
                    $content ?? $this->pdfContent(),
                ),
            ],
            ['Accept' => 'application/json'],
        );
    }

    public function test_cash_execution_requires_a_valid_execution_proof(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $this->openCashSession($executor);
        $refund = $this->refundFor($requester, 'cash', approver: $approver);

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund']);

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);

        $this->uploadDocument($refund, $executor)->assertCreated();

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED)
            ->assertJsonPath('data.executed_by_id', $executor->id);
    }

    public function test_cash_execution_moves_money_once_and_updates_the_cash_session(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $cashSession = $this->openCashSession($executor, 50000);
        $refund = $this->refundFor($requester, 'cash', amount: 5000, approver: $approver);
        $this->uploadDocument($refund, $executor)->assertCreated();

        $sale = $refund->sale;
        $payment = $refund->payment;

        $response = $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED)
            ->assertJsonPath('data.executed_by_id', $executor->id)
            ->assertJsonPath('data.cash_session_id', $cashSession->id)
            ->assertJsonPath('data.executed_at', fn (mixed $executedAt): bool => $executedAt !== null);

        $movement = CashMovement::query()->sole();

        $this->assertSame($cashSession->id, $movement->cash_session_id);
        $this->assertSame($executor->id, $movement->user_id);
        $this->assertSame($refund->id, $movement->refund_id);
        $this->assertSame('out', $movement->type);
        $this->assertSame(5000.0, (float) $movement->amount);
        $this->assertSame($movement->id, $response->json('data.cash_movement_id'));

        $this->assertSame(5000.0, $cashSession->fresh()->refundsPaid());
        $this->assertSame(45000.0, $cashSession->fresh()->expectedCash());

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.execution_started')
            ->where('model_id', $refund->id)
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.executed')
            ->where('model_id', $refund->id)
            ->count());

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.id', $refund->id)
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED);

        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.executed')
            ->where('model_id', $refund->id)
            ->count());

        $this->authenticate('Gérant');

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertConflict();

        $this->assertDatabaseCount('cash_movements', 1);

        Sanctum::actingAs($executor);

        $this->postJson('/api/cash-sessions/close', ['closing_balance' => 45000])
            ->assertOk()
            ->assertJsonPath('data.refunds_total', '5000.00')
            ->assertJsonPath('data.expected_cash', '45000.00')
            ->assertJsonPath('data.difference', '0.00');
    }

    public function test_cash_execution_requires_an_open_cash_session(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'cash', approver: $approver);
        $this->uploadDocument($refund, $executor)->assertCreated();

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Aucune session de caisse n’est ouverte. Ouvrez-en une avant d’exécuter un remboursement en espèces.',
            );

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cash_execution_refuses_an_insufficient_drawer_balance(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $cashSession = $this->openCashSession($executor, 1000);
        $refund = $this->refundFor($requester, 'cash', amount: 5000, approver: $approver);
        $this->uploadDocument($refund, $executor)->assertCreated();

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Solde de caisse insuffisant : 1000.00 FCFA disponibles, 5000.00 FCFA demandés.');

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
        $this->assertSame(1000.0, $cashSession->fresh()->expectedCash());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_an_unrelated_employee_cannot_execute_a_refund(): void
    {
        $requester = $this->userWithRole('Employé');
        $refund = $this->refundFor($requester, 'wave');
        $this->authenticate('Employé');

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertForbidden();

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_an_admin_without_execution_permission_cannot_execute_a_refund(): void
    {
        $requester = $this->userWithRole('Employé');
        $refund = $this->refundFor($requester, 'wave');
        $this->authenticate('Admin');

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertForbidden();

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
    }

    public function test_electronic_execution_waits_for_reconciliation_without_moving_cash(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTING)
            ->assertJsonPath('data.executed_by_id', $executor->id)
            ->assertJsonPath('data.cash_session_id', null)
            ->assertJsonPath('data.executed_at', null)
            ->assertJsonPath('data.execution_started_at', fn (mixed $startedAt): bool => $startedAt !== null);

        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.execution_started')
            ->where('model_id', $refund->id)
            ->count());

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertConflict();

        $this->assertSame(Refund::STATUS_EXECUTING, $refund->fresh()->status);
    }

    public function test_a_check_refund_is_executed_like_an_electronic_refund(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'check', approver: $approver);

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTING);

        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertNull($refund->fresh()->executed_at);
    }

    public function test_only_an_approved_refund_can_be_executed(): void
    {
        $requester = $this->userWithRole('Employé');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', Refund::STATUS_PENDING);

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund']);

        $this->assertSame(Refund::STATUS_PENDING, $refund->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_reconciliation_confirms_an_electronic_refund_with_a_proof(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);

        $this->postJson("/api/refunds/{$refund->id}/execute")->assertOk();

        $manager = $this->authenticate('Gérant');
        $this->uploadDocument($refund, $manager)->assertCreated();

        $reference = 'BANK-'.Str::upper(Str::random(10));

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_CONFIRMED,
            'reconciliation_reference' => $reference,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED)
            ->assertJsonPath('data.reconciled_by_id', $manager->id)
            ->assertJsonPath('data.reconciliation_reference', $reference)
            ->assertJsonPath('data.executed_at', fn (mixed $executedAt): bool => $executedAt !== null)
            ->assertJsonPath('data.reconciled_at', fn (mixed $reconciledAt): bool => $reconciledAt !== null);

        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.reconciled')
            ->where('model_id', $refund->id)
            ->count());
    }

    public function test_reconciliation_confirmation_requires_a_valid_execution_proof(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);
        $this->postJson("/api/refunds/{$refund->id}/execute")->assertOk();
        $this->authenticate('Admin');

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_CONFIRMED,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund']);

        $this->assertSame(Refund::STATUS_EXECUTING, $refund->fresh()->status);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'refund.reconciled')
            ->where('model_id', $refund->id)
            ->count());
    }

    public function test_reconciliation_absent_requires_a_proof_and_a_reason(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);
        $this->postJson("/api/refunds/{$refund->id}/execute")->assertOk();

        $manager = $this->authenticate('Gérant');

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_ABSENT,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $reason = 'Le transfert n’apparaît pas sur le relevé.';

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_ABSENT,
            'reason' => $reason,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund']);

        $this->uploadDocument(
            $refund,
            $manager,
            RefundDocument::KIND_NO_TRANSFER_PROOF,
            $this->pdfContent(700),
        )->assertCreated();

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_ABSENT,
            'reason' => $reason,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_FAILED_CONFIRMED)
            ->assertJsonPath('data.failure_reason', $reason)
            ->assertJsonPath('data.reconciled_by_id', $manager->id)
            ->assertJsonPath('data.failed_at', fn (mixed $failedAt): bool => $failedAt !== null);

        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.failed_confirmed')
            ->where('model_id', $refund->id)
            ->count());
    }

    public function test_reconciliation_can_keep_the_refund_pending_then_confirm_it(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);
        $this->postJson("/api/refunds/{$refund->id}/execute")->assertOk();

        $manager = $this->authenticate('Gérant');
        $reason = 'Le relevé bancaire ne montre pas encore le transfert.';

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_PENDING,
            'reason' => $reason,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_RECONCILIATION_PENDING)
            ->assertJsonPath('data.reconciliation_reason', $reason)
            ->assertJsonPath('data.reconciliation_required_at', fn (mixed $requiredAt): bool => $requiredAt !== null);

        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'refund.reconciliation_pending')
            ->where('model_id', $refund->id)
            ->count());

        $this->uploadDocument($refund, $manager)->assertCreated();

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_CONFIRMED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED)
            ->assertJsonPath('data.reconciled_by_id', $manager->id);
    }

    public function test_the_executor_cannot_reconcile_their_own_refund(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);

        $this->postJson("/api/refunds/{$refund->id}/execute")->assertOk();
        $this->uploadDocument($refund, $executor)->assertCreated();

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_CONFIRMED,
        ])
            ->assertForbidden();

        $this->assertSame(Refund::STATUS_EXECUTING, $refund->fresh()->status);
        $this->assertNull($refund->fresh()->reconciled_by_id);
    }

    public function test_an_admin_can_reconcile_an_execution_started_by_a_manager(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);

        $this->postJson("/api/refunds/{$refund->id}/execute")->assertOk();
        $this->uploadDocument($refund, $executor)->assertCreated();

        $admin = $this->authenticate('Admin');

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_CONFIRMED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED)
            ->assertJsonPath('data.reconciled_by_id', $admin->id);
    }

    public function test_an_employee_cannot_reconcile_a_refund(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);
        $this->postJson("/api/refunds/{$refund->id}/execute")->assertOk();

        $this->authenticate('Employé');

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_PENDING,
            'reason' => 'Incertitude.',
        ])->assertForbidden();

        $this->assertSame(Refund::STATUS_EXECUTING, $refund->fresh()->status);
    }

    public function test_cash_refunds_cannot_be_reconciled(): void
    {
        $employee = $this->userWithRole('Employé');
        $refund = $this->refundFor($employee, 'cash', Refund::STATUS_EXECUTING, executor: $employee);
        $this->authenticate('Gérant');

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_PENDING,
            'reason' => 'Incertitude.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund']);

        $this->assertSame(Refund::STATUS_EXECUTING, $refund->fresh()->status);
    }

    public function test_only_an_executing_refund_can_be_reconciled(): void
    {
        $employee = $this->userWithRole('Employé');
        $refund = $this->refundFor($employee, 'wave', Refund::STATUS_APPROVED);
        $this->authenticate('Gérant');

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_PENDING,
            'reason' => 'Incertitude.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund']);

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
    }

    public function test_reconciliation_payload_is_validated(): void
    {
        $employee = $this->userWithRole('Employé');
        $refund = $this->refundFor($employee, 'wave', Refund::STATUS_EXECUTING, executor: $employee);
        $this->authenticate('Gérant');

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['decision']);

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => 'unknown',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['decision']);

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_PENDING,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_PENDING,
            'reason' => str_repeat('a', 501),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_CONFIRMED,
            'reconciliation_reference' => str_repeat('a', 256),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reconciliation_reference']);

        $this->assertSame(Refund::STATUS_EXECUTING, $refund->fresh()->status);
    }

    public function test_the_requester_cannot_approve_their_own_refund(): void
    {
        $requester = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', Refund::STATUS_PENDING);

        $this->postJson("/api/refunds/{$refund->id}/approve")
            ->assertForbidden();

        $this->assertSame(Refund::STATUS_PENDING, $refund->fresh()->status);
        $this->assertNull($refund->fresh()->approved_by_id);
    }

    public function test_the_requester_cannot_execute_their_own_refund(): void
    {
        $requester = $this->authenticate('Employé');
        $approver = $this->userWithRole('Admin');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertForbidden();

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
        $this->assertNull($refund->fresh()->executed_by_id);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_the_approver_cannot_execute_the_refund_they_approved(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->authenticate('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertForbidden();

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
        $this->assertNull($refund->fresh()->executed_by_id);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_executing_and_reconciliation_pending_refunds_reserve_the_payment_balance(): void
    {
        $requester = $this->authenticate('Employé');
        $approver = $this->userWithRole('Admin');

        foreach ([Refund::STATUS_EXECUTING, Refund::STATUS_RECONCILIATION_PENDING] as $status) {
            $existing = $this->refundFor(
                $requester,
                'wave',
                $status,
                amount: 6000,
                approver: $approver,
                paymentAmount: 10000,
            );

            $this->postJson('/api/refunds', [
                'sale_id' => $existing->sale_id,
                'payment_id' => $existing->payment_id,
                'amount' => 5000,
                'method' => 'wave',
                'transaction_reference' => 'REF-'.Str::uuid()->toString(),
                'reason' => 'Nouvelle demande au-delà du solde réservé.',
                'idempotency_key' => 'refund-'.Str::uuid()->toString(),
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['amount']);

            $this->assertSame($status, $existing->fresh()->status);
        }
    }

    public function test_failed_confirmed_refunds_release_the_payment_balance(): void
    {
        $requester = $this->authenticate('Employé');
        $approver = $this->userWithRole('Admin');
        $failed = $this->refundFor(
            $requester,
            'wave',
            Refund::STATUS_FAILED_CONFIRMED,
            amount: 6000,
            approver: $approver,
            paymentAmount: 10000,
        );

        $this->postJson('/api/refunds', [
            'sale_id' => $failed->sale_id,
            'payment_id' => $failed->payment_id,
            'amount' => 5000,
            'method' => 'wave',
            'transaction_reference' => 'REF-'.Str::uuid()->toString(),
            'reason' => 'Nouvelle demande après libération du solde.',
            'idempotency_key' => 'refund-'.Str::uuid()->toString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', Refund::STATUS_PENDING);

        $this->assertDatabaseCount('refunds', 2);
        $this->assertSame(Refund::STATUS_FAILED_CONFIRMED, $failed->fresh()->status);
    }

    public function test_execution_rechecks_concurrent_reservations_before_moving_money(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $refund = $this->refundFor(
            $requester,
            'cash',
            amount: 6000,
            approver: $approver,
            paymentAmount: 10000,
        );

        Refund::create([
            'reference' => 'REM-'.Str::upper(Str::random(12)),
            'sale_id' => $refund->sale_id,
            'payment_id' => $refund->payment_id,
            'requested_by_id' => $requester->id,
            'approved_by_id' => $approver->id,
            'executed_by_id' => $executor->id,
            'amount' => 6000,
            'method' => 'wave',
            'transaction_reference' => 'REF-'.Str::uuid()->toString(),
            'reason' => 'Réservation concurrente de test.',
            'status' => Refund::STATUS_RECONCILIATION_PENDING,
            'approved_at' => now(),
            'execution_started_at' => now(),
            'idempotency_key' => Str::uuid()->toString(),
            'idempotency_fingerprint' => hash('sha256', Str::uuid()->toString()),
        ]);

        $this->uploadDocument($refund, $executor)->assertCreated();

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(Refund::STATUS_APPROVED, $refund->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cash_expected_balance_includes_expenses_and_refunds(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $cashSession = $this->openCashSession($executor, 50000);

        $sale = Sale::factory()->create([
            'user_id' => $executor->id,
            'cash_session_id' => $cashSession->id,
            'total_amount' => 20000,
            'payment_status' => 'paid',
        ]);

        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $executor->id,
            'amount' => 20000,
            'method' => 'cash',
            'reference' => null,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        Expense::create([
            'cash_session_id' => $cashSession->id,
            'user_id' => $executor->id,
            'name' => 'Fournitures',
            'amount' => 5000,
            'date' => now()->toDateString(),
        ]);

        $refund = $this->refundFor($requester, 'cash', amount: 10000, approver: $approver);
        $this->uploadDocument($refund, $executor)->assertCreated();

        $this->postJson("/api/refunds/{$refund->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED);

        $this->assertSame(5000.0, $cashSession->fresh()->expensesPaid());
        $this->assertSame(10000.0, $cashSession->fresh()->refundsPaid());
        $this->assertSame(55000.0, $cashSession->fresh()->expectedCash());

        $this->postJson('/api/cash-sessions/close', ['closing_balance' => 55000])
            ->assertOk()
            ->assertJsonPath('data.expected_cash', '55000.00')
            ->assertJsonPath('data.difference', '0.00');
    }

    public function test_closing_a_cash_session_is_blocked_by_a_running_cash_refund(): void
    {
        $employee = $this->authenticate('Employé');
        $cashSession = $this->openCashSession($employee);
        $refund = $this->refundFor(
            $employee,
            'cash',
            Refund::STATUS_EXECUTING,
            executor: $employee,
            cashSession: $cashSession,
        );

        $this->postJson('/api/cash-sessions/close', ['closing_balance' => 50000])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Cette session de caisse contient un remboursement en cours. Terminez-le avant de clôturer la session.',
            );

        $this->assertSame('open', $cashSession->fresh()->status);
        $this->assertSame(Refund::STATUS_EXECUTING, $refund->fresh()->status);
    }

    public function test_approval_recalculates_executing_and_reconciliation_pending_reservations(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->authenticate('Gérant');

        foreach ([Refund::STATUS_EXECUTING, Refund::STATUS_RECONCILIATION_PENDING] as $status) {
            $reserved = $this->refundFor(
                $requester,
                'wave',
                $status,
                amount: 6000,
                approver: $approver,
                paymentAmount: 10000,
            );

            $candidate = $this->refundOnExistingPayment(
                (int) $reserved->sale_id,
                (int) $reserved->payment_id,
                $requester,
                5000,
                Refund::STATUS_PENDING,
            );

            $this->postJson("/api/refunds/{$candidate->id}/approve")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['amount']);

            $this->assertSame(Refund::STATUS_PENDING, $candidate->fresh()->status);
            $this->assertNull($candidate->fresh()->approved_by_id);
        }
    }

    public function test_execution_rechecks_executing_and_reconciliation_pending_reservations(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $this->authenticate('Gérant');

        foreach ([Refund::STATUS_EXECUTING, Refund::STATUS_RECONCILIATION_PENDING] as $status) {
            $reserved = $this->refundFor(
                $requester,
                'wave',
                $status,
                amount: 6000,
                approver: $approver,
                paymentAmount: 10000,
            );

            $candidate = $this->refundOnExistingPayment(
                (int) $reserved->sale_id,
                (int) $reserved->payment_id,
                $requester,
                5000,
                Refund::STATUS_APPROVED,
                $approver,
            );

            $this->postJson("/api/refunds/{$candidate->id}/execute")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['amount']);

            $this->assertSame(Refund::STATUS_APPROVED, $candidate->fresh()->status);
            $this->assertNull($candidate->fresh()->executed_by_id);
        }
    }

    public function test_failed_confirmed_refunds_do_not_block_execution(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $this->authenticate('Gérant');

        $failed = $this->refundFor(
            $requester,
            'wave',
            Refund::STATUS_FAILED_CONFIRMED,
            amount: 6000,
            approver: $approver,
            paymentAmount: 10000,
        );

        $candidate = $this->refundOnExistingPayment(
            (int) $failed->sale_id,
            (int) $failed->payment_id,
            $requester,
            5000,
            Refund::STATUS_APPROVED,
            $approver,
        );

        $this->postJson("/api/refunds/{$candidate->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTING);
    }

    public function test_cash_execution_recomputes_drawer_with_expenses_and_previous_refunds(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->authenticate('Gérant');
        $cashSession = $this->openCashSession($executor, 10000);

        Expense::create([
            'cash_session_id' => $cashSession->id,
            'user_id' => $executor->id,
            'name' => 'Fournitures',
            'amount' => 3000,
            'date' => now()->toDateString(),
        ]);

        $this->refundFor(
            $requester,
            'cash',
            Refund::STATUS_EXECUTED,
            amount: 4000,
            executor: $executor,
            cashSession: $cashSession,
            approver: $approver,
            paymentAmount: 4000,
        );

        $tooLarge = $this->refundFor($requester, 'cash', amount: 9000, approver: $approver, paymentAmount: 9000);
        $this->uploadDocument($tooLarge, $executor)->assertCreated();

        $this->postJson("/api/refunds/{$tooLarge->id}/execute")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Solde de caisse insuffisant : 3000.00 FCFA disponibles, 9000.00 FCFA demandés.',
            );

        $fitting = $this->refundFor($requester, 'cash', amount: 3000, approver: $approver, paymentAmount: 3000);
        $this->uploadDocument($fitting, $executor)->assertCreated();

        $this->postJson("/api/refunds/{$fitting->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED);

        $this->assertSame(7000.0, $cashSession->fresh()->refundsPaid());
        $this->assertSame(0.0, $cashSession->fresh()->expectedCash());
    }

    public function test_the_reconciler_can_be_the_requester(): void
    {
        $requester = $this->userWithRole('Admin');
        $approver = $this->userWithRole('Gérant');
        $executor = $this->userWithRole('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);

        Sanctum::actingAs($executor);
        $this->postJson("/api/refunds/{$refund->id}/execute")->assertOk();
        $this->uploadDocument($refund, $executor)->assertCreated();

        Sanctum::actingAs($requester);

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_CONFIRMED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED)
            ->assertJsonPath('data.reconciled_by_id', $requester->id);
    }

    public function test_the_reconciler_can_be_the_approver(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Gérant');
        $executor = $this->userWithRole('Gérant');
        $refund = $this->refundFor($requester, 'wave', approver: $approver);

        Sanctum::actingAs($executor);
        $this->postJson("/api/refunds/{$refund->id}/execute")->assertOk();
        $this->uploadDocument($refund, $executor)->assertCreated();

        Sanctum::actingAs($approver);

        $this->postJson("/api/refunds/{$refund->id}/reconcile", [
            'decision' => Refund::RECONCILIATION_CONFIRMED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Refund::STATUS_EXECUTED)
            ->assertJsonPath('data.reconciled_by_id', $approver->id);
    }
}
