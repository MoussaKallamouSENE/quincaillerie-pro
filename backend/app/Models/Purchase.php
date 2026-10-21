<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = [
        'supplier_id',
        'user_id',
        'reference',
        'total_amount',
        'status',
        'received_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function receptions()
    {
        return $this->hasMany(Reception::class);
    }
}
