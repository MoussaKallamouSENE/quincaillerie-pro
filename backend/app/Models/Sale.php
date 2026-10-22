<?php

namespace App\Models;

use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $fillable = ['customer_id', 'user_id', 'cash_session_id', 'reference', 'total_amount', 'payment_status'];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cashSession()
    {
        return $this->belongsTo(CashSession::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Montant déjà réglé par les paiements rattachés à la vente.
     */
    public function paidAmount(): float
    {
        $paid = $this->relationLoaded('payments')
            ? (float) $this->payments->sum('amount')
            : (float) $this->payments()->sum('amount');

        return round($paid, 2);
    }

    /**
     * Reste à encaisser sur la vente.
     */
    public function balanceDue(): float
    {
        return round((float) $this->total_amount - $this->paidAmount(), 2);
    }
}
