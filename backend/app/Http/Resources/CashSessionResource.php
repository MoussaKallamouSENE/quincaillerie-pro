<?php

namespace App\Http\Resources;

use App\Models\CashSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation publique d'une session de caisse.
 *
 * @mixin CashSession
 */
class CashSessionResource extends JsonResource
{
    /**
     * Transformer la resource en tableau.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'opening_balance' => $this->opening_balance,
            'closing_balance' => $this->closing_balance,
            'status' => $this->status,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            // Ventes encaisseees en especes pendant la session.
            'cash_received' => $this->money($this->cashReceived()),
            // Depenses payees en especes pendant la session.
            'expenses_total' => $this->money($this->expensesPaid()),
            // Solde theorique du tiroir : fond de caisse + especes - depenses.
            // Fige a la cloture pour une session close : une depense
            // enregistree apres coup ne modifie plus l'historique de caisse.
            'expected_cash' => $this->money($this->expectedCash()),
            // Ecart entre le comptage et le solde theorique : positif =
            // excedent, negatif = manque de cash.
            'difference' => $this->moneyOrNull($this->difference()),
            // Ecart entre le comptage et le seul fond de caisse initial.
            'opening_difference' => $this->moneyOrNull($this->openingDifference()),
        ];
    }

    /**
     * Formate un montant avec deux decimales, comme les colonnes `decimal`.
     */
    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Formate un montant facultatif.
     */
    private function moneyOrNull(?float $amount): ?string
    {
        return $amount === null ? null : $this->money($amount);
    }
}
