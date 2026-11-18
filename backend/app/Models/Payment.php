<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Modes de paiement qui doivent etre verifies manuellement.
     *
     * @var array<int, string>
     */
    public const MANUAL_METHODS = ['wave', 'orange_money'];

    protected $fillable = [
        'sale_id',
        'user_id',
        'cash_session_id',
        'amount',
        'method',
        'reference',
        'status',
        'confirmed_by_id',
        'confirmed_at',
        'rejected_by_id',
        'rejected_at',
        'rejection_reason',
        'idempotency_key',
        'idempotency_fingerprint',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Indique si le mode de paiement exige une verification manuelle.
     */
    public static function requiresManualVerification(string $method): bool
    {
        return in_array($method, self::MANUAL_METHODS, true);
    }

    /**
     * Indique si le paiement exige une verification manuelle.
     */
    public function isManual(): bool
    {
        return self::requiresManualVerification($this->method);
    }
}
