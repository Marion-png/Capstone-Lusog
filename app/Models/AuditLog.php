<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Support\AuditSeal;
use App\Support\SchemaCache;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only forensic audit trail entry. Never updated or deleted by
 * application code — evidence must stay immutable.
 *
 * Immutability is enforced three times over: here (the model refuses to save
 * an existing entry or delete one), in the database (triggers refuse UPDATE,
 * DELETE and TRUNCATE on the table — a mass query bypasses model events but
 * not those), and after the fact (each entry is sealed with an HMAC on
 * creation; App\Support\AuditSeal reports a row whose contents no longer match
 * its seal).
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

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log): void {
            // Whole seconds: the seal covers the timestamp to the second, and
            // a column that rounded a fraction up would read back as a
            // different second than the one that was sealed.
            $log->created_at = ($log->created_at ?? now())->copy()->startOfSecond();

            if (SchemaCache::hasColumn('audit_logs', 'row_hash')) {
                $log->row_hash = AuditSeal::compute($log);
            }
        });

        static::updating(function (): never {
            throw new LogicException('Audit entries are append-only and cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit entries are append-only and cannot be deleted.');
        });
    }

    public function sealStatus(): string
    {
        return AuditSeal::status($this);
    }
}
