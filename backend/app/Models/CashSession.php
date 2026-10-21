<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    protected $fillable = ['user_id', 'opening_balance', 'closing_balance', 'status', 'opened_at', 'closed_at'];

    public function user() { return $this->belongsTo(User::class); }
    public function sales() { return $this->hasMany(Sale::class); }
    public function movements() { return $this->hasMany(CashMovement::class); }
    public function expenses() { return $this->hasMany(Expense::class); }
}
