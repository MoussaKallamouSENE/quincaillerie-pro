<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundDocument extends Model
{
    public const KIND_EXECUTION_PROOF = 'execution_proof';

    public const KIND_NO_TRANSFER_PROOF = 'no_transfer_proof';

    public const KIND_RECONCILIATION_REPORT = 'reconciliation_report';

    public const KINDS = [
        self::KIND_EXECUTION_PROOF,
        self::KIND_NO_TRANSFER_PROOF,
        self::KIND_RECONCILIATION_REPORT,
    ];

    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    protected $attributes = [
        'kind' => self::KIND_EXECUTION_PROOF,
        'disk' => 'local',
    ];

    protected $hidden = [
        'disk',
        'path',
    ];

    protected $fillable = [
        'refund_id',
        'uploaded_by_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'checksum',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
