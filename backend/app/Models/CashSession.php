<?php

namespace App\Models;

use Database\Factories\CashSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    /** @use HasFactory<CashSessionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'opening_balance', 'closing_balance', 'expected_cash', 'status', 'opened_at', 'closed_at'];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function movements()
    {
        return $this->hasMany(CashMovement::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Montant en espèces encaissé pendant la session.
     *
     * Seuls les paiements réglés en espèces alimentent le fond de caisse :
     * les paiements par Wave, Orange Money, virement ou chèque en sont
     * exclus, car ils n'entrent pas dans le tiroir.
     */
    public function cashReceived(): float
    {
        return (float) $this->sales()
            ->join('payments', 'payments.sale_id', '=', 'sales.id')
            ->where('payments.method', 'cash')
            ->sum('payments.amount');
    }

    /**
     * Montant des dépenses payées en espèces pendant la session.
     *
     * Ces sorties d'argent sont retranchees du solde theorique du tiroir.
     */
    public function expensesPaid(): float
    {
        return (float) $this->expenses()->sum('amount');
    }

    /**
     * Solde théorique du tiroir : fond de caisse + ventes en espèces - dépenses.
     *
     * Une session close conserve le solde fige au moment de sa clôture : le
     * theorique d'une session cloturee ne bouge plus, meme si une dépense est
     * enregistree apres coup.
     */
    public function expectedCash(): float
    {
        if ($this->closed_at !== null && $this->expected_cash !== null) {
            return (float) $this->expected_cash;
        }

        return $this->computeExpectedCash();
    }

    /**
     * Calcule le solde théorique du tiroir à l'instant présent.
     */
    public function computeExpectedCash(): float
    {
        return round(
            (float) $this->opening_balance + $this->cashReceived() - $this->expensesPaid(),
            2,
        );
    }

    /**
     * Écart entre le montant compté et le solde théorique du tiroir.
     *
     * Positif = excédent de caisse, négatif = manque d'argent.
     */
    public function difference(): ?float
    {
        if ($this->closing_balance === null) {
            return null;
        }

        return round((float) $this->closing_balance - $this->expectedCash(), 2);
    }

    /**
     * Écart entre le montant compté et le seul fond de caisse initial.
     */
    public function openingDifference(): ?float
    {
        if ($this->closing_balance === null) {
            return null;
        }

        return round((float) $this->closing_balance - (float) $this->opening_balance, 2);
    }
}
