<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    protected $fillable = ['cash_session_id', 'user_id', 'refund_id', 'type', 'amount', 'reason'];

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
