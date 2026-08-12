<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One issue of medicine from the clinic stock to a learner.
 *
 * Recording a dispense is the only thing in the system that draws stock
 * down, so MedicineDispenseController writes this row and the decrement
 * together in a transaction.
 */
class MedicineDispense extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'medicine_id',
        'student_lrn',
        'student_name',
        'reason',
        'quantity',
        'dispensed_by_name',
        'dispensed_by_role',
        'dispensed_at',
    ];

    /**
     * Who the medicine went to, and why, is sensitive personal information
     * and is encrypted at rest. Anything that needs to be searched, sorted
     * or summed — the LRN, the quantity, the school — stays plain.
     */
    protected function casts(): array
    {
        return [
            'dispensed_at' => 'datetime',
            'quantity' => 'integer',
            'student_name' => EncryptedString::class,
            'reason' => EncryptedString::class,
            'dispensed_by_name' => EncryptedString::class,
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
