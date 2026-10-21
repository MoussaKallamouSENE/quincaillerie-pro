<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    protected $fillable = ['customer_id', 'user_id', 'reference', 'total_amount', 'status', 'valid_until'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(QuoteItem::class); }
}
