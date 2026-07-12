<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only forensic audit trail entry. Never updated or deleted by
 * application code — evidence must stay immutable.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * The payload may contain the sensitive values that were viewed or
     * changed, so it is encrypted at rest like the data it describes.
     */
    protected $casts = [
        'details' => EncryptedArray::class,
        'created_at' => 'datetime',
    ];
}
