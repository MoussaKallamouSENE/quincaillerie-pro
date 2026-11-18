<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paiement manuel Wave ou Orange Money expose aux responsables.
 *
 * @mixin Payment
 */
class ManualPaymentResource extends JsonResource
{
    /**
     * Transformer la resource en tableau.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sale = $this->sale;

        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'sale_reference' => $sale?->reference,
            'customer_name' => $sale?->customer?->name,
            'seller_name' => $sale?->user?->name,
            'amount' => $this->amount,
            'method' => $this->method,
            'reference' => $this->reference,
            'status' => $this->status,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confirmed_by_id' => $this->confirmed_by_id,
            'confirmed_by_name' => $this->relationLoaded('confirmedBy') ? $this->confirmedBy?->name : null,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejected_by_id' => $this->rejected_by_id,
            'rejected_by_name' => $this->relationLoaded('rejectedBy') ? $this->rejectedBy?->name : null,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'sale' => $sale === null ? null : [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'total_amount' => $sale->total_amount,
                'paid_amount' => $this->money($sale->paidAmount()),
                'pending_amount' => $this->money($sale->pendingAmount()),
                'available_due' => $this->money($sale->availableAmount()),
                'balance_due' => $this->money($sale->balanceDue()),
                'payment_status' => $sale->payment_status,
            ],
        ];
    }

    /**
     * Formate un montant avec deux decimales, comme les colonnes `decimal`.
     */
    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
