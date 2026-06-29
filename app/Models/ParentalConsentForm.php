<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentalConsentForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_health_record_id',
        'program_type',
        'school_year',
        'consent_type',
        'partial_exception',
        'refused_reason',
        'allergy_food',
        'allergy_food_detail',
        'allergy_medicine',
        'allergy_medicine_detail',
        'prev_immunization',
        'prev_immunization_detail',
        'has_other_illness',
        'other_illness_detail',
        'medical_cert_attached',
        'file_path',
        'file_original_name',
        'uploaded_by_name',
    ];

    protected $casts = [
        'allergy_food'        => 'boolean',
        'allergy_medicine'    => 'boolean',
        'prev_immunization'   => 'boolean',
        'has_other_illness'   => 'boolean',
        'medical_cert_attached' => 'boolean',
    ];

    public function studentHealthRecord(): BelongsTo
    {
        return $this->belongsTo(StudentHealthRecord::class);
    }

    /**
     * Returns the current Philippine school year string (e.g. "2025-2026").
     * DepEd's academic year runs June–May, so months 6–12 belong to the SY
     * starting that calendar year; months 1–5 belong to the SY that started
     * the previous calendar year.
     */
    public static function currentSchoolYear(): string
    {
        $month = (int) now()->format('n');
        $year  = (int) now()->format('Y');
        return $month >= 6
            ? "{$year}-" . ($year + 1)
            : ($year - 1) . "-{$year}";
    }
}
