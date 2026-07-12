<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentHealthRecord extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'student_name',
        'student_id',
        'school_name',
        'section',
        'student_details',
        'weight',
        'bmi_value',
        'nutritional_status',
        'baseline_age',
        'baseline_height_cm',
        'baseline_weight_kg',
        'baseline_bmi_value',
        'baseline_nutritional_status',
        'baseline_recorded_at',
        'endline_age',
        'endline_height_cm',
        'endline_weight_kg',
        'endline_bmi_value',
        'endline_nutritional_status',
        'endline_recorded_at',
        'attendance_sessions_count',
        'is_at_risk',
        'examination',
        'attendance_by_month',
    ];

    /**
     * Personal and health data is encrypted at rest (AES-256 via APP_KEY).
     * Columns used in SQL predicates (student_id, school_name, section,
     * institution_id, is_at_risk, attendance_sessions_count) stay plain —
     * never move an encrypted column into a WHERE/ORDER BY/GROUP BY.
     */
    protected $casts = [
        'student_name' => EncryptedString::class,
        'weight' => EncryptedString::class,
        'bmi_value' => EncryptedString::class,
        'nutritional_status' => EncryptedString::class,
        'baseline_age' => EncryptedString::class,
        'baseline_height_cm' => EncryptedString::class,
        'baseline_weight_kg' => EncryptedString::class,
        'baseline_bmi_value' => EncryptedString::class,
        'baseline_nutritional_status' => EncryptedString::class,
        'endline_age' => EncryptedString::class,
        'endline_height_cm' => EncryptedString::class,
        'endline_weight_kg' => EncryptedString::class,
        'endline_bmi_value' => EncryptedString::class,
        'endline_nutritional_status' => EncryptedString::class,
        'baseline_recorded_at' => 'date',
        'endline_recorded_at' => 'date',
        'is_at_risk' => 'boolean',
        'examination' => EncryptedArray::class,
        'attendance_by_month' => EncryptedArray::class,
        'student_details' => EncryptedArray::class,
    ];

    /**
     * Restrict the query to the logged-in user's school. Records are never
     * shared across institutions; sessions without a school (legacy/system
     * admin) are left unfiltered.
     */
    public function scopeForActiveInstitution(Builder $query): Builder
    {
        $institutionId = session('active_institution_id');

        return $institutionId ? $query->where('institution_id', $institutionId) : $query;
    }

    public function healthConditions(): HasMany
    {
        return $this->hasMany(StudentHealthCondition::class);
    }

    public function consentForms(): HasMany
    {
        return $this->hasMany(ParentalConsentForm::class);
    }
}
