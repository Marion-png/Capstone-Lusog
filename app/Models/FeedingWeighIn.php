<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One optional progress weigh-in of a feeding beneficiary, taken by the
 * Feeding Program Coordinator around feeding day 30, 60 or 90 (testers'
 * report #33). See App\Support\FeedingWeighIns.
 *
 * The figures and their classifications are a child's health data and the
 * coordinator's name is personal data, so all of them are encrypted at rest.
 * Only the keys the pages look up by — the learner row, the school, the
 * school year and the checkpoint — stay plain.
 */
class FeedingWeighIn extends Model
{
    use Auditable;

    protected $fillable = [
        'institution_id',
        'student_health_record_id',
        'school_year',
        'feeding_day',
        'weighed_on',
        'height_cm',
        'weight_kg',
        'bmi_value',
        'nutritional_status',
        'height_for_age',
        'recorded_by_name',
    ];

    protected $casts = [
        'feeding_day' => 'integer',
        'weighed_on' => 'date',
        'height_cm' => EncryptedString::class,
        'weight_kg' => EncryptedString::class,
        'bmi_value' => EncryptedString::class,
        'nutritional_status' => EncryptedString::class,
        'height_for_age' => EncryptedString::class,
        'recorded_by_name' => EncryptedString::class,
    ];

    public function studentHealthRecord(): BelongsTo
    {
        return $this->belongsTo(StudentHealthRecord::class);
    }
}
