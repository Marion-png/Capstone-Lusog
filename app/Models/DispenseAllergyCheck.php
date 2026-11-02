<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * The allergy check made on one dispense (App\Support\AllergyCheck), clear
 * results included, with the override and who made it. A blocked attempt is
 * recorded too, with no consultation, because nothing else was saved.
 */
class DispenseAllergyCheck extends Model
{
    use Auditable;

    protected $fillable = [
        'institution_id',
        'consultation_id',
        'medicine_id',
        'student_lrn',
        'result',
        'learner_medicine_allergy_id',
        'overridden',
        'override_reason',
        'checked_by_name',
        'checked_by_role',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'overridden' => 'boolean',
            'checked_at' => 'datetime',
            'override_reason' => EncryptedString::class,
            'checked_by_name' => EncryptedString::class,
        ];
    }
}
