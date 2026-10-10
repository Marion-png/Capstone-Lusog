<?php

namespace App\Support;

use App\Models\AuditLog;

/**
 * A keyed seal on each audit entry, so an altered entry can be told apart
 * from an honest one.
 *
 * The database already refuses UPDATE, DELETE and TRUNCATE on `audit_logs`
 * (migration 2026_10_10_000002), but a trigger can be dropped by whoever owns
 * the database. The seal is the second lock: an HMAC-SHA256 over every field
 * of the entry, keyed from APP_KEY, written when the entry is created. Anyone
 * who edits a row without the key cannot produce a matching seal, and
 * `php artisan audit:verify` (or the System Admin's audit screen) reports the
 * row as altered.
 *
 * It is computed over the *decrypted* details, read through the model's own
 * cast, because the ciphertext is re-randomised on every encryption and the
 * plaintext is what the entry says. The same function seals at write time and
 * checks at read time, so the two cannot disagree about what a row contains.
 *
 * Limits, stated rather than hidden: rows written before sealing existed are
 * reported `unsealed`, not trusted; and rotating APP_KEY without re-sealing
 * makes every seal read as altered — which is the correct answer for a key
 * nobody should be rotating without re-encrypting the data it protects.
 */
final class AuditSeal
{
    public const SEALED = 'sealed';

    public const ALTERED = 'altered';

    public const UNSEALED = 'unsealed';

    /** Every column an entry is made of, except its id and the seal itself. */
    private const FIELDS = [
        'actor_name', 'actor_username', 'actor_role', 'institution_id',
        'action', 'subject_type', 'subject_id', 'description',
        'http_method', 'url', 'route_name', 'ip_address',
    ];

    public static function compute(AuditLog $log): string
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $log->getAttribute($field);
            $values[$field] = $value === null ? null : (string) $value;
        }

        // The details as they will read back: JSON round-tripped exactly as
        // the encrypted cast stores them.
        $details = $log->getAttribute('details');
        $values['details'] = $details === null ? null : json_decode(json_encode($details), true);
        $values['created_at'] = $log->created_at?->format('Y-m-d H:i:s');

        return hash_hmac('sha256', json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::key());
    }

    public static function status(AuditLog $log): string
    {
        $stored = (string) ($log->getAttribute('row_hash') ?? '');

        if ($stored === '') {
            return self::UNSEALED;
        }

        return hash_equals($stored, self::compute($log)) ? self::SEALED : self::ALTERED;
    }

    private static function key(): string
    {
        return hash_hmac('sha256', 'lusog-audit-seal', (string) config('app.key'));
    }
}
