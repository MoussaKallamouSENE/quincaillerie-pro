<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Refund extends Model
{
    public const METHOD_CASH = 'cash';

    public const METHOD_WAVE = 'wave';

    public const METHOD_ORANGE_MONEY = 'orange_money';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_CHECK = 'check';

    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_WAVE,
        self::METHOD_ORANGE_MONEY,
        self::METHOD_BANK_TRANSFER,
        self::METHOD_CHECK,
    ];

    public const ELECTRONIC_METHODS = [
        self::METHOD_WAVE,
        self::METHOD_ORANGE_MONEY,
        self::METHOD_BANK_TRANSFER,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_RECONCILIATION_PENDING = 'reconciliation_pending';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED_CONFIRMED = 'failed_confirmed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_BLOCKED,
        self::STATUS_APPROVED,
        self::STATUS_EXECUTING,
        self::STATUS_RECONCILIATION_PENDING,
        self::STATUS_EXECUTED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_FAILED_CONFIRMED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_EXECUTED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_FAILED_CONFIRMED,
    ];

    public const RESERVED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_BLOCKED,
        self::STATUS_APPROVED,
        self::STATUS_EXECUTING,
        self::STATUS_EXECUTED,
        self::STATUS_RECONCILIATION_PENDING,
    ];

    public const THRESHOLD_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_EXECUTING,
        self::STATUS_EXECUTED,
        self::STATUS_RECONCILIATION_PENDING,
    ];

    public const MANAGER_APPROVAL_THRESHOLD = 100000.00;

    public const RECONCILIATION_CONFIRMED = 'confirmed';

    public const RECONCILIATION_ABSENT = 'absent';

    public const RECONCILIATION_PENDING = 'pending';

    public const RECONCILIATION_DECISIONS = [
        self::RECONCILIATION_CONFIRMED,
        self::RECONCILIATION_ABSENT,
        self::RECONCILIATION_PENDING,
    ];

    public const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_BLOCKED => [
            self::STATUS_APPROVED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_APPROVED => [
            self::STATUS_EXECUTING,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_EXECUTING => [
            self::STATUS_EXECUTED,
            self::STATUS_RECONCILIATION_PENDING,
            self::STATUS_FAILED_CONFIRMED,
        ],
        self::STATUS_RECONCILIATION_PENDING => [
            self::STATUS_EXECUTED,
            self::STATUS_FAILED_CONFIRMED,
        ],
        self::STATUS_EXECUTED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_FAILED_CONFIRMED => [],
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'reference',
        'sale_id',
        'payment_id',
        'requested_by_id',
        'approved_by_id',
        'rejected_by_id',
        'executed_by_id',
        'cancelled_by_id',
        'reconciled_by_id',
        'cash_session_id',
        'amount',
        'method',
        'transaction_reference',
        'reason',
        'status',
        'cumulative_amount',
        'blocked_reason',
        'rejection_reason',
        'cancellation_reason',
        'failure_reason',
        'reconciliation_reason',
        'reconciliation_reference',
        'idempotency_key',
        'idempotency_fingerprint',
        'approved_at',
        'rejected_at',
        'execution_started_at',
        'executed_at',
        'cancelled_at',
        'failed_at',
        'reconciliation_required_at',
        'reconciled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cumulative_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'execution_started_at' => 'datetime',
        'executed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'failed_at' => 'datetime',
        'reconciliation_required_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function cashMovement(): HasOne
    {
        return $this->hasOne(CashMovement::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RefundDocument::class);
    }

    public function scopeReserved(Builder $query): Builder
    {
        return $query->whereIn('status', self::RESERVED_STATUSES);
    }

    public function scopeCountedInThreshold(Builder $query): Builder
    {
        return $query->whereIn('status', self::THRESHOLD_STATUSES);
    }

    public static function requiresTransactionReference(string $method): bool
    {
        return in_array($method, self::ELECTRONIC_METHODS, true);
    }

    public function isElectronic(): bool
    {
        return self::requiresTransactionReference($this->method);
    }

    public function isCash(): bool
    {
        return $this->method === self::METHOD_CASH;
    }

    public function requiresReconciliation(): bool
    {
        return ! $this->isCash();
    }

    public function reservesAmount(): bool
    {
        return in_array($this->status, self::RESERVED_STATUSES, true);
    }

    public function countsTowardThreshold(): bool
    {
        return in_array($this->status, self::THRESHOLD_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
