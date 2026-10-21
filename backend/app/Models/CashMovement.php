<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    protected $fillable = ['cash_session_id', 'user_id', 'type', 'amount', 'reason'];

    public function cashSession() { return $this->belongsTo(CashSession::class); }
    public function user() { return $this->belongsTo(User::class); }
}
