<?php

namespace App\Models;

use App\Casts\EncryptedBoolean;
use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentalConsentForm extends Model
{
    use Auditable;
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

    /**
     * Consent decisions and health details are encrypted at rest. Only
     * program_type / school_year / student_health_record_id remain plain
     * because queries filter on them.
     */
    protected $casts = [
        'consent_type' => EncryptedString::class,
        'partial_exception' => EncryptedString::class,
        'refused_reason' => EncryptedString::class,
        'allergy_food_detail' => EncryptedString::class,
        'allergy_medicine_detail' => EncryptedString::class,
        'prev_immunization_detail' => EncryptedString::class,
        'other_illness_detail' => EncryptedString::class,
        'file_original_name' => EncryptedString::class,
        'allergy_food' => EncryptedBoolean::class,
        'allergy_medicine' => EncryptedBoolean::class,
        'prev_immunization' => EncryptedBoolean::class,
        'has_other_illness' => EncryptedBoolean::class,
        'medical_cert_attached' => EncryptedBoolean::class,
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
        $year = (int) now()->format('Y');

        return $month >= 6
            ? "{$year}-".($year + 1)
            : ($year - 1)."-{$year}";
    }
}
