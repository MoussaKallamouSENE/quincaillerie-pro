<?php

namespace App\Http\Resources;

use App\Models\RefundDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RefundDocument
 */
class RefundDocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'refund_id' => $this->refund_id,
            'kind' => $this->kind,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'checksum' => $this->checksum,
            'uploaded_by_id' => $this->uploaded_by_id,
            'uploaded_by_name' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
