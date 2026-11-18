<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientCashException;
use App\Exceptions\NoOpenCashSessionException;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestion du cycle de vie des remboursements.
 *
 * Le service porte la demande, l'approbation, l'execution et la
 * reconciliation. Il ne modifie jamais la vente ni le paiement d'origine ;
 * l'execution en especes reste la seule operation qui cree un mouvement de
 * caisse.
 */
class RefundService
{
    public function __construct(
        private readonly RefundAccessService $accessService,
        private readonly RefundDocumentService $documentService,
    ) {}

    /**
     * Cree une demande de remboursement sur un paiement confirme.
     *
     * @param  array<string, mixed>  $data  Donnees validees de la demande.
     *
     * @throws ValidationException
     */
    public function create(array $data, User $requester): Refund
    {
        $saleId = (int) ($data['sale_id'] ?? 0);
        $paymentId = (int) ($data['payment_id'] ?? 0);
        $method = trim((string) ($data['method'] ?? ''));
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $reference = $this->normalizeReference($data['transaction_reference'] ?? null);
        $reason = $this->normalizeReason($data['reason'] ?? null);
        $idempotencyKey = $this->normalizeIdempotencyKey($data['idempotency_key'] ?? null);

        $this->assertCreatePayload($saleId, $paymentId, $method, $amount, $reference, $reason);

        $fingerprint = $this->fingerprint([
            'sale_id' => $saleId,
            'payment_id' => $paymentId,
            'requested_by_id' => (int) $requester->id,
            'amount' => $this->money($amount),
            'method' => mb_strtolower($method),
            'transaction_reference_hash' => $this->referenceHash($reference),
            'reason' => $reason,
        ]);

        try {
            return DB::transaction(function () use ($saleId, $paymentId, $method, $amount, $reference, $reason, $idempotencyKey, $fingerprint, $requester): Refund {
                $lockedSale = Sale::query()
                    ->lockForUpdate()
                    ->findOrFail($saleId);

                $lockedPayment = Payment::query()
                    ->lockForUpdate()
                    ->findOrFail($paymentId);

                $existing = $this->findByIdempotencyKey($paymentId, $idempotencyKey, lock: true);

                if ($existing instanceof Refund) {
                    $lockedRequester = $this->lockAuthorizedUser(
                        (int) $requester->id,
                        'demander_remboursement',
                    );

                    return $this->resolveExisting($existing, $fingerprint);
                }

                $this->assertPaymentBelongsToSale($lockedSale, $lockedPayment);
                $this->assertPaymentIsConfirmed($lockedPayment);

                // Ordre uniforme : vente -> paiement -> remboursements -> utilisateur.
                // Les reservations sont lues et verrouillees avant le compte demandeur.
                $this->assertAmountWithinRefundableBalance($lockedPayment, $amount);

                $projectedAmount = round(
                    $this->thresholdAmount($lockedPayment) + $amount,
                    2,
                );

                if ($reference !== null) {
                    $this->assertReferenceAvailable($method, $reference);
                }

                // La ligne de remboursement est creee avant le verrou
                // utilisateur : ainsi aucun verrou refunds n'est acquis apres
                // le verrou users. Le statut definitif est applique juste
                // apres l'autorisation, dans la meme transaction.
                $refund = Refund::create([
                    'reference' => 'TMP-'.Str::uuid()->toString(),
                    'sale_id' => $lockedSale->id,
                    'payment_id' => $lockedPayment->id,
                    'requested_by_id' => $requester->id,
                    'amount' => $amount,
                    'method' => $method,
                    'transaction_reference' => $reference,
                    'reason' => $reason,
                    'status' => Refund::STATUS_PENDING,
                    'blocked_reason' => null,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $fingerprint,
                ]);

                $lockedRequester = $this->lockAuthorizedUser(
                    (int) $requester->id,
                    'demander_remboursement',
                );

                [$status, $blockedReason] = $this->resolveInitialStatus($lockedRequester, $projectedAmount);

                $refund->forceFill([
                    'reference' => 'REM-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT),
                    'status' => $status,
                    'blocked_reason' => $blockedReason,
                ])->save();

                $this->recordAudit(
                    $refund,
                    $lockedRequester,
                    'refund.requested',
                    null,
                    [
                        'status' => $refund->status,
                        'amount' => $this->money((float) $refund->amount),
                        'method' => $refund->method,
                        'reference' => $refund->reference,
                    ],
                );

                if ($blockedReason !== null) {
                    $this->recordAudit(
                        $refund,
                        $lockedRequester,
                        'refund.blocked',
                        null,
                        [
                            'status' => Refund::STATUS_BLOCKED,
                            'blocked_reason' => $blockedReason,
                        ],
                    );
                }

                return $this->loadRefund($refund);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByIdempotencyKey($paymentId, $idempotencyKey);

            if ($existing instanceof Refund) {
                return $this->resolveExisting($existing, $fingerprint);
            }

            if ($reference !== null && $this->referenceExists($method, $reference)) {
                throw ValidationException::withMessages([
                    'transaction_reference' => 'Cette référence de transaction est déjà utilisée.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Approuve une demande en attente ou bloquee.
     *
     * @throws ValidationException
     */
    public function approve(Refund $refund, User $approver): Refund
    {
        return DB::transaction(function () use ($refund, $approver): Refund {
            [$lockedRefund, $lockedSale, $lockedPayment] = $this->lockRefundContext($refund);

            $this->assertPaymentBelongsToSale($lockedSale, $lockedPayment);
            $this->assertPaymentIsConfirmed($lockedPayment);

            if (! in_array($lockedRefund->status, [Refund::STATUS_PENDING, Refund::STATUS_BLOCKED], true)) {
                throw ValidationException::withMessages([
                    'refund' => 'Cette demande ne peut plus être approuvée.',
                ]);
            }

            $amount = round((float) $lockedRefund->amount, 2);

            // Ordre uniforme : vente -> paiement -> remboursements -> utilisateur.
            $this->assertAmountWithinRefundableBalance(
                $lockedPayment,
                $amount,
                (int) $lockedRefund->id,
            );

            $cumulativeAmount = round(
                $this->thresholdAmount($lockedPayment, (int) $lockedRefund->id) + $amount,
                2,
            );

            $lockedApprover = $this->lockAuthorizedUser((int) $approver->id, 'approuver_remboursement');

            $this->assertNotRequester($lockedRefund, $lockedApprover);

            $isAdmin = $lockedApprover->hasRole('Admin');

            if ($lockedRefund->status === Refund::STATUS_BLOCKED && ! $isAdmin) {
                abort(403, 'Seul un autre administrateur peut approuver une demande bloquée.');
            }

            if (! $isAdmin && $cumulativeAmount > Refund::MANAGER_APPROVAL_THRESHOLD) {
                throw ValidationException::withMessages([
                    'refund' => sprintf(
                        'Un gérant ne peut pas approuver ce remboursement : le cumul atteindrait %s FCFA, au-delà du seuil de %s FCFA.',
                        $this->money($cumulativeAmount),
                        $this->money(Refund::MANAGER_APPROVAL_THRESHOLD),
                    ),
                ]);
            }

            $oldStatus = $lockedRefund->status;

            $lockedRefund->forceFill([
                'status' => Refund::STATUS_APPROVED,
                'approved_by_id' => $lockedApprover->id,
                'approved_at' => now(),
                'cumulative_amount' => $cumulativeAmount,
                'blocked_reason' => null,
            ])->save();

            $this->recordAudit(
                $lockedRefund,
                $lockedApprover,
                'refund.approved',
                ['status' => $oldStatus],
                [
                    'status' => Refund::STATUS_APPROVED,
                    'approved_by_id' => $lockedApprover->id,
                    'cumulative_amount' => $this->money($cumulativeAmount),
                ],
            );

            return $this->loadRefund($lockedRefund);
        });
    }

    /**
     * Rejette une demande en attente.
     *
     * @throws ValidationException
     */
    public function reject(Refund $refund, User $approver, string $reason): Refund
    {
        $reason = $this->normalizeReason($reason);

        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif du rejet est obligatoire.',
            ]);
        }

        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif du rejet ne peut pas dépasser 500 caractères.',
            ]);
        }

        return DB::transaction(function () use ($refund, $approver, $reason): Refund {
            [$lockedRefund] = $this->lockRefundContext($refund);
            $lockedApprover = $this->lockAuthorizedUser((int) $approver->id, 'approuver_remboursement');

            $this->assertNotRequester($lockedRefund, $lockedApprover);

            if ($lockedRefund->status !== Refund::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'refund' => 'Seule une demande en attente peut être rejetée.',
                ]);
            }

            $oldStatus = $lockedRefund->status;

            $lockedRefund->forceFill([
                'status' => Refund::STATUS_REJECTED,
                'rejected_by_id' => $lockedApprover->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->recordAudit(
                $lockedRefund,
                $lockedApprover,
                'refund.rejected',
                ['status' => $oldStatus],
                [
                    'status' => Refund::STATUS_REJECTED,
                    'rejected_by_id' => $lockedApprover->id,
                    'rejection_reason' => $reason,
                ],
            );

            return $this->loadRefund($lockedRefund);
        });
    }

    /**
     * Annule une demande encore en attente, bloquee ou approuvee.
     *
     * @throws ValidationException
     */
    public function cancel(Refund $refund, User $actor, ?string $reason = null): Refund
    {
        $reason = $this->normalizeReason($reason);

        if ($reason !== null && mb_strlen($reason, 'UTF-8') > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif d’annulation ne peut pas dépasser 500 caractères.',
            ]);
        }

        return DB::transaction(function () use ($refund, $actor, $reason): Refund {
            [$lockedRefund] = $this->lockRefundContext($refund);
            $status = $lockedRefund->status;

            if (in_array($status, [Refund::STATUS_PENDING, Refund::STATUS_BLOCKED], true)) {
                $lockedActor = $this->lockAuthorizedUser((int) $actor->id, 'demander_remboursement');

                if ((int) $lockedRefund->requested_by_id !== (int) $lockedActor->id) {
                    abort(403, 'Seul le demandeur peut annuler cette demande.');
                }
            } elseif ($status === Refund::STATUS_APPROVED) {
                $lockedActor = $this->lockAuthorizedUser((int) $actor->id, 'annuler_remboursement');

                if ($reason === null) {
                    throw ValidationException::withMessages([
                        'reason' => 'Le motif d’annulation est obligatoire pour un remboursement approuvé.',
                    ]);
                }
            } else {
                throw ValidationException::withMessages([
                    'refund' => 'Cette demande ne peut plus être annulée.',
                ]);
            }

            $oldStatus = $status;

            $lockedRefund->forceFill([
                'status' => Refund::STATUS_CANCELLED,
                'cancelled_by_id' => $lockedActor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->recordAudit(
                $lockedRefund,
                $lockedActor,
                'refund.cancelled',
                ['status' => $oldStatus],
                array_filter([
                    'status' => Refund::STATUS_CANCELLED,
                    'cancelled_by_id' => $lockedActor->id,
                    'cancellation_reason' => $reason,
                ], fn (mixed $value): bool => $value !== null),
            );

            return $this->loadRefund($lockedRefund);
        });
    }

    /**
     * Execute un remboursement approuve.
     *
     * Les especes sont payees immediatement depuis la session ouverte de
     * l'executeur. Les autres moyens passent en "executing" en attendant la
     * reconciliation qui confirmera ou infirmera le transfert.
     *
     * @throws ValidationException
     */
    public function execute(Refund $refund, User $executor): Refund
    {
        return DB::transaction(function () use ($refund, $executor): Refund {
            // Ordre global : session du tiroir -> vente source -> paiements
            // (source + tiroir) -> remboursements (paiement source + tiroir)
            // -> depenses -> utilisateur. La session est prise en premier pour
            // figer le tiroir ; create/approve gardent la sous-sequence
            // vente -> paiement -> remboursements -> utilisateur.
            $requestedMethod = (string) $refund->getAttribute('method');
            $cashSession = $requestedMethod === Refund::METHOD_CASH
                ? $this->lockOpenCashSession((int) $executor->id)
                : null;

            $lockedSale = Sale::query()
                ->lockForUpdate()
                ->findOrFail((int) $refund->getAttribute('sale_id'));

            $sourcePaymentId = (int) $refund->getAttribute('payment_id');
            $drawerPaymentIds = $cashSession instanceof CashSession
                ? $cashSession->cashPaymentIds()
                : [];

            $lockedPayments = $this->lockPayments(array_merge(
                [$sourcePaymentId],
                $drawerPaymentIds,
            ));

            $lockedPayment = $lockedPayments->firstWhere('id', $sourcePaymentId);

            if (! $lockedPayment instanceof Payment) {
                throw (new ModelNotFoundException)->setModel(Payment::class, [$sourcePaymentId]);
            }

            // Le paiement source est verrouille : aucune nouvelle reservation
            // ne peut etre creee sur ce paiement avant la lecture des lignes.
            $sourcePaymentRefundIds = Refund::query()
                ->where('payment_id', $lockedPayment->id)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $drawerRefundIds = $cashSession instanceof CashSession
                ? $cashSession->executedCashRefundIds()
                : [];

            $lockedRefunds = $this->lockRefunds(array_merge(
                [(int) $refund->getKey()],
                $sourcePaymentRefundIds,
                $drawerRefundIds,
            ));

            $lockedRefund = $lockedRefunds->firstWhere('id', (int) $refund->getKey());

            if (! $lockedRefund instanceof Refund) {
                throw (new ModelNotFoundException)->setModel(Refund::class, [(int) $refund->getKey()]);
            }

            $sourcePaymentRefunds = $lockedRefunds
                ->where('payment_id', $lockedPayment->id)
                ->values();

            // La session empeche toute nouvelle depense ; les lignes sont
            // neanmoins verrouillees avec les autres composantes du tiroir.
            $lockedExpenses = $cashSession instanceof CashSession
                ? Expense::query()
                    ->where('cash_session_id', $cashSession->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : collect();

            $this->assertRefundBelongsToContext($lockedRefund, $lockedSale, $lockedPayment);
            $this->assertPaymentBelongsToSale($lockedSale, $lockedPayment);
            $this->assertPaymentIsConfirmed($lockedPayment);

            if ($lockedRefund->status === Refund::STATUS_APPROVED) {
                $this->assertAmountWithinRefundableBalanceFromRefunds(
                    $lockedPayment,
                    round((float) $lockedRefund->amount, 2),
                    (int) $lockedRefund->id,
                    $sourcePaymentRefunds,
                );
            }

            $lockedExecutor = $this->lockAuthorizedUser((int) $executor->id, 'executer_remboursement');

            if (! $this->accessService->canExecute($lockedExecutor, $lockedRefund)) {
                abort(Response::HTTP_FORBIDDEN, 'Vous ne pouvez pas exécuter ce remboursement.');
            }

            if ($lockedRefund->status === Refund::STATUS_EXECUTED) {
                return $this->resolveExecutionReplay($lockedRefund, $lockedExecutor);
            }

            if ($lockedRefund->status === Refund::STATUS_EXECUTING) {
                abort(
                    Response::HTTP_CONFLICT,
                    'Cette exécution est déjà en cours. Attendez sa réconciliation.',
                );
            }

            if ($lockedRefund->status !== Refund::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'refund' => 'Seul un remboursement approuvé peut être exécuté.',
                ]);
            }

            $this->assertExecutorSeparation($lockedRefund, $lockedExecutor);

            if ($lockedRefund->isCash() && ! $cashSession instanceof CashSession) {
                if ($requestedMethod === Refund::METHOD_CASH) {
                    throw new NoOpenCashSessionException(
                        'Aucune session de caisse n’est ouverte. Ouvrez-en une avant d’exécuter un remboursement en espèces.',
                    );
                }

                abort(
                    Response::HTTP_CONFLICT,
                    'Le mode de remboursement a changé pendant l’exécution. Réessayez.',
                );
            }

            if (! $lockedRefund->isCash()) {
                return $this->startReconciledExecution(
                    $lockedRefund,
                    $lockedExecutor,
                    $lockedPayment,
                    $sourcePaymentRefunds,
                );
            }

            $drawerPaymentIds = array_map('intval', $drawerPaymentIds);
            $drawerRefundIds = array_map('intval', $drawerRefundIds);

            $drawerPayments = $lockedPayments->filter(
                fn (Payment $payment): bool => in_array((int) $payment->id, $drawerPaymentIds, true),
            );
            $drawerRefunds = $lockedRefunds->filter(
                fn (Refund $refund): bool => in_array((int) $refund->id, $drawerRefundIds, true),
            );

            $cashReceived = round((float) $drawerPayments
                ->filter(fn (Payment $payment): bool => $payment->method === 'cash'
                    && $payment->status === Payment::STATUS_CONFIRMED)
                ->sum('amount'), 2);
            $expensesPaid = round((float) $lockedExpenses->sum('amount'), 2);
            $refundsPaid = round((float) $drawerRefunds
                ->filter(fn (Refund $refund): bool => $refund->method === Refund::METHOD_CASH
                    && $refund->status === Refund::STATUS_EXECUTED)
                ->sum('amount'), 2);

            $expectedCash = $cashSession->computeExpectedCashFromTotals(
                $cashReceived,
                $expensesPaid,
                $refundsPaid,
            );

            return $this->executeCash($lockedRefund, $lockedExecutor, $cashSession, $expectedCash);
        });
    }

    /**
     * Enregistre la decision de reconciliation d'un remboursement non especes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function reconcile(Refund $refund, User $reconciler, array $data): Refund
    {
        $decision = trim((string) ($data['decision'] ?? ''));
        $reason = $this->normalizeReason($data['reason'] ?? null);
        $reference = $this->normalizeReference($data['reconciliation_reference'] ?? null);

        $this->assertReconciliationPayload($decision, $reason, $reference);

        return DB::transaction(function () use ($refund, $reconciler, $decision, $reason, $reference): Refund {
            [$lockedRefund] = $this->lockRefundContext($refund);
            $lockedReconciler = $this->lockAuthorizedUser((int) $reconciler->id, 'reconcilier_remboursement');

            if (! $this->accessService->canReconcile($lockedReconciler, $lockedRefund)) {
                abort(Response::HTTP_FORBIDDEN, 'Vous ne pouvez pas réconcilier ce remboursement.');
            }

            if ($lockedRefund->isCash()) {
                throw ValidationException::withMessages([
                    'refund' => 'Un remboursement en espèces est exécuté immédiatement et ne se réconcilie pas.',
                ]);
            }

            if (! in_array($lockedRefund->status, [
                Refund::STATUS_EXECUTING,
                Refund::STATUS_RECONCILIATION_PENDING,
            ], true)) {
                throw ValidationException::withMessages([
                    'refund' => 'Seul un remboursement en cours d’exécution ou en attente de réconciliation peut être réconcilié.',
                ]);
            }

            $executorId = (int) $lockedRefund->executed_by_id;

            if ($executorId < 1) {
                throw ValidationException::withMessages([
                    'refund' => 'Ce remboursement n’a pas d’exécutant à réconcilier.',
                ]);
            }

            $this->assertReconcilerSeparation($lockedRefund, $lockedReconciler);

            return match ($decision) {
                Refund::RECONCILIATION_CONFIRMED => $this->confirmReconciliation(
                    $lockedRefund,
                    $lockedReconciler,
                    $reason,
                    $reference,
                ),
                Refund::RECONCILIATION_ABSENT => $this->failReconciliation(
                    $lockedRefund,
                    $lockedReconciler,
                    (string) $reason,
                    $reference,
                ),
                Refund::RECONCILIATION_PENDING => $this->markReconciliationPending(
                    $lockedRefund,
                    $lockedReconciler,
                    (string) $reason,
                    $reference,
                ),
            };
        });
    }

    /**
     * Rejoue une execution especes deja terminee par le meme acteur.
     *
     * @throws ValidationException
     */
    private function resolveExecutionReplay(Refund $refund, User $actor): Refund
    {
        $this->assertExecutorSeparation($refund, $actor);

        if (! $refund->isCash()) {
            abort(Response::HTTP_CONFLICT, 'Ce remboursement a déjà été exécuté.');
        }

        $movement = CashMovement::query()
            ->where('refund_id', $refund->id)
            ->lockForUpdate()
            ->first();

        if (! $movement instanceof CashMovement
            || (int) $refund->executed_by_id !== (int) $actor->id) {
            abort(Response::HTTP_CONFLICT, 'Ce remboursement a déjà été exécuté.');
        }

        return $this->loadRefund($refund);
    }

    /**
     * Verrouille la session ouverte de l'executeur, avant tout autre contexte.
     *
     * Ce verrou est le point de serialisation des executions especes d'un
     * meme utilisateur. Il doit etre acquis avant la vente, le paiement et le
     * remboursement pour que le calcul du tiroir ne puisse pas croiser les
     * verrous d'une autre execution.
     */
    private function lockOpenCashSession(int $executorId): ?CashSession
    {
        return CashSession::query()
            ->where('user_id', $executorId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Paie un remboursement especes depuis la session ouverte de l'executeur.
     *
     * @throws ValidationException
     */
    private function executeCash(
        Refund $refund,
        User $executor,
        CashSession $cashSession,
        float $expectedCash,
    ): Refund {
        $this->documentService->assertExecutionProof($refund);

        $amount = round((float) $refund->amount, 2);

        if ($this->toCents($expectedCash) < $this->toCents($amount)) {
            throw new InsufficientCashException($cashSession, $expectedCash, $amount);
        }

        $startedAt = now();

        $refund->forceFill([
            'status' => Refund::STATUS_EXECUTING,
            'executed_by_id' => $executor->id,
            'execution_started_at' => $startedAt,
            'cash_session_id' => $cashSession->id,
        ])->save();

        $this->recordAudit(
            $refund,
            $executor,
            'refund.execution_started',
            ['status' => Refund::STATUS_APPROVED],
            [
                'status' => Refund::STATUS_EXECUTING,
                'method' => $refund->method,
                'executed_by_id' => $executor->id,
                'execution_started_at' => $startedAt->toIso8601String(),
                'cash_session_id' => $cashSession->id,
            ],
        );

        $movement = CashMovement::create([
            'cash_session_id' => $cashSession->id,
            'user_id' => $executor->id,
            'refund_id' => $refund->id,
            'type' => 'out',
            'amount' => $amount,
            'reason' => mb_substr('Remboursement '.$refund->reference, 0, 255, 'UTF-8'),
        ]);

        $executedAt = now();

        $refund->forceFill([
            'status' => Refund::STATUS_EXECUTED,
            'executed_at' => $executedAt,
        ])->save();

        $this->recordAudit(
            $refund,
            $executor,
            'refund.executed',
            ['status' => Refund::STATUS_EXECUTING],
            [
                'status' => Refund::STATUS_EXECUTED,
                'executed_by_id' => $executor->id,
                'executed_at' => $executedAt->toIso8601String(),
                'cash_session_id' => $cashSession->id,
                'cash_movement_id' => $movement->id,
            ],
        );

        return $this->loadRefund($refund);
    }

    /**
     * Demarre l'execution d'un remboursement electronique ou par cheque.
     */
    private function startReconciledExecution(
        Refund $refund,
        User $executor,
        Payment $payment,
        Collection $sourcePaymentRefunds,
    ): Refund {
        $amount = round((float) $refund->amount, 2);

        $this->assertAmountWithinRefundableBalanceFromRefunds(
            $payment,
            $amount,
            (int) $refund->id,
            $sourcePaymentRefunds,
        );

        $startedAt = now();

        $refund->forceFill([
            'status' => Refund::STATUS_EXECUTING,
            'executed_by_id' => $executor->id,
            'execution_started_at' => $startedAt,
        ])->save();

        $this->recordAudit(
            $refund,
            $executor,
            'refund.execution_started',
            ['status' => Refund::STATUS_APPROVED],
            [
                'status' => Refund::STATUS_EXECUTING,
                'method' => $refund->method,
                'executed_by_id' => $executor->id,
                'execution_started_at' => $startedAt->toIso8601String(),
            ],
        );

        return $this->loadRefund($refund);
    }

    /**
     * Confirme que le transfert a bien eu lieu.
     *
     * @throws ValidationException
     */
    private function confirmReconciliation(
        Refund $refund,
        User $reconciler,
        ?string $reason,
        ?string $reference,
    ): Refund {
        $this->documentService->assertExecutionProof($refund);

        $oldStatus = $refund->status;
        $reconciledAt = now();

        $refund->forceFill([
            'status' => Refund::STATUS_EXECUTED,
            'executed_at' => $reconciledAt,
            'reconciled_by_id' => $reconciler->id,
            'reconciled_at' => $reconciledAt,
            'reconciliation_reason' => $reason,
            'reconciliation_reference' => $reference,
        ])->save();

        $this->recordAudit(
            $refund,
            $reconciler,
            'refund.reconciled',
            ['status' => $oldStatus],
            [
                'status' => Refund::STATUS_EXECUTED,
                'decision' => Refund::RECONCILIATION_CONFIRMED,
                'reconciled_by_id' => $reconciler->id,
                'reconciled_at' => $reconciledAt->toIso8601String(),
                'reconciliation_reference' => $reference,
            ],
        );

        return $this->loadRefund($refund);
    }

    /**
     * Confirme que le transfert n'a pas eu lieu.
     *
     * @throws ValidationException
     */
    private function failReconciliation(
        Refund $refund,
        User $reconciler,
        string $reason,
        ?string $reference,
    ): Refund {
        $refund->forceFill(['failure_reason' => $reason]);
        $this->documentService->assertFailureProof($refund);

        $oldStatus = $refund->status;
        $reconciledAt = now();

        $refund->forceFill([
            'status' => Refund::STATUS_FAILED_CONFIRMED,
            'failed_at' => $reconciledAt,
            'reconciled_by_id' => $reconciler->id,
            'reconciled_at' => $reconciledAt,
            'reconciliation_reference' => $reference,
        ])->save();

        $this->recordAudit(
            $refund,
            $reconciler,
            'refund.failed_confirmed',
            ['status' => $oldStatus],
            [
                'status' => Refund::STATUS_FAILED_CONFIRMED,
                'decision' => Refund::RECONCILIATION_ABSENT,
                'failure_reason' => $reason,
                'reconciled_by_id' => $reconciler->id,
                'reconciled_at' => $reconciledAt->toIso8601String(),
                'reconciliation_reference' => $reference,
            ],
        );

        return $this->loadRefund($refund);
    }

    /**
     * Maintient le remboursement en attente d'une nouvelle reconciliation.
     */
    private function markReconciliationPending(
        Refund $refund,
        User $reconciler,
        string $reason,
        ?string $reference,
    ): Refund {
        $oldStatus = $refund->status;
        $reconciledAt = now();

        $refund->forceFill([
            'status' => Refund::STATUS_RECONCILIATION_PENDING,
            'reconciliation_reason' => $reason,
            'reconciliation_reference' => $reference,
            'reconciliation_required_at' => $reconciledAt,
        ])->save();

        $this->recordAudit(
            $refund,
            $reconciler,
            'refund.reconciliation_pending',
            ['status' => $oldStatus],
            [
                'status' => Refund::STATUS_RECONCILIATION_PENDING,
                'decision' => Refund::RECONCILIATION_PENDING,
                'reconciliation_reason' => $reason,
                'reconciliation_required_at' => $reconciledAt->toIso8601String(),
                'reconciliation_reference' => $reference,
            ],
        );

        return $this->loadRefund($refund);
    }

    /**
     * Verifie la decision de reconciliation.
     *
     * @throws ValidationException
     */
    private function assertReconciliationPayload(
        string $decision,
        ?string $reason,
        ?string $reference,
    ): void {
        if (! in_array($decision, Refund::RECONCILIATION_DECISIONS, true)) {
            throw ValidationException::withMessages([
                'decision' => 'La décision de réconciliation est invalide.',
            ]);
        }

        if ($reason !== null && mb_strlen($reason, 'UTF-8') > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif ne peut pas dépasser 500 caractères.',
            ]);
        }

        if ($reference !== null && mb_strlen($reference, 'UTF-8') > 255) {
            throw ValidationException::withMessages([
                'reconciliation_reference' => 'La référence de réconciliation ne peut pas dépasser 255 caractères.',
            ]);
        }

        if (in_array($decision, [Refund::RECONCILIATION_ABSENT, Refund::RECONCILIATION_PENDING], true)
            && $reason === null) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif est obligatoire pour une absence ou une incertitude.',
            ]);
        }
    }

    /**
     * Convertit un montant en centimes pour eviter les erreurs d'arrondi.
     */
    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Verifie les donnees minimales de creation traitees par le service.
     *
     * @throws ValidationException
     */
    private function assertCreatePayload(
        int $saleId,
        int $paymentId,
        string $method,
        float $amount,
        ?string $reference,
        ?string $reason,
    ): void {
        if ($saleId < 1) {
            throw ValidationException::withMessages([
                'sale_id' => 'La vente est obligatoire.',
            ]);
        }

        if ($paymentId < 1) {
            throw ValidationException::withMessages([
                'payment_id' => 'Le paiement est obligatoire.',
            ]);
        }

        if (! in_array($method, Refund::METHODS, true)) {
            throw ValidationException::withMessages([
                'method' => 'Le moyen de remboursement est invalide.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant du remboursement doit être supérieur à zéro.',
            ]);
        }

        if ($reference !== null && mb_strlen($reference, 'UTF-8') > 255) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'La référence de transaction ne peut pas dépasser 255 caractères.',
            ]);
        }

        if (Refund::requiresTransactionReference($method) && $reference === null) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'La référence de transaction est obligatoire pour Wave, Orange Money et les virements bancaires.',
            ]);
        }

        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif du remboursement est obligatoire.',
            ]);
        }

        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif du remboursement ne peut pas dépasser 500 caractères.',
            ]);
        }
    }

    /**
     * Statut initial et motif de blocage d'une nouvelle demande.
     *
     * Le cumul projete est fourni deja verrouille par l'appelant, afin de ne
     * plus lire les remboursements apres le verrou utilisateur.
     *
     * @return array{0: string, 1: string|null}
     */
    private function resolveInitialStatus(User $requester, float $projected): array
    {
        $mustBlock = $requester->hasRole('Admin')
            && ! $this->hasOtherActiveAdmin($requester)
            && $projected > Refund::MANAGER_APPROVAL_THRESHOLD;

        if (! $mustBlock) {
            return [Refund::STATUS_PENDING, null];
        }

        return [
            Refund::STATUS_BLOCKED,
            sprintf(
                'Aucun autre administrateur actif ne peut approuver ce remboursement : le cumul atteindrait %s FCFA, au-delà du seuil de %s FCFA.',
                $this->money($projected),
                $this->money(Refund::MANAGER_APPROVAL_THRESHOLD),
            ),
        ];
    }

    /**
     * Indique si un autre compte Admin actif existe.
     */
    private function hasOtherActiveAdmin(User $requester): bool
    {
        return User::query()
            ->whereKeyNot($requester->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'Admin'))
            ->exists();
    }

    /**
     * Verrouille la vente, le paiement et le remboursement dans un ordre stable.
     *
     * Ordre commun a tous les ecrivains de remboursement, y compris `create`
     * qui verrouille aussi les lignes de remboursement pour son cumul :
     * vente -> paiement -> remboursement(s) -> utilisateur. Les identifiants
     * portes par le modele de route sont immuables et servent uniquement a
     * cibler les verrous ; toutes les valeurs metier sont ensuite relues par
     * des lectures courantes (`lockForUpdate`), jamais depuis le snapshot de
     * la requete ni depuis un modele eventuellement perime.
     *
     * L'execution especes ajoute la session avant cette sous-sequence et
     * verrouille tous les paiements et remboursements du tiroir avant
     * l'utilisateur. L'ordre complet est donc :
     * session -> vente source -> paiements (source + tiroir)
     * -> remboursements (paiement source + tiroir) -> depenses -> utilisateur.
     * Aucun verrou de paiement ou de remboursement n'est pris apres l'utilisateur.
     *
     * @return array{0: Refund, 1: Sale, 2: Payment}
     *
     * @throws ValidationException
     */
    private function lockRefundContext(Refund $refund): array
    {
        $refundId = (int) $refund->getKey();
        $saleId = (int) $refund->getAttribute('sale_id');
        $paymentId = (int) $refund->getAttribute('payment_id');

        $lockedSale = Sale::query()
            ->lockForUpdate()
            ->findOrFail($saleId);

        $lockedPayment = Payment::query()
            ->lockForUpdate()
            ->findOrFail($paymentId);

        $lockedRefund = Refund::query()
            ->lockForUpdate()
            ->findOrFail($refundId);

        $this->assertRefundBelongsToContext($lockedRefund, $lockedSale, $lockedPayment);

        return [$lockedRefund, $lockedSale, $lockedPayment];
    }

    /**
     * Verrouille un ensemble de paiements par cle primaire croissante.
     *
     * @param  array<int, int>  $ids
     */
    private function lockPayments(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($ids === []) {
            return collect();
        }

        return Payment::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Verrouille un ensemble de remboursements par cle primaire croissante.
     *
     * @param  array<int, int>  $ids
     */
    private function lockRefunds(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($ids === []) {
            return collect();
        }

        return Refund::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function assertRefundBelongsToContext(
        Refund $refund,
        Sale $sale,
        Payment $payment,
    ): void {
        if ((int) $refund->sale_id !== (int) $sale->id
            || (int) $refund->payment_id !== (int) $payment->id) {
            throw ValidationException::withMessages([
                'refund' => 'Le remboursement ne correspond pas à sa vente ou à son paiement.',
            ]);
        }
    }

    /**
     * Recharge l'acteur et revalide son activation et sa permission.
     *
     * @throws ValidationException
     */
    private function lockAuthorizedUser(int $userId, string $permission): User
    {
        $user = User::query()
            ->lockForUpdate()
            ->findOrFail($userId);

        if (! $user->is_active) {
            abort(403, 'Votre compte a été désactivé. Contactez un administrateur.');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        if (! $user->hasPermissionTo($permission)) {
            abort(403, 'Vous n’avez pas la permission requise pour cette action.');
        }

        return $user;
    }

    /**
     * Interdit au demandeur d'approuver ou de rejeter sa propre demande.
     */
    private function assertNotRequester(Refund $refund, User $actor): void
    {
        if ((int) $refund->requested_by_id === (int) $actor->id) {
            abort(403, 'Le demandeur ne peut pas approuver ou rejeter son propre remboursement.');
        }
    }

    /**
     * Interdit au demandeur ou a l'approbateur d'executer le remboursement.
     */
    private function assertExecutorSeparation(Refund $refund, User $executor): void
    {
        if ((int) $refund->requested_by_id === (int) $executor->id) {
            abort(403, 'Le demandeur ne peut pas exécuter son propre remboursement.');
        }

        if ($refund->approved_by_id !== null
            && (int) $refund->approved_by_id === (int) $executor->id) {
            abort(403, 'L’approbateur ne peut pas exécuter le remboursement qu’il a approuvé.');
        }
    }

    /**
     * Interdit a l'executant initial de reconcilier son propre remboursement.
     *
     * Le demandeur et l'approbateur restent autorises a reconcilier.
     */
    private function assertReconcilerSeparation(Refund $refund, User $reconciler): void
    {
        if ((int) $refund->executed_by_id === (int) $reconciler->id) {
            abort(
                Response::HTTP_FORBIDDEN,
                'L’exécuteur ne peut pas réconcilier son propre remboursement.',
            );
        }
    }

    /**
     * Verifie le rattachement du paiement a la vente.
     *
     * @throws ValidationException
     */
    private function assertPaymentBelongsToSale(Sale $sale, Payment $payment): void
    {
        if ((int) $payment->sale_id !== (int) $sale->id) {
            throw ValidationException::withMessages([
                'payment_id' => 'Le paiement ne correspond pas à la vente associée.',
            ]);
        }
    }

    /**
     * Verifie que le paiement initial est confirme.
     *
     * @throws ValidationException
     */
    private function assertPaymentIsConfirmed(Payment $payment): void
    {
        if ($payment->status !== Payment::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'payment' => 'Seuls les paiements confirmés peuvent faire l’objet d’un remboursement.',
            ]);
        }
    }

    /**
     * Verifie que le cumul reserve ne depasse pas le montant du paiement.
     *
     * @throws ValidationException
     */
    private function assertAmountWithinRefundableBalance(
        Payment $payment,
        float $amount,
        ?int $excludeRefundId = null,
    ): void {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant du remboursement doit être supérieur à zéro.',
            ]);
        }

        $reserved = $this->reservedAmount($payment, $excludeRefundId);
        $paymentAmount = round((float) $payment->amount, 2);

        if (round($reserved + $amount, 2) > $paymentAmount) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'Le montant demandé dépasse le solde remboursable du paiement (%s FCFA).',
                    $this->money(max(0, round($paymentAmount - $reserved, 2))),
                ),
            ]);
        }
    }

    /**
     * Verifie le solde remboursable depuis des lignes deja verrouillees.
     */
    private function assertAmountWithinRefundableBalanceFromRefunds(
        Payment $payment,
        float $amount,
        ?int $excludeRefundId,
        Collection $refunds,
    ): void {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant du remboursement doit être supérieur à zéro.',
            ]);
        }

        $reserved = round((float) $refunds
            ->filter(fn (Refund $refund): bool => (int) $refund->payment_id === (int) $payment->id
                && $refund->reservesAmount()
                && ($excludeRefundId === null || (int) $refund->id !== $excludeRefundId))
            ->sum('amount'), 2);
        $paymentAmount = round((float) $payment->amount, 2);

        if (round($reserved + $amount, 2) > $paymentAmount) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'Le montant demandé dépasse le solde remboursable du paiement (%s FCFA).',
                    $this->money(max(0, round($paymentAmount - $reserved, 2))),
                ),
            ]);
        }
    }

    /**
     * Somme des remboursements qui reservent encore tout ou partie du paiement.
     *
     * La lecture est verrouillee (lecture courante InnoDB) : elle ignore le
     * snapshot REPEATABLE READ de la transaction et voit donc les reservations
     * validees par une transaction concurrente juste avant notre verrou.
     */
    private function reservedAmount(Payment $payment, ?int $excludeRefundId = null): float
    {
        return $this->sumRefundsForPayment($payment, true, $excludeRefundId);
    }

    /**
     * Somme des remboursements comptes dans le seuil d'approbation.
     *
     * Lecture verrouillee pour recalculer le cumul reel au moment de
     * l'approbation, y compris apres des approbations concurrentes.
     */
    private function thresholdAmount(Payment $payment, ?int $excludeRefundId = null): float
    {
        return $this->sumRefundsForPayment($payment, false, $excludeRefundId);
    }

    /**
     * Somme courante des remboursements d'un paiement.
     *
     * Les lignes sont chargees avec `lockForUpdate` puis additionnees en PHP :
     * c'est une lecture courante qui contourne le snapshot REPEATABLE READ et
     * reste portable (SQLite, MySQL).
     */
    private function sumRefundsForPayment(
        Payment $payment,
        bool $reserved,
        ?int $excludeRefundId,
    ): float {
        $query = Refund::query()
            ->where('payment_id', $payment->id);

        if ($reserved) {
            $query->reserved();
        } else {
            $query->countedInThreshold();
        }

        if ($excludeRefundId !== null) {
            $query->whereKeyNot($excludeRefundId);
        }

        return round(
            (float) $query
                ->lockForUpdate()
                ->get(['id', 'amount'])
                ->sum('amount'),
            2,
        );
    }

    /**
     * Retourne une demande deja creee, ou refuse une charge utile differente.
     */
    private function resolveExisting(Refund $refund, string $fingerprint): Refund
    {
        if (! is_string($refund->idempotency_fingerprint)
            || ! hash_equals($refund->idempotency_fingerprint, $fingerprint)) {
            throw new IdempotencyConflictException;
        }

        return $this->loadRefund($refund);
    }

    /**
     * Recherche la demande d'un paiement pour cette cle d'idempotence.
     */
    private function findByIdempotencyKey(int $paymentId, string $idempotencyKey, bool $lock = false): ?Refund
    {
        $query = Refund::query()
            ->where('payment_id', $paymentId)
            ->where('idempotency_key', $idempotencyKey);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Empreinte stable de la demande initiale d'un remboursement.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Empreinte d'une reference, insensible aux accents, a la casse et aux espaces externes.
     */
    private function referenceHash(?string $reference): ?string
    {
        $canonical = $this->canonicalText($reference);

        return $canonical === null ? null : hash('sha256', $canonical);
    }

    /**
     * Cle d'idempotence canonique et non vide.
     *
     * @throws ValidationException
     */
    private function normalizeIdempotencyKey(mixed $key): string
    {
        if (! is_string($key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clé d’idempotence est obligatoire.',
            ]);
        }

        $canonical = $this->canonicalText($key);

        if ($canonical === null) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clé d’idempotence est obligatoire.',
            ]);
        }

        if (mb_strlen($canonical, 'UTF-8') > 64) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clé d’idempotence normalisée ne peut pas dépasser 64 caractères.',
            ]);
        }

        return $canonical;
    }

    /**
     * Forme canonique commune aux cles et aux references.
     */
    private function canonicalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KD);

            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\p{M}+/u', '', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * Normalise une reference de transaction.
     */
    private function normalizeReference(mixed $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        $reference = trim($reference);

        return $reference === '' ? null : $reference;
    }

    /**
     * Normalise un motif libre.
     */
    private function normalizeReason(mixed $reason): ?string
    {
        if (! is_string($reason)) {
            return null;
        }

        $reason = trim($reason);

        return $reason === '' ? null : $reason;
    }

    /**
     * Indique si une reference existe deja pour ce mode de remboursement.
     */
    private function referenceExists(string $method, string $reference): bool
    {
        $canonical = $this->canonicalText($reference);

        if ($canonical === null) {
            return false;
        }

        if (Refund::query()
            ->where('method', $method)
            ->where('transaction_reference', trim($reference))
            ->exists()) {
            return true;
        }

        $refunds = Refund::query()
            ->where('method', $method)
            ->whereNotNull('transaction_reference')
            ->cursor();

        foreach ($refunds as $refund) {
            if ($this->canonicalText($refund->transaction_reference) === $canonical) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifie qu'une reference n'est pas deja utilisee pour ce mode.
     *
     * @throws ValidationException
     */
    private function assertReferenceAvailable(string $method, string $reference): void
    {
        if ($this->referenceExists($method, $reference)) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'Cette référence de transaction est déjà utilisée.',
            ]);
        }
    }

    /**
     * Charge les relations publiques d'un remboursement.
     */
    private function loadRefund(Refund $refund): Refund
    {
        return $refund->load([
            'sale.customer',
            'sale.user',
            'payment',
            'requestedBy',
            'approvedBy',
            'rejectedBy',
            'executedBy',
            'cancelledBy',
            'reconciledBy',
            'cashSession',
            'cashMovement',
        ]);
    }

    /**
     * Trace une transition dans le journal d'audit existant.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function recordAudit(
        Refund $refund,
        User $actor,
        string $action,
        ?array $oldValues,
        array $newValues,
    ): void {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'model_type' => Refund::class,
            'model_id' => $refund->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    /**
     * Formate un montant canonique.
     */
    private function money(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }
}
