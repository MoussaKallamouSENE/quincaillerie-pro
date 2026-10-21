<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = ['cash_session_id', 'user_id', 'name', 'amount', 'date'];

    public function cashSession() { return $this->belongsTo(CashSession::class); }
    public function user() { return $this->belongsTo(User::class); }
}
