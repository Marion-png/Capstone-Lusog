<?php

namespace App\Models\Concerns;

use App\Support\AuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit trail entry for every create, update, and delete on the
 * model. Old/new values go into the encrypted `details` payload so a
 * forensic investigation can see exactly what changed, without the audit
 * table itself leaking the sensitive data in plaintext.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            AuditTrail::record(
                'created',
                class_basename($model),
                $model->getKey(),
                'Created '.class_basename($model).' #'.$model->getKey(),
            );
        });

        static::updated(function (Model $model): void {
            $changes = [];
            foreach (array_keys($model->getChanges()) as $field) {
                if (in_array($field, ['created_at', 'updated_at'], true)) {
                    continue;
                }
                $changes[$field] = [
                    'from' => $model->getOriginal($field),
                    'to' => $model->getAttribute($field),
                ];
            }

            if ($changes === []) {
                return;
            }

            AuditTrail::record(
                'updated',
                class_basename($model),
                $model->getKey(),
                'Updated '.class_basename($model).' #'.$model->getKey().' (fields: '.implode(', ', array_keys($changes)).')',
                ['changes' => $changes],
            );
        });

        static::deleted(function (Model $model): void {
            AuditTrail::record(
                'deleted',
                class_basename($model),
                $model->getKey(),
                'Deleted '.class_basename($model).' #'.$model->getKey(),
                ['snapshot' => $model->attributesToArray()],
            );
        });
    }
}
