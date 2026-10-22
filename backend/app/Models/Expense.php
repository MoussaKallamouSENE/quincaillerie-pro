<?php

namespace App\Models;

use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Dépense de caisse.
 *
 * Une dépense est une sortie d'argent du tiroir : elle est rattachée à la
 * session de caisse qui l'a supportée et son montant est retranché du solde
 * théorique du tiroir (fond de caisse + ventes en espèces - dépenses).
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected $fillable = ['cash_session_id', 'user_id', 'name', 'amount', 'date'];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    /**
     * Session de caisse qui a supporté la dépense.
     */
    public function cashSession()
    {
        return $this->belongsTo(CashSession::class);
    }

    /**
     * Auteur de la dépense.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
