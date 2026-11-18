<?php

namespace App\Http\Resources;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representation publique d'une demande de remboursement.
 *
 * @mixin Refund
 */
class RefundResource extends JsonResource
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
            'sale_id' => $this->sale_id,
            'sale_reference' => $this->whenLoaded('sale', fn () => $this->sale?->reference),
            'customer_name' => $this->whenLoaded('sale', fn () => $this->sale?->customer?->name),
            'seller_name' => $this->whenLoaded('sale', fn () => $this->sale?->user?->name),
            'payment_id' => $this->payment_id,
            'payment_method' => $this->whenLoaded('payment', fn () => $this->payment?->method),
            'payment_reference' => $this->whenLoaded('payment', fn () => $this->payment?->reference),
            'payment_status' => $this->whenLoaded('payment', fn () => $this->payment?->status),
            'amount' => $this->amount,
            'method' => $this->method,
            'transaction_reference' => $this->transaction_reference,
            'reason' => $this->reason,
            'status' => $this->status,
            'cumulative_amount' => $this->cumulative_amount,
            'blocked_reason' => $this->blocked_reason,
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'failure_reason' => $this->failure_reason,
            'reconciliation_reason' => $this->reconciliation_reason,
            'reconciliation_reference' => $this->reconciliation_reference,
            'requested_by_id' => $this->requested_by_id,
            'requested_by_name' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'approved_by_id' => $this->approved_by_id,
            'approved_by_name' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'rejected_by_id' => $this->rejected_by_id,
            'rejected_by_name' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy?->name),
            'executed_by_id' => $this->executed_by_id,
            'executed_by_name' => $this->whenLoaded('executedBy', fn () => $this->executedBy?->name),
            'cash_session_id' => $this->cash_session_id,
            'cash_movement_id' => $this->whenLoaded('cashMovement', fn () => $this->cashMovement?->id),
            'cancelled_by_id' => $this->cancelled_by_id,
            'cancelled_by_name' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy?->name),
            'reconciled_by_id' => $this->reconciled_by_id,
            'reconciled_by_name' => $this->whenLoaded('reconciledBy', fn () => $this->reconciledBy?->name),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'execution_started_at' => $this->execution_started_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'reconciliation_required_at' => $this->reconciliation_required_at?->toIso8601String(),
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'documents' => RefundDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
