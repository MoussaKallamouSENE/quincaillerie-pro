<?php

namespace App\Models;

use Database\Factories\CashSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    /** @use HasFactory<CashSessionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'opening_balance', 'closing_balance', 'expected_cash', 'status', 'opened_at', 'closed_at'];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function movements()
    {
        return $this->hasMany(CashMovement::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Montant en espèces encaissé pendant la session.
     *
     * Seuls les paiements réglés en espèces alimentent le fond de caisse :
     * les paiements par Wave, Orange Money, virement ou chèque en sont
     * exclus, car ils n'entrent pas dans le tiroir.
     */
    public function cashReceived(bool $lockForUpdate = false): float
    {
        if (! $lockForUpdate) {
            return round((float) $this->cashPaymentsQuery()->sum('payments.amount'), 2);
        }

        $paymentIds = $this->cashPaymentIds();

        if ($paymentIds === []) {
            return 0.0;
        }

        $payments = Payment::query()
            ->whereIn('id', $paymentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'sale_id', 'cash_session_id', 'amount', 'method', 'status']);

        $total = $payments
            ->filter(fn (Payment $payment): bool => $payment->method === 'cash'
                && $payment->status === Payment::STATUS_CONFIRMED
                && $this->paymentBelongsToSession($payment))
            ->sum('amount');

        return round((float) $total, 2);
    }

    /**
     * Identifiants des paiements encaissés en espèces dans la session.
     *
     * @return array<int, int>
     */
    public function cashPaymentIds(): array
    {
        return $this->cashPaymentsQuery()
            ->select('payments.id')
            ->pluck('payments.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function cashPaymentsQuery(): Builder
    {
        return Payment::query()
            ->where('method', 'cash')
            ->where('status', Payment::STATUS_CONFIRMED)
            ->where(function (Builder $query): void {
                $query->where('cash_session_id', $this->id)
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('cash_session_id')
                            ->whereIn('sale_id', $this->sales()->select('id'));
                    });
            });
    }

    private function paymentBelongsToSession(Payment $payment): bool
    {
        if ((int) $payment->cash_session_id === (int) $this->id) {
            return true;
        }

        return $payment->cash_session_id === null
            && $this->sales()->whereKey($payment->sale_id)->exists();
    }

    /**
     * Montant des dépenses payées en espèces pendant la session.
     *
     * Ces sorties d'argent sont retranchees du solde theorique du tiroir.
     */
    public function expensesPaid(bool $lockForUpdate = false): float
    {
        $query = $this->expenses();

        $total = $lockForUpdate
            ? $query->lockForUpdate()->get()->sum('amount')
            : $query->sum('amount');

        return round((float) $total, 2);
    }

    /**
     * Montant des remboursements espèces effectivement payés pendant la session.
     */
    public function refundsPaid(bool $lockForUpdate = false): float
    {
        if (! $lockForUpdate) {
            return round((float) $this->executedCashRefundsQuery()->sum('amount'), 2);
        }

        $refundIds = $this->executedCashRefundIds();

        if ($refundIds === []) {
            return 0.0;
        }

        $refunds = Refund::query()
            ->whereIn('id', $refundIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'sale_id', 'cash_session_id', 'amount', 'method', 'status']);

        $total = $refunds
            ->filter(fn (Refund $refund): bool => $refund->method === Refund::METHOD_CASH
                && $refund->status === Refund::STATUS_EXECUTED
                && $this->refundBelongsToSession($refund))
            ->sum('amount');

        return round((float) $total, 2);
    }

    /**
     * Identifiants des remboursements espèces exécutés dans la session.
     *
     * @return array<int, int>
     */
    public function executedCashRefundIds(): array
    {
        return $this->executedCashRefundsQuery()
            ->select('refunds.id')
            ->pluck('refunds.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function executedCashRefundsQuery(): Builder
    {
        return $this->cashRefundsQuery()
            ->where('status', Refund::STATUS_EXECUTED);
    }

    private function cashRefundsQuery(): Builder
    {
        return Refund::query()
            ->where('method', Refund::METHOD_CASH)
            ->where(function (Builder $query): void {
                $query->where('cash_session_id', $this->id)
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('cash_session_id')
                            ->whereIn('sale_id', $this->sales()->select('id'));
                    });
            });
    }

    private function refundBelongsToSession(Refund $refund): bool
    {
        if ((int) $refund->cash_session_id === (int) $this->id) {
            return true;
        }

        return $refund->cash_session_id === null
            && $this->sales()->whereKey($refund->sale_id)->exists();
    }

    /**
     * Indique si un remboursement espèces doit encore être finalisé.
     */
    public function hasRunningCashRefund(): bool
    {
        return $this->cashRefundsQuery()
            ->whereIn('status', [
                Refund::STATUS_EXECUTING,
                Refund::STATUS_RECONCILIATION_PENDING,
            ])
            ->exists();
    }

    /**
     * Solde théorique du tiroir : fond de caisse + ventes en espèces - dépenses
     * - remboursements espèces.
     *
     * Une session close conserve le solde fige au moment de sa clôture : le
     * theorique d'une session cloturee ne bouge plus, meme si une dépense ou
     * un remboursement est enregistre apres coup.
     */
    public function expectedCash(): float
    {
        if ($this->closed_at !== null && $this->expected_cash !== null) {
            return (float) $this->expected_cash;
        }

        return $this->computeExpectedCash();
    }

    /**
     * Calcule le solde théorique du tiroir à l'instant présent.
     *
     * Avec `$lockCurrent`, chaque composante est relue avec un verrou
     * (lecture courante) : le solde intègre alors les ventes, dépenses et
     * remboursements validés par des transactions concurrentes, sans dépendre
     * du snapshot REPEATABLE READ de la transaction appelante.
     */
    public function computeExpectedCash(bool $lockCurrent = false): float
    {
        if ($lockCurrent) {
            $totals = $this->lockDrawerTotals();

            return $this->computeExpectedCashFromTotals(
                $totals['cash_received'],
                $totals['expenses_paid'],
                $totals['refunds_paid'],
            );
        }

        return $this->computeExpectedCashFromTotals(
            $this->cashReceived(),
            $this->expensesPaid(),
            $this->refundsPaid(),
        );
    }

    /**
     * Calcule le theorique depuis des composantes deja verrouillees.
     *
     * L'execution especes verrouille toutes les lignes du tiroir avant
     * l'utilisateur ; elle ne doit plus acquerir de verrous ensuite.
     */
    public function computeExpectedCashFromTotals(
        float $cashReceived,
        float $expensesPaid,
        float $refundsPaid,
    ): float {
        return round(
            (float) $this->opening_balance + $cashReceived - $expensesPaid - $refundsPaid,
            2,
        );
    }

    /**
     * Verrouille et calcule les composantes du tiroir dans l'ordre global.
     *
     * @return array{cash_received: float, expenses_paid: float, refunds_paid: float, expected_cash: float}
     */
    public function lockDrawerTotals(): array
    {
        $cashReceived = $this->lockCashPaymentsTotal();
        $refundsPaid = $this->lockExecutedCashRefundsTotal();
        $expensesPaid = round((float) Expense::query()
            ->where('cash_session_id', $this->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['amount'])
            ->sum('amount'), 2);

        return [
            'cash_received' => $cashReceived,
            'expenses_paid' => $expensesPaid,
            'refunds_paid' => $refundsPaid,
            'expected_cash' => $this->computeExpectedCashFromTotals(
                $cashReceived,
                $expensesPaid,
                $refundsPaid,
            ),
        ];
    }

    private function lockCashPaymentsTotal(): float
    {
        $paymentIds = $this->cashPaymentIds();

        if ($paymentIds === []) {
            return 0.0;
        }

        $payments = Payment::query()
            ->whereIn('id', $paymentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'sale_id', 'cash_session_id', 'amount', 'method', 'status']);

        return round((float) $payments
            ->filter(fn (Payment $payment): bool => $payment->method === 'cash'
                && $payment->status === Payment::STATUS_CONFIRMED
                && $this->paymentBelongsToSession($payment))
            ->sum('amount'), 2);
    }

    private function lockExecutedCashRefundsTotal(): float
    {
        $refundIds = $this->executedCashRefundIds();

        if ($refundIds === []) {
            return 0.0;
        }

        $refunds = Refund::query()
            ->whereIn('id', $refundIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'sale_id', 'cash_session_id', 'amount', 'method', 'status']);

        return round((float) $refunds
            ->filter(fn (Refund $refund): bool => $refund->method === Refund::METHOD_CASH
                && $refund->status === Refund::STATUS_EXECUTED
                && $this->refundBelongsToSession($refund))
            ->sum('amount'), 2);
    }

    /**
     * Écart entre le montant compté et le solde théorique du tiroir.
     *
     * Positif = excédent de caisse, négatif = manque d'argent.
     */
    public function difference(): ?float
    {
        if ($this->closing_balance === null) {
            return null;
        }

        return round((float) $this->closing_balance - $this->expectedCash(), 2);
    }

    /**
     * Écart entre le montant compté et le seul fond de caisse initial.
     */
    public function openingDifference(): ?float
    {
        if ($this->closing_balance === null) {
            return null;
        }

        return round((float) $this->closing_balance - (float) $this->opening_balance, 2);
    }
}
