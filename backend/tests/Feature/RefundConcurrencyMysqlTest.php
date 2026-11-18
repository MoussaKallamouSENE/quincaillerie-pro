<?php

namespace Tests\Feature;

use App\Exceptions\IdempotencyConflictException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundDocument;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\RefundService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Preuves de concurrence reelles sur MySQL jetable.
 *
 * Ces tests lancent plusieurs processus PHP independants : ils ne peuvent
 * donc pas etre executes sur SQLite (qui serialise les ecritures) et sont
 * ignores par defaut. Pour les activer, utiliser exclusivement la base
 * jetable autorisee :
 *
 *   REFUND_MYSQL_CONCURRENCY=1 DB_CONNECTION=mysql DB_HOST=127.0.0.1 \
 *   DB_PORT=33307 DB_DATABASE=quincaillerie_refund_concurrency_test \
 *   DB_USERNAME=refund_test DB_PASSWORD=refund_test DB_URL= DB_SOCKET= \
 *   php artisan test tests/Feature/RefundConcurrencyMysqlTest.php
 *
 * Le garde-fou s'execute avant parent::setUp() : aucune initialisation
 * Laravel, donc aucune migration, ne peut avoir lieu sur une connexion non
 * explicitement autorisee. Le nom de la base ne suffit jamais a lui seul.
 */
class RefundConcurrencyMysqlTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORIZED_MYSQL_HOST = '127.0.0.1';

    private const AUTHORIZED_MYSQL_PORT = '33307';

    private const AUTHORIZED_MYSQL_DATABASE = 'quincaillerie_refund_concurrency_test';

    /**
     * Les workers concurrents doivent voir les donnees deja validees : on
     * desactive la transaction englobante de PHPUnit.
     *
     * @var array<int, string|null>
     */
    protected $connectionsToTransact = [];

    /**
     * @var array<int, string>
     */
    private array $proofPaths = [];

    protected function setUp(): void
    {
        $configuration = self::environmentConfiguration();

        if (($configuration['REFUND_MYSQL_CONCURRENCY'] ?? null) !== '1') {
            $this->markTestSkipped('Definir REFUND_MYSQL_CONCURRENCY=1 avec une base MySQL jetable.');
        }

        // Controle fail-closed avant parent::setUp() et donc avant
        // RefreshDatabase, migrate:fresh et toute autre operation.
        self::assertAuthorizedConfiguration($configuration);

        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open est requis pour lancer des workers concurrents.');
        }

        $this->seed(RoleAndPermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->proofPaths as $path) {
            Storage::disk('local')->delete($path);
        }

        $this->proofPaths = [];

        if ($this->app !== null) {
            parent::tearDown();
        }
    }

    /**
     * Refuse toute configuration MySQL qui ne cible pas explicitement la base
     * jetable autorisee. Aucun boot Laravel ni migration ne doit avoir lieu
     * avant cet appel.
     *
     * @param  array<string, string|null>  $configuration
     *
     * @throws RuntimeException
     */
    public static function assertAuthorizedConfiguration(array $configuration): void
    {
        $expected = [
            'REFUND_MYSQL_CONCURRENCY' => '1',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => self::AUTHORIZED_MYSQL_HOST,
            'DB_PORT' => self::AUTHORIZED_MYSQL_PORT,
            'DB_DATABASE' => self::AUTHORIZED_MYSQL_DATABASE,
        ];

        foreach ($expected as $key => $value) {
            $actual = $configuration[$key] ?? null;

            if ($actual !== $value) {
                throw new RuntimeException(sprintf(
                    'Test MySQL de concurrence refuse : %s doit valoir %s (recu : %s).',
                    $key,
                    $value,
                    self::describeConfigurationValue($actual),
                ));
            }
        }

        foreach (['DB_URL', 'DB_SOCKET'] as $key) {
            $actual = $configuration[$key] ?? null;

            if ($actual !== null && $actual !== '') {
                throw new RuntimeException(sprintf(
                    'Test MySQL de concurrence refuse : %s doit rester vide (recu : %s).',
                    $key,
                    self::describeConfigurationValue($actual),
                ));
            }
        }
    }

    /**
     * @return array<string, string|null>
     */
    public static function environmentConfiguration(): array
    {
        $configuration = [];

        foreach ([
            'REFUND_MYSQL_CONCURRENCY',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_URL',
            'DB_SOCKET',
        ] as $key) {
            $value = getenv($key);

            if ($value === false) {
                $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
            }

            $configuration[$key] = is_scalar($value) ? (string) $value : null;
        }

        return $configuration;
    }

    private static function describeConfigurationValue(?string $value): string
    {
        return $value === null ? '<absent>' : '"'.$value.'"';
    }

    public function test_two_concurrent_approvals_recompute_the_real_cumulative_amount(): void
    {
        $requester = $this->userWithRole('Employé');
        $firstApprover = $this->userWithRole('Gérant');
        $secondApprover = $this->userWithRole('Gérant');
        $session = $this->openSession($requester);

        $sale = Sale::factory()->create([
            'user_id' => $requester->id,
            'cash_session_id' => $session->id,
            'total_amount' => 200000,
            'payment_status' => 'paid',
        ]);

        $payment = $this->confirmedPayment($sale, $requester, 200000, 'wave');

        $first = $this->pendingRefund($sale, $payment, $requester, 60000);
        $second = $this->pendingRefund($sale, $payment, $requester, 60000);

        $results = $this->runWorkers([
            ['action' => 'approve', 'refund_id' => $first->id, 'user_id' => $firstApprover->id],
            ['action' => 'approve', 'refund_id' => $second->id, 'user_id' => $secondApprover->id],
        ]);

        $this->assertSame(1, $this->successCount($results), $this->describe($results));

        $approved = collect([$first->fresh(), $second->fresh()])
            ->where('status', Refund::STATUS_APPROVED);
        $pending = collect([$first->fresh(), $second->fresh()])
            ->where('status', Refund::STATUS_PENDING);

        $this->assertCount(1, $approved);
        $this->assertCount(1, $pending);
        $this->assertSame(60000.0, (float) $approved->first()->cumulative_amount);
    }

    public function test_cash_execution_sees_activity_committed_while_waiting_for_the_session_lock(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->userWithRole('Gérant');
        $historian = $this->userWithRole('Gérant');
        $session = $this->openSession($executor, 10000);

        $this->executedCashRefund(
            $session,
            $historian,
            $requester,
            $approver,
            4000,
        );

        $refund = $this->approvedRefund($requester, $approver, 8000);
        $this->attachExecutionProof($refund, $executor);

        $results = $this->runWorkers([
            [
                'action' => 'execute',
                'refund_id' => $refund->id,
                'user_id' => $executor->id,
            ],
            [
                'action' => 'session_activity',
                'session_id' => $session->id,
                'hold_ms' => 1500,
                'historian_id' => $historian->id,
            ],
        ]);

        $this->assertSame(2, $this->successCount($results), $this->describe($results));
        $this->assertSame(Refund::STATUS_EXECUTED, $refund->fresh()->status, $this->describe($results));

        // 10000 (fond) + 10000 (vente concurrente) - 1000 (depense) - 4000
        // (remboursement deja execute) - 8000 (celui-ci) = 7000.
        $this->assertSame(7000.0, $session->fresh()->computeExpectedCash());
        $this->assertSame(2, CashSession::query()->findOrFail($session->id)
            ->refunds()
            ->where('method', Refund::METHOD_CASH)
            ->where('status', Refund::STATUS_EXECUTED)
            ->count());
    }

    public function test_two_concurrent_cash_executions_cannot_overdraw_the_drawer(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->userWithRole('Gérant');
        $session = $this->openSession($executor, 5000);

        $first = $this->approvedRefund($requester, $approver, 4000);
        $second = $this->approvedRefund($requester, $approver, 4000);
        $this->attachExecutionProof($first, $executor);
        $this->attachExecutionProof($second, $executor);

        $results = $this->runWorkers([
            ['action' => 'execute', 'refund_id' => $first->id, 'user_id' => $executor->id],
            ['action' => 'execute', 'refund_id' => $second->id, 'user_id' => $executor->id],
        ]);

        $this->assertSame(1, $this->successCount($results), $this->describe($results));

        $executed = collect([$first->fresh(), $second->fresh()])
            ->first(fn (Refund $refund): bool => $refund->status === Refund::STATUS_EXECUTED);
        $rolledBack = collect([$first->fresh(), $second->fresh()])
            ->first(fn (Refund $refund): bool => $refund->status !== Refund::STATUS_EXECUTED);

        $this->assertInstanceOf(Refund::class, $executed);
        $this->assertInstanceOf(Refund::class, $rolledBack);
        $this->assertSame(1, CashMovement::query()->where('refund_id', $executed->id)->count());
        $this->assertSame(0, CashMovement::query()->where('refund_id', $rolledBack->id)->count());
        $this->assertSame(Refund::STATUS_APPROVED, $rolledBack->status);
        $this->assertNull($rolledBack->executed_by_id);
        $this->assertNull($rolledBack->execution_started_at);
        $this->assertNull($rolledBack->executed_at);
        $this->assertNull($rolledBack->cash_session_id);
        $this->assertSame(1000.0, $session->fresh()->computeExpectedCash());
    }

    public function test_two_concurrent_cash_executions_on_different_sessions_do_not_deadlock_across_five_runs(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');

        for ($run = 1; $run <= 5; $run++) {
            $firstExecutor = $this->userWithRole('Gérant');
            $secondExecutor = $this->userWithRole('Gérant');
            $firstSession = $this->openSession($firstExecutor, 5000);
            $secondSession = $this->openSession($secondExecutor, 5000);

            $first = $this->approvedRefund($requester, $approver, 4000, $firstExecutor, $firstSession);
            $second = $this->approvedRefund($requester, $approver, 4000, $secondExecutor, $secondSession);
            $this->attachExecutionProof($first, $firstExecutor);
            $this->attachExecutionProof($second, $secondExecutor);

            $results = $this->runWorkers([
                ['action' => 'execute', 'refund_id' => $first->id, 'user_id' => $firstExecutor->id],
                ['action' => 'execute', 'refund_id' => $second->id, 'user_id' => $secondExecutor->id],
            ]);

            $this->assertSame(
                2,
                $this->successCount($results),
                'Run '.$run.' : '.$this->describe($results),
            );

            $first->refresh();
            $second->refresh();
            $firstSession->refresh();
            $secondSession->refresh();

            $this->assertSame(Refund::STATUS_EXECUTED, $first->status, 'Run '.$run);
            $this->assertSame(Refund::STATUS_EXECUTED, $second->status, 'Run '.$run);
            $this->assertSame($firstSession->id, $first->cash_session_id, 'Run '.$run);
            $this->assertSame($secondSession->id, $second->cash_session_id, 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('refund_id', $first->id)->count(), 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('refund_id', $second->id)->count(), 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('cash_session_id', $firstSession->id)->count(), 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('cash_session_id', $secondSession->id)->count(), 'Run '.$run);
            $this->assertSame(4000.0, $firstSession->cashReceived(), 'Run '.$run);
            $this->assertSame(4000.0, $secondSession->cashReceived(), 'Run '.$run);
            $this->assertSame(4000.0, $firstSession->refundsPaid(), 'Run '.$run);
            $this->assertSame(4000.0, $secondSession->refundsPaid(), 'Run '.$run);
            $this->assertSame(5000.0, $firstSession->computeExpectedCash(), 'Run '.$run);
            $this->assertSame(5000.0, $secondSession->computeExpectedCash(), 'Run '.$run);
            $this->assertSame(0, CashMovement::query()
                ->whereIn('cash_session_id', [$firstSession->id, $secondSession->id])
                ->whereNull('refund_id')
                ->count(), 'Run '.$run);
        }
    }

    public function test_four_concurrent_cash_executions_on_different_sessions_do_not_deadlock(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $refunds = [];
        $sessions = [];
        $workers = [];

        foreach (range(1, 4) as $index) {
            $executor = $this->userWithRole('Gérant');
            $sessions[$index] = $this->openSession($executor, 5000);
            $refunds[$index] = $this->approvedRefund(
                $requester,
                $approver,
                4000,
                $executor,
                $sessions[$index],
            );
            $this->attachExecutionProof($refunds[$index], $executor);

            $workers[] = [
                'action' => 'execute',
                'refund_id' => $refunds[$index]->id,
                'user_id' => $executor->id,
            ];
        }

        $results = $this->runWorkers($workers);

        $this->assertSame(4, $this->successCount($results), $this->describe($results));

        foreach (range(1, 4) as $index) {
            $refunds[$index]->refresh();
            $sessions[$index]->refresh();

            $this->assertSame(Refund::STATUS_EXECUTED, $refunds[$index]->status, 'Exécution '.$index);
            $this->assertSame($sessions[$index]->id, $refunds[$index]->cash_session_id, 'Exécution '.$index);
            $this->assertSame(1, CashMovement::query()->where('refund_id', $refunds[$index]->id)->count(), 'Exécution '.$index);
            $this->assertSame(1, CashMovement::query()->where('cash_session_id', $sessions[$index]->id)->count(), 'Exécution '.$index);
            $this->assertSame(4000.0, $sessions[$index]->cashReceived(), 'Exécution '.$index);
            $this->assertSame(4000.0, $sessions[$index]->refundsPaid(), 'Exécution '.$index);
            $this->assertSame(5000.0, $sessions[$index]->computeExpectedCash(), 'Exécution '.$index);
        }

        $sessionIds = collect($sessions)->pluck('id')->all();
        $refundIds = collect($refunds)->pluck('id')->all();

        $this->assertSame(0, Refund::query()
            ->whereIn('id', $refundIds)
            ->where('status', '!=', Refund::STATUS_EXECUTED)
            ->count());
        $this->assertSame(0, CashMovement::query()
            ->whereIn('cash_session_id', $sessionIds)
            ->whereNull('refund_id')
            ->count());
    }

    public function test_two_concurrent_cash_executions_on_sessions_with_existing_executed_refunds_do_not_deadlock(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $firstExecutor = $this->userWithRole('Gérant');
        $secondExecutor = $this->userWithRole('Gérant');
        $firstSession = $this->openSession($firstExecutor, 5000);
        $secondSession = $this->openSession($secondExecutor, 5000);

        $previousFirst = $this->executedCashRefund(
            $firstSession,
            $firstExecutor,
            $requester,
            $approver,
            1000,
        );
        $previousSecond = $this->executedCashRefund(
            $secondSession,
            $secondExecutor,
            $requester,
            $approver,
            1000,
        );
        $this->recordCashRefundMovement($previousFirst, $firstSession, $firstExecutor, 1000);
        $this->recordCashRefundMovement($previousSecond, $secondSession, $secondExecutor, 1000);

        $first = $this->approvedRefund($requester, $approver, 4000, $firstExecutor, $firstSession);
        $second = $this->approvedRefund($requester, $approver, 4000, $secondExecutor, $secondSession);
        $this->attachExecutionProof($first, $firstExecutor);
        $this->attachExecutionProof($second, $secondExecutor);

        $results = $this->runWorkers([
            ['action' => 'execute', 'refund_id' => $first->id, 'user_id' => $firstExecutor->id],
            ['action' => 'execute', 'refund_id' => $second->id, 'user_id' => $secondExecutor->id],
        ]);

        $this->assertSame(2, $this->successCount($results), $this->describe($results));

        $first->refresh();
        $second->refresh();
        $firstSession->refresh();
        $secondSession->refresh();

        $this->assertSame(Refund::STATUS_EXECUTED, $first->status);
        $this->assertSame(Refund::STATUS_EXECUTED, $second->status);
        $this->assertSame($firstSession->id, $first->cash_session_id);
        $this->assertSame($secondSession->id, $second->cash_session_id);
        $this->assertSame(1, CashMovement::query()->where('refund_id', $first->id)->count());
        $this->assertSame(1, CashMovement::query()->where('refund_id', $second->id)->count());
        $this->assertSame(1, CashMovement::query()->where('refund_id', $previousFirst->id)->count());
        $this->assertSame(1, CashMovement::query()->where('refund_id', $previousSecond->id)->count());
        $this->assertSame(2, CashMovement::query()->where('cash_session_id', $firstSession->id)->count());
        $this->assertSame(2, CashMovement::query()->where('cash_session_id', $secondSession->id)->count());
        $this->assertSame(0, CashMovement::query()
            ->whereIn('cash_session_id', [$firstSession->id, $secondSession->id])
            ->whereNull('refund_id')
            ->count());
        $this->assertSame(4000.0, $firstSession->cashReceived());
        $this->assertSame(4000.0, $secondSession->cashReceived());
        $this->assertSame(5000.0, $firstSession->refundsPaid());
        $this->assertSame(5000.0, $secondSession->refundsPaid());
        $this->assertSame(4000.0, $firstSession->computeExpectedCash());
        $this->assertSame(4000.0, $secondSession->computeExpectedCash());
    }

    public function test_two_concurrent_cash_executions_on_source_sales_of_the_same_drawer_do_not_deadlock(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $executor = $this->userWithRole('Gérant');
        $session = $this->openSession($executor, 5000);

        // Les ventes source appartiennent a la session de l'executant : leurs
        // paiements especes sont lus et verrouilles par le calcul du tiroir.
        $first = $this->approvedRefund($requester, $approver, 4000, $executor, $session);
        $second = $this->approvedRefund($requester, $approver, 4000, $executor, $session);
        $this->attachExecutionProof($first, $executor);
        $this->attachExecutionProof($second, $executor);

        $results = $this->runWorkers([
            ['action' => 'execute', 'refund_id' => $first->id, 'user_id' => $executor->id],
            ['action' => 'execute', 'refund_id' => $second->id, 'user_id' => $executor->id],
        ]);

        $this->assertSame(2, $this->successCount($results), $this->describe($results));

        $first->refresh();
        $second->refresh();
        $session->refresh();

        $this->assertSame(Refund::STATUS_EXECUTED, $first->status);
        $this->assertSame(Refund::STATUS_EXECUTED, $second->status);
        $this->assertSame($session->id, $first->cash_session_id);
        $this->assertSame($session->id, $second->cash_session_id);
        $this->assertSame(1, CashMovement::query()->where('refund_id', $first->id)->count());
        $this->assertSame(1, CashMovement::query()->where('refund_id', $second->id)->count());
        $sessionMovements = CashMovement::query()->where('cash_session_id', $session->id);
        $this->assertSame(2, (clone $sessionMovements)->count());
        $this->assertSame(8000.0, (float) (clone $sessionMovements)->sum('amount'));
        $this->assertSame(8000.0, $session->cashReceived());
        $this->assertSame(8000.0, $session->refundsPaid());
        // 5000 (fond) + 8000 (ventes cash source) - 8000 (deux remboursements).
        $this->assertSame(5000.0, $session->computeExpectedCash());
    }

    public function test_create_locks_payment_and_refunds_before_the_user(): void
    {
        $requester = $this->userWithRole('Employé');
        $session = $this->openSession($requester);
        $sale = Sale::factory()->create([
            'user_id' => $requester->id,
            'cash_session_id' => $session->id,
            'total_amount' => 100000,
            'payment_status' => 'paid',
        ]);
        $payment = $this->confirmedPayment($sale, $requester, 100000, 'cash');

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            app(RefundService::class)->create([
                'sale_id' => $sale->id,
                'payment_id' => $payment->id,
                'amount' => 50000,
                'method' => Refund::METHOD_CASH,
                'transaction_reference' => null,
                'reason' => 'Verification de l ordre des verrous.',
                'idempotency_key' => (string) Str::uuid(),
            ], $requester);
        } finally {
            $queries = DB::connection()->getQueryLog();
            DB::connection()->flushQueryLog();
        }

        $saleLock = $this->firstLockedQueryIndex($queries, 'sales');
        $paymentLock = $this->firstLockedQueryIndex($queries, 'payments');
        $firstRefundLock = $this->firstLockedQueryIndex($queries, 'refunds');
        $lastRefundLock = $this->lastLockedQueryIndex($queries, 'refunds');
        $refundInsert = $this->firstRefundInsertIndex($queries);
        $userLock = $this->firstLockedQueryIndex($queries, 'users');

        $this->assertNotNull($saleLock);
        $this->assertNotNull($paymentLock);
        $this->assertNotNull($firstRefundLock);
        $this->assertNotNull($lastRefundLock);
        $this->assertNotNull($refundInsert);
        $this->assertNotNull($userLock);
        $this->assertLessThan($paymentLock, $saleLock);
        $this->assertLessThan($firstRefundLock, $paymentLock);
        $this->assertLessThan($userLock, $firstRefundLock);
        $this->assertLessThan($userLock, $lastRefundLock);
        $this->assertLessThan($userLock, $refundInsert);
    }

    public function test_two_concurrent_creates_with_same_idempotency_key_and_payload_return_same_refund(): void
    {
        $requester = $this->userWithRole('Employé');
        $session = $this->openSession($requester);

        $sale = Sale::factory()->create([
            'user_id' => $requester->id,
            'cash_session_id' => $session->id,
            'total_amount' => 100000,
            'payment_status' => 'paid',
        ]);

        $payment = $this->confirmedPayment($sale, $requester, 100000, 'wave');
        $idempotencyKey = (string) Str::uuid();

        $data = [
            'sale_id' => $sale->id,
            'payment_id' => $payment->id,
            'amount' => 3000,
            'method' => Refund::METHOD_CASH,
            'transaction_reference' => null,
            'reason' => 'Test de concurrence idempotence identique.',
            'idempotency_key' => $idempotencyKey,
        ];

        $results = $this->runWorkers([
            ['action' => 'create', 'user_id' => $requester->id, 'data' => $data],
            ['action' => 'create', 'user_id' => $requester->id, 'data' => $data],
        ]);

        $this->assertSame(2, $this->successCount($results), $this->describe($results));

        $firstRefundId = $results[0]['refund_id'] ?? null;
        $secondRefundId = $results[1]['refund_id'] ?? null;

        $this->assertNotNull($firstRefundId, $this->describe($results));
        $this->assertNotNull($secondRefundId, $this->describe($results));
        $this->assertSame($firstRefundId, $secondRefundId);

        $this->assertSame(1, Refund::query()
            ->where('payment_id', $payment->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count());

        $this->assertDatabaseHas('refunds', [
            'id' => $firstRefundId,
            'payment_id' => $payment->id,
            'idempotency_key' => $idempotencyKey,
            'amount' => 3000,
        ]);
    }

    public function test_two_concurrent_creates_with_same_idempotency_key_and_different_payload_conflict(): void
    {
        $requester = $this->userWithRole('Employé');
        $session = $this->openSession($requester);

        $sale = Sale::factory()->create([
            'user_id' => $requester->id,
            'cash_session_id' => $session->id,
            'total_amount' => 100000,
            'payment_status' => 'paid',
        ]);

        $payment = $this->confirmedPayment($sale, $requester, 100000, 'wave');
        $idempotencyKey = (string) Str::uuid();

        $baseData = [
            'sale_id' => $sale->id,
            'payment_id' => $payment->id,
            'method' => Refund::METHOD_CASH,
            'transaction_reference' => null,
            'reason' => 'Test de concurrence idempotence differente.',
            'idempotency_key' => $idempotencyKey,
        ];

        $firstData = $baseData;
        $firstData['amount'] = 3000;

        $secondData = $baseData;
        $secondData['amount'] = 4000;

        $results = $this->runWorkers([
            ['action' => 'create', 'user_id' => $requester->id, 'data' => $firstData],
            ['action' => 'create', 'user_id' => $requester->id, 'data' => $secondData],
        ]);

        $this->assertSame(1, $this->successCount($results), $this->describe($results));

        $errors = collect($results)->where('status', 'error');

        $this->assertCount(1, $errors, $this->describe($results));
        $this->assertSame(
            IdempotencyConflictException::class,
            $errors->first()['class'] ?? null,
            $this->describe($results),
        );

        $this->assertSame(1, Refund::query()
            ->where('payment_id', $payment->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count());

        $refund = Refund::query()
            ->where('payment_id', $payment->id)
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();

        $this->assertContains((float) $refund->amount, [3000.0, 4000.0]);
    }

    public function test_concurrent_create_and_approval_do_not_deadlock(): void
    {
        $actor = $this->userWithRole('Gérant');
        $targetRequester = $this->userWithRole('Employé');

        $session = $this->openSession($actor);
        $sale = Sale::factory()->create([
            'user_id' => $actor->id,
            'cash_session_id' => $session->id,
            'total_amount' => 100000,
            'payment_status' => 'paid',
        ]);
        $payment = $this->confirmedPayment($sale, $actor, 100000, 'cash');

        $targetSession = $this->openSession($targetRequester);
        $targetSale = Sale::factory()->create([
            'user_id' => $targetRequester->id,
            'cash_session_id' => $targetSession->id,
            'total_amount' => 100000,
            'payment_status' => 'paid',
        ]);
        $targetPayment = $this->confirmedPayment($targetSale, $targetRequester, 100000, 'wave');
        $targetRefund = $this->pendingRefund($targetSale, $targetPayment, $targetRequester, 50000);

        $results = $this->runWorkers([
            [
                'action' => 'create',
                'user_id' => $actor->id,
                'data' => [
                    'sale_id' => $sale->id,
                    'payment_id' => $payment->id,
                    'amount' => 50000,
                    'method' => Refund::METHOD_CASH,
                    'transaction_reference' => null,
                    'reason' => 'Creation concurrente avec approbation.',
                    'idempotency_key' => (string) Str::uuid(),
                ],
            ],
            [
                'action' => 'approve',
                'refund_id' => $targetRefund->id,
                'user_id' => $actor->id,
            ],
        ]);

        $this->assertSame(2, $this->successCount($results), $this->describe($results));
        $this->assertSame(Refund::STATUS_APPROVED, $targetRefund->fresh()->status);
        $this->assertDatabaseHas('refunds', [
            'payment_id' => $payment->id,
            'requested_by_id' => $actor->id,
            'amount' => 50000,
            'status' => Refund::STATUS_PENDING,
        ]);
    }

    public function test_same_user_create_and_cash_execution_do_not_deadlock(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');

        for ($run = 1; $run <= 5; $run++) {
            $actor = $this->userWithRole('Gérant');
            $session = $this->openSession($actor, 5000);

            $createSale = Sale::factory()->create([
                'user_id' => $actor->id,
                'cash_session_id' => $session->id,
                'total_amount' => 3000,
                'payment_status' => 'paid',
            ]);
            $createPayment = $this->confirmedPayment($createSale, $actor, 3000, 'cash');

            $execution = $this->approvedRefund($requester, $approver, 4000, $actor, $session);
            $this->attachExecutionProof($execution, $actor);

            $results = $this->runWorkers([
                [
                    'action' => 'create_after_payment_lock',
                    'user_id' => $actor->id,
                    'lock_payment_id' => $createPayment->id,
                    'hold_ms' => 500,
                    'data' => [
                        'sale_id' => $createSale->id,
                        'payment_id' => $createPayment->id,
                        'amount' => 1000,
                        'method' => Refund::METHOD_CASH,
                        'transaction_reference' => null,
                        'reason' => 'Creation concurrente avec execution.',
                        'idempotency_key' => (string) Str::uuid(),
                    ],
                ],
                [
                    'action' => 'execute',
                    'refund_id' => $execution->id,
                    'user_id' => $actor->id,
                ],
            ]);

            $this->assertSame(2, $this->successCount($results), 'Run '.$run.' : '.$this->describe($results));

            $execution->refresh();
            $session->refresh();

            $created = Refund::query()
                ->where('payment_id', $createPayment->id)
                ->firstOrFail();

            $this->assertSame(Refund::STATUS_EXECUTED, $execution->status, 'Run '.$run);
            $this->assertSame($session->id, $execution->cash_session_id, 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('refund_id', $execution->id)->count(), 'Run '.$run);
            $this->assertSame(0, CashMovement::query()->where('refund_id', $created->id)->count(), 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('cash_session_id', $session->id)->count(), 'Run '.$run);
            $this->assertSame(Refund::STATUS_PENDING, $created->status, 'Run '.$run);
            $this->assertNull($created->cash_session_id, 'Run '.$run);
            $this->assertSame(7000.0, $session->cashReceived(), 'Run '.$run);
            $this->assertSame(4000.0, $session->refundsPaid(), 'Run '.$run);
            $this->assertSame(8000.0, $session->computeExpectedCash(), 'Run '.$run);
        }
    }

    public function test_crossed_source_cash_executions_do_not_deadlock(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');

        for ($run = 1; $run <= 5; $run++) {
            $executorA = $this->userWithRole('Gérant');
            $executorB = $this->userWithRole('Gérant');
            $sessionA = $this->openSession($executorA, 5000);
            $sessionB = $this->openSession($executorB, 5000);

            // Chaque paiement source appartient au tiroir de l'autre executant.
            $refundA = $this->approvedRefund($requester, $approver, 4000, $executorA, $sessionA);
            $refundB = $this->approvedRefund($requester, $approver, 4000, $executorB, $sessionB);
            $this->attachExecutionProof($refundA, $executorB);
            $this->attachExecutionProof($refundB, $executorA);

            $results = $this->runWorkers([
                ['action' => 'execute', 'refund_id' => $refundA->id, 'user_id' => $executorB->id],
                ['action' => 'execute', 'refund_id' => $refundB->id, 'user_id' => $executorA->id],
            ]);

            $this->assertSame(2, $this->successCount($results), 'Run '.$run.' : '.$this->describe($results));

            $refundA->refresh();
            $refundB->refresh();
            $sessionA->refresh();
            $sessionB->refresh();

            $this->assertSame(Refund::STATUS_EXECUTED, $refundA->status, 'Run '.$run);
            $this->assertSame(Refund::STATUS_EXECUTED, $refundB->status, 'Run '.$run);
            $this->assertSame($sessionB->id, $refundA->cash_session_id, 'Run '.$run);
            $this->assertSame($sessionA->id, $refundB->cash_session_id, 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('refund_id', $refundA->id)->count(), 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('refund_id', $refundB->id)->count(), 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('cash_session_id', $sessionA->id)->count(), 'Run '.$run);
            $this->assertSame(1, CashMovement::query()->where('cash_session_id', $sessionB->id)->count(), 'Run '.$run);
            $this->assertSame(4000.0, $sessionA->cashReceived(), 'Run '.$run);
            $this->assertSame(4000.0, $sessionB->cashReceived(), 'Run '.$run);
            $this->assertSame(4000.0, $sessionA->refundsPaid(), 'Run '.$run);
            $this->assertSame(4000.0, $sessionB->refundsPaid(), 'Run '.$run);
            $this->assertSame(5000.0, $sessionA->computeExpectedCash(), 'Run '.$run);
            $this->assertSame(5000.0, $sessionB->computeExpectedCash(), 'Run '.$run);
        }
    }

    public function test_crossed_add_payments_do_not_deadlock_and_follow_the_actor_session(): void
    {
        for ($run = 1; $run <= 5; $run++) {
            $firstManager = $this->userWithRole('Gérant');
            $secondManager = $this->userWithRole('Gérant');
            $firstSession = $this->openSession($firstManager, 0);
            $secondSession = $this->openSession($secondManager, 0);

            $firstSale = Sale::factory()->create([
                'user_id' => $firstManager->id,
                'cash_session_id' => $firstSession->id,
                'total_amount' => 2000,
                'payment_status' => 'credit',
            ]);
            $secondSale = Sale::factory()->create([
                'user_id' => $secondManager->id,
                'cash_session_id' => $secondSession->id,
                'total_amount' => 2000,
                'payment_status' => 'credit',
            ]);

            $results = $this->runWorkers([
                [
                    'action' => 'add_payment',
                    'sale_id' => $firstSale->id,
                    'user_id' => $secondManager->id,
                    'data' => ['amount' => 1000, 'method' => 'cash'],
                ],
                [
                    'action' => 'add_payment',
                    'sale_id' => $secondSale->id,
                    'user_id' => $firstManager->id,
                    'data' => ['amount' => 1000, 'method' => 'cash'],
                ],
            ]);

            $this->assertSame(2, $this->successCount($results), 'Run '.$run.' : '.$this->describe($results));

            $firstPayment = Payment::query()->where('sale_id', $firstSale->id)->firstOrFail();
            $secondPayment = Payment::query()->where('sale_id', $secondSale->id)->firstOrFail();

            $this->assertSame($secondSession->id, $firstPayment->cash_session_id, 'Run '.$run);
            $this->assertSame($secondManager->id, $firstPayment->user_id, 'Run '.$run);
            $this->assertSame($firstSession->id, $secondPayment->cash_session_id, 'Run '.$run);
            $this->assertSame($firstManager->id, $secondPayment->user_id, 'Run '.$run);
            $this->assertSame($firstSession->id, $firstSale->fresh()->cash_session_id, 'Run '.$run);
            $this->assertSame($secondSession->id, $secondSale->fresh()->cash_session_id, 'Run '.$run);
            $this->assertSame(1000.0, $firstSession->fresh()->cashReceived(), 'Run '.$run);
            $this->assertSame(1000.0, $secondSession->fresh()->cashReceived(), 'Run '.$run);
        }
    }

    public function test_mysql_triggers_reject_new_null_cash_session_attributions(): void
    {
        $user = $this->userWithRole('Gérant');
        $session = $this->openSession($user);
        $sale = Sale::factory()->create([
            'user_id' => $user->id,
            'cash_session_id' => $session->id,
            'total_amount' => 1000,
            'payment_status' => 'credit',
        ]);

        $insertBlocked = false;

        try {
            DB::table('payments')->insert([
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'cash_session_id' => null,
                'amount' => 1000,
                'method' => 'cash',
                'reference' => null,
                'status' => Payment::STATUS_CONFIRMED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $insertBlocked = true;
        }

        $this->assertTrue($insertBlocked);

        DB::table('payments')->insert([
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'cash_session_id' => null,
            'amount' => 1000,
            'method' => 'wave',
            'reference' => 'WAVE-'.Str::uuid(),
            'status' => Payment::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payment = $this->confirmedPayment($sale, $user, 1000, 'cash');
        $clearBlocked = false;

        try {
            DB::table('payments')
                ->where('id', $payment->id)
                ->update(['cash_session_id' => null]);
        } catch (QueryException) {
            $clearBlocked = true;
        }

        $this->assertTrue($clearBlocked);
        $this->assertSame($session->id, $payment->fresh()->cash_session_id);
    }

    public function test_close_locks_payments_refunds_and_expenses_in_order(): void
    {
        $requester = $this->userWithRole('Employé');
        $approver = $this->userWithRole('Admin');
        $manager = $this->userWithRole('Gérant');
        $session = $this->openSession($manager, 5000);

        $sale = Sale::factory()->create([
            'user_id' => $manager->id,
            'cash_session_id' => $session->id,
            'total_amount' => 5000,
            'payment_status' => 'paid',
        ]);
        $this->confirmedPayment($sale, $manager, 5000, 'cash');
        $this->executedCashRefund($session, $manager, $requester, $approver, 1000);

        DB::table('expenses')->insert([
            'cash_session_id' => $session->id,
            'user_id' => $manager->id,
            'name' => 'Depense de cloture',
            'amount' => 500,
            'date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            app(CashSessionService::class)->closeFor($manager, 8500);
        } finally {
            $queries = DB::connection()->getQueryLog();
            DB::connection()->flushQueryLog();
        }

        $sessionLock = $this->firstLockedQueryIndex($queries, 'cash_sessions');
        $paymentLock = $this->firstLockedQueryIndex($queries, 'payments');
        $refundLock = $this->firstLockedQueryIndex($queries, 'refunds');
        $expenseLock = $this->firstLockedQueryIndex($queries, 'expenses');

        $this->assertNotNull($sessionLock);
        $this->assertNotNull($paymentLock);
        $this->assertNotNull($refundLock);
        $this->assertNotNull($expenseLock);
        $this->assertLessThan($paymentLock, $sessionLock);
        $this->assertLessThan($refundLock, $paymentLock);
        $this->assertLessThan($expenseLock, $refundLock);
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame(8500.0, (float) $session->fresh()->expected_cash);
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

    private function openSession(User $user, float $openingBalance = 50000): CashSession
    {
        return CashSession::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => $openingBalance,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function confirmedPayment(Sale $sale, User $user, float $amount, string $method): Payment
    {
        return Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $user->id,
            'cash_session_id' => $method === 'cash' ? $sale->cash_session_id : null,
            'amount' => $amount,
            'method' => $method,
            'reference' => $method === 'cash' ? null : 'PAY-'.Str::uuid()->toString(),
            'status' => Payment::STATUS_CONFIRMED,
        ]);
    }

    private function pendingRefund(Sale $sale, Payment $payment, User $requester, float $amount): Refund
    {
        return Refund::create([
            'reference' => 'REM-'.Str::upper(Str::random(12)),
            'sale_id' => $sale->id,
            'payment_id' => $payment->id,
            'requested_by_id' => $requester->id,
            'amount' => $amount,
            'method' => 'wave',
            'transaction_reference' => 'REF-'.Str::uuid()->toString(),
            'reason' => 'Concurrence MySQL de test.',
            'status' => Refund::STATUS_PENDING,
            'idempotency_key' => Str::uuid()->toString(),
            'idempotency_fingerprint' => hash('sha256', Str::uuid()->toString()),
        ]);
    }

    private function approvedRefund(
        User $requester,
        User $approver,
        float $amount,
        ?User $sourceSeller = null,
        ?CashSession $sourceSession = null,
    ): Refund {
        $sourceSeller ??= $requester;
        $sourceSession ??= $this->openSession($sourceSeller);
        $sale = Sale::factory()->create([
            'user_id' => $sourceSeller->id,
            'cash_session_id' => $sourceSession->id,
            'total_amount' => $amount,
            'payment_status' => 'paid',
        ]);
        $payment = $this->confirmedPayment($sale, $sourceSeller, $amount, 'cash');

        return Refund::create([
            'reference' => 'REM-'.Str::upper(Str::random(12)),
            'sale_id' => $sale->id,
            'payment_id' => $payment->id,
            'requested_by_id' => $requester->id,
            'approved_by_id' => $approver->id,
            'approved_at' => now(),
            'amount' => $amount,
            'method' => Refund::METHOD_CASH,
            'reason' => 'Remboursement concurrent de test.',
            'status' => Refund::STATUS_APPROVED,
            'idempotency_key' => Str::uuid()->toString(),
            'idempotency_fingerprint' => hash('sha256', Str::uuid()->toString()),
        ]);
    }

    private function executedCashRefund(
        CashSession $session,
        User $executor,
        User $requester,
        User $approver,
        float $amount,
    ): Refund {
        $sourceSession = $this->openSession($requester);
        $sale = Sale::factory()->create([
            'user_id' => $requester->id,
            'cash_session_id' => $sourceSession->id,
            'total_amount' => $amount,
            'payment_status' => 'paid',
        ]);
        $payment = $this->confirmedPayment($sale, $requester, $amount, 'wave');

        return Refund::create([
            'reference' => 'REM-'.Str::upper(Str::random(12)),
            'sale_id' => $sale->id,
            'payment_id' => $payment->id,
            'requested_by_id' => $requester->id,
            'approved_by_id' => $approver->id,
            'executed_by_id' => $executor->id,
            'cash_session_id' => $session->id,
            'amount' => $amount,
            'method' => Refund::METHOD_CASH,
            'reason' => 'Remboursement deja execute de test.',
            'status' => Refund::STATUS_EXECUTED,
            'approved_at' => now(),
            'execution_started_at' => now(),
            'executed_at' => now(),
            'idempotency_key' => Str::uuid()->toString(),
            'idempotency_fingerprint' => hash('sha256', Str::uuid()->toString()),
        ]);
    }

    private function attachExecutionProof(Refund $refund, User $uploader): void
    {
        $content = "%PDF-1.4\n".str_repeat(' ', 512)."\n%%EOF\n";
        $path = 'refunds/'.$refund->id.'/'.Str::uuid()->toString().'.pdf';

        Storage::disk('local')->put($path, $content);
        $this->proofPaths[] = $path;

        RefundDocument::create([
            'refund_id' => $refund->id,
            'uploaded_by_id' => $uploader->id,
            'kind' => RefundDocument::KIND_EXECUTION_PROOF,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'preuve.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen($content),
            'checksum' => hash('sha256', $content),
        ]);
    }

    private function recordCashRefundMovement(
        Refund $refund,
        CashSession $session,
        User $executor,
        float $amount,
    ): void {
        CashMovement::create([
            'cash_session_id' => $session->id,
            'user_id' => $executor->id,
            'refund_id' => $refund->id,
            'type' => 'out',
            'amount' => $amount,
            'reason' => 'Mouvement de remboursement existant.',
        ]);
    }

    /**
     * Lance des workers PHP independants, les synchronise puis collecte leurs resultats.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array<string, mixed>>
     */
    private function runWorkers(array $payloads): array
    {
        $directory = sys_get_temp_dir().'/refund-mysql-race-'.Str::uuid();
        mkdir($directory, 0700, true);

        $script = $this->workerScript();
        $scriptPath = $directory.'/worker.php';
        file_put_contents($scriptPath, $script);

        $processes = [];

        foreach ($payloads as $index => $payload) {
            $command = [
                PHP_BINARY,
                $scriptPath,
                $directory,
                (string) $index,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ];

            $pipes = [];
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );

            if (! is_resource($process)) {
                $this->fail('Impossible de lancer un worker de concurrence.');
            }

            $processes[$index] = [$process, $pipes];
        }

        $this->waitForWorkers($directory, count($payloads));

        touch($directory.'/go');

        $results = [];

        foreach ($processes as $index => [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $raw = is_file($directory.'/result-'.$index)
                ? (string) file_get_contents($directory.'/result-'.$index)
                : '';

            $result = json_decode($raw, true);

            $results[$index] = is_array($result)
                ? $result
                : [
                    'status' => 'missing',
                    'exit_code' => $exitCode,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ];
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);

        return $results;
    }

    private function waitForWorkers(string $directory, int $count): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $ready = 0;

            for ($index = 0; $index < $count; $index++) {
                if (is_file($directory.'/ready-'.$index)) {
                    $ready++;
                }
            }

            if ($ready === $count) {
                return;
            }

            usleep(10000);
        }

        $this->fail('Les workers de concurrence ne se sont pas initialises a temps.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function successCount(array $results): int
    {
        return collect($results)->where('status', 'ok')->count();
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     */
    private function firstLockedQueryIndex(array $queries, string $table): ?int
    {
        foreach ($queries as $index => $query) {
            if ($this->isLockedQueryFor($query, $table)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     */
    private function lastLockedQueryIndex(array $queries, string $table): ?int
    {
        $last = null;

        foreach ($queries as $index => $query) {
            if ($this->isLockedQueryFor($query, $table)) {
                $last = $index;
            }
        }

        return $last;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function isLockedQueryFor(array $query, string $table): bool
    {
        $sql = strtolower((string) ($query['query'] ?? ''));

        return str_contains($sql, 'from `'.$table.'`')
            && str_contains($sql, 'for update');
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     */
    private function firstRefundInsertIndex(array $queries): ?int
    {
        foreach ($queries as $index => $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));

            if (str_starts_with($sql, 'insert into `refunds`')) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function describe(array $results): string
    {
        return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: 'resultats illisibles';
    }

    private function workerScript(): string
    {
        return str_replace(
            ['__AUTOLOAD__', '__BOOTSTRAP__'],
            [base_path('vendor/autoload.php'), base_path('bootstrap/app.php')],
            <<<'PHP'
<?php

require '__AUTOLOAD__';

$app = require '__BOOTSTRAP__';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$directory, $index] = [$argv[1], $argv[2]];
$payload = json_decode(base64_decode($argv[3], true), true, 512, JSON_THROW_ON_ERROR);

$readyFile = $directory.'/ready-'.$index;
$resultFile = $directory.'/result-'.$index;
$action = $payload['action'] ?? '';
$refundId = null;

try {
    if ($action === 'session_activity') {
        \Illuminate\Support\Facades\DB::beginTransaction();
        \Illuminate\Support\Facades\DB::table('cash_sessions')
            ->where('id', (int) $payload['session_id'])
            ->lockForUpdate()
            ->first();
    }

    if ($action === 'create_after_payment_lock') {
        \Illuminate\Support\Facades\DB::beginTransaction();
        \Illuminate\Support\Facades\DB::table('payments')
            ->where('id', (int) $payload['lock_payment_id'])
            ->lockForUpdate()
            ->first();
    }

    file_put_contents($readyFile, '1');

    $deadline = microtime(true) + 30;

    while (! is_file($directory.'/go') && microtime(true) < $deadline) {
        usleep(5000);
    }

    if (($payload['hold_ms'] ?? 0) > 0) {
        usleep((int) $payload['hold_ms'] * 1000);
    }

    if ($action === 'approve' || $action === 'execute') {
        $service = $app->make(\App\Services\RefundService::class);
        $refund = \App\Models\Refund::query()->findOrFail((int) $payload['refund_id']);
        $user = \App\Models\User::query()->findOrFail((int) $payload['user_id']);

        $action === 'approve'
            ? $service->approve($refund, $user)
            : $service->execute($refund, $user);
    } elseif ($action === 'create') {
        $service = $app->make(\App\Services\RefundService::class);
        $user = \App\Models\User::query()->findOrFail((int) $payload['user_id']);

        $refund = $service->create($payload['data'], $user);
        $refundId = (int) $refund->id;
    } elseif ($action === 'create_after_payment_lock') {
        $service = $app->make(\App\Services\RefundService::class);
        $user = \App\Models\User::query()->findOrFail((int) $payload['user_id']);

        $refund = $service->create($payload['data'], $user);
        $refundId = (int) $refund->id;
        \Illuminate\Support\Facades\DB::commit();
    } elseif ($action === 'add_payment') {
        $service = $app->make(\App\Services\SaleService::class);
        $sale = \App\Models\Sale::query()->findOrFail((int) $payload['sale_id']);
        $user = \App\Models\User::query()->findOrFail((int) $payload['user_id']);

        $service->addPayment($sale, $payload['data'], $user);
    } elseif ($action === 'session_activity') {
        \Illuminate\Support\Facades\DB::table('expenses')->insert([
            'cash_session_id' => (int) $payload['session_id'],
            'user_id' => (int) $payload['historian_id'],
            'name' => 'Depense concurrente',
            'amount' => 1000,
            'date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sale = \App\Models\Sale::factory()->create([
            'user_id' => (int) $payload['historian_id'],
            'cash_session_id' => (int) $payload['session_id'],
            'total_amount' => 10000,
            'payment_status' => 'paid',
        ]);

        \App\Models\Payment::create([
            'sale_id' => $sale->id,
            'user_id' => (int) $payload['historian_id'],
            'cash_session_id' => (int) $payload['session_id'],
            'amount' => 10000,
            'method' => 'cash',
            'reference' => null,
            'status' => \App\Models\Payment::STATUS_CONFIRMED,
        ]);

        \Illuminate\Support\Facades\DB::commit();
    } else {
        throw new \RuntimeException('Action de worker inconnue : '.$action);
    }

    $result = ['status' => 'ok'];
    if ($refundId !== null) {
        $result['refund_id'] = $refundId;
    }
    file_put_contents($resultFile, json_encode($result));
    exit(0);
} catch (\Throwable $exception) {
    if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
        \Illuminate\Support\Facades\DB::rollBack();
    }

    file_put_contents($resultFile, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'errors' => method_exists($exception, 'errors') ? $exception->errors() : null,
    ]));

    exit(1);
}
PHP,
        );
    }
}
