<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representation publique d'une depense de caisse.
 *
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
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
            'cash_session_id' => $this->cash_session_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            // Montant de la depense, au format des colonnes `decimal`.
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'date' => $this->date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
