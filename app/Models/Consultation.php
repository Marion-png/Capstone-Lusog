<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consultation extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'consulted_at',
        'student_name',
        'grade_section',
        'condition',
        'condition_id',
        'treatment_given',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'consulted_at' => 'datetime',
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

