<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consultation extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'consulted_at',
        'student_name',
        'grade_section',
        'condition',
        'condition_id',
        'treatment_given',
        'notes',
        'notes_shared_with_adviser',
        'status',
    ];

    /**
     * Student identity and medical details are encrypted at rest. Aggregations
     * over these columns (top conditions, distinct students) must run in PHP
     * after decryption, never in SQL.
     */
    protected function casts(): array
    {
        return [
            'consulted_at' => 'datetime',
            'student_name' => EncryptedString::class,
            'grade_section' => EncryptedString::class,
            'condition' => EncryptedString::class,
            'treatment_given' => EncryptedString::class,
            'notes' => EncryptedString::class,
            // A flag, not a fact about the child: plain, and queryable.
            'notes_shared_with_adviser' => 'boolean',
        ];
    }

    /**
     * Get the condition associated with this consultation.
     */
    public function conditionRecord(): BelongsTo
    {
        return $this->belongsTo(Condition::class, 'condition_id');
    }
}
