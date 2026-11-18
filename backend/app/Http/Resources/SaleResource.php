<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation publique d'une vente.
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
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
            'reference' => $this->reference,
            'idempotency_warning' => $this->when(
                $this->resource->getAttribute('idempotency_warning') !== null,
                fn () => $this->resource->getAttribute('idempotency_warning'),
            ),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'cash_session_id' => $this->cash_session_id,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->money($this->paidAmount()),
            'pending_amount' => $this->money($this->pendingAmount()),
            'available_due' => $this->money($this->availableAmount()),
            'balance_due' => $this->money($this->balanceDue()),
            'payment_status' => $this->payment_status,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (SaleItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'status' => $payment->status,
                'cash_session_id' => $payment->cash_session_id,
                'confirmed_at' => $payment->confirmed_at?->toIso8601String(),
                'confirmed_by_id' => $payment->confirmed_by_id,
                'confirmed_by_name' => $payment->relationLoaded('confirmedBy') ? $payment->confirmedBy?->name : null,
                'rejected_at' => $payment->rejected_at?->toIso8601String(),
                'rejected_by_id' => $payment->rejected_by_id,
                'rejected_by_name' => $payment->relationLoaded('rejectedBy') ? $payment->rejectedBy?->name : null,
                'rejection_reason' => $payment->rejection_reason,
                'created_at' => $payment->created_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
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
