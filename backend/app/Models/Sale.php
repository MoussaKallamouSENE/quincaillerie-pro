<?php

namespace App\Models;

use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $fillable = ['customer_id', 'user_id', 'cash_session_id', 'reference', 'idempotency_key', 'idempotency_fingerprint', 'total_amount', 'payment_status'];

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

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function confirmedPayments(): HasMany
    {
        return $this->hasMany(Payment::class)->where('status', Payment::STATUS_CONFIRMED);
    }

    public function pendingPayments(): HasMany
    {
        return $this->hasMany(Payment::class)->where('status', Payment::STATUS_PENDING);
    }

    /**
     * Montant deja regle par les paiements confirmes.
     *
     * Les paiements Wave et Orange Money en attente de verification sont
     * volontairement exclus : ils ne constituent pas un encaissement tant
     * qu'un responsable ne les a pas valides.
     */
    public function paidAmount(): float
    {
        $paid = $this->relationLoaded('payments')
            ? $this->payments
                ->where('status', Payment::STATUS_CONFIRMED)
                ->sum('amount')
            : (float) $this->payments()->confirmed()->sum('amount');

        return round((float) $paid, 2);
    }

    /**
     * Montant des paiements manuels en attente de verification.
     */
    public function pendingAmount(): float
    {
        $pending = $this->relationLoaded('payments')
            ? $this->payments
                ->where('status', Payment::STATUS_PENDING)
                ->sum('amount')
            : (float) $this->payments()->pending()->sum('amount');

        return round((float) $pending, 2);
    }

    /**
     * Reste a encaisser sur la vente.
     */
    public function balanceDue(): float
    {
        return round((float) $this->total_amount - $this->paidAmount(), 2);
    }

    /**
     * Montant encore disponible pour un nouveau paiement.
     *
     * Les paiements en attente reservent leur montant : ils ne sont pas
     * comptes comme payes, mais un second encaissement ne peut pas les
     * depasser avant leur confirmation ou leur rejet.
     */
    public function availableAmount(): float
    {
        return round(
            max(0, (float) $this->total_amount - $this->paidAmount() - $this->pendingAmount()),
            2,
        );
    }

    /**
     * Recalcule le statut de reglement a partir des seuls paiements confirmes.
     */
    public function recalculatePaymentStatus(): self
    {
        $paid = $this->paidAmount();
        $due = round((float) $this->total_amount - $paid, 2);

        if ($due <= 0) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = $paid > 0 ? 'partial' : 'credit';
        }

        return $this;
    }
}
