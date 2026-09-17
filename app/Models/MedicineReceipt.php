<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery of medicine into the clinic stock — the logbook's "in" column.
 *
 * Recording a receipt is the only thing in the system that raises a stock
 * level after the item was created, so MedicineInventoryController::receive
 * writes this row and the increment together in one transaction, the mirror
 * of how a dispense and its decrement are written.
 */
class MedicineReceipt extends Model
{
    use Auditable;

    protected $fillable = [
        'institution_id',
        'medicine_id',
        'quantity',
        'expiry_date',
        'received_at',
        'source',
        'notes',
        'received_by_name',
        'received_by_role',
    ];

    /**
     * Who signed for the delivery is a staff name and is encrypted at rest.
     * Everything the log is filtered, sorted or summed on stays plain.
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expiry_date' => 'date',
            'received_at' => 'date',
            'received_by_name' => EncryptedString::class,
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /** Restrict to the school the session is scoped to. */
    public function scopeForInstitution(Builder $query, int|string|null $institutionId): Builder
    {
        return $query->when($institutionId, fn (Builder $q) => $q->where('institution_id', $institutionId));
    }
}
