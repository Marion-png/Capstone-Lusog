<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentHealthRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'student_name',
        'student_id',
        'school_name',
        'section',
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

    protected $casts = [
        'baseline_recorded_at' => 'date',
        'endline_recorded_at' => 'date',
        'is_at_risk' => 'boolean',
        'examination' => 'array',
        'attendance_by_month' => 'array',
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
