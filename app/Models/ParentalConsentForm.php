<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Casts\EncryptedBoolean;
use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use App\Support\WorkingSchoolYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentalConsentForm extends Model
{
    use Auditable;
    use HasFactory;

    /**
     * Which signed form a scan is. Two forms come back from parents and they
     * answer different questions, so they must never be read as each other:
     * a parent agreeing to the feeding programme has not agreed to deworming
     * or an injection, and the reverse.
     *
     * The health-services letter (the Sulat-Pahibalo) is stored under the name
     * it had when deworming was its only service.
     */
    public const PROGRAM_HEALTH_SERVICES = 'Deworming';

    /** The parent's consent to the School-Based Feeding Program. */
    public const PROGRAM_FEEDING = 'SBFP';

    /** @var array<string, string> */
    public const PROGRAMS = [
        self::PROGRAM_HEALTH_SERVICES => 'Health services consent (Sulat-Pahibalo)',
        self::PROGRAM_FEEDING => 'School-Based Feeding Program consent',
    ];

    protected $fillable = [
        'student_health_record_id',
        'program_type',
        'school_year',
        'services',
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
        'med_cert_path',
        'med_cert_original_name',
        'notes',
        'file_path',
        'file_original_name',
        'uploaded_by_name',
        'scan_result',
    ];

    /** Where the files sit on disk is the server's business; pages link by id. */
    protected $hidden = ['file_path', 'med_cert_path'];

    /**
     * Consent decisions and health details are encrypted at rest. Only
     * program_type / school_year / student_health_record_id remain plain
     * because queries filter on them.
     */
    protected $casts = [
<<<<<<< Updated upstream
        // The services the letter lists, as ticked off the paper. NULL on a
        // row filed before they were recorded: it covers its program alone.
        'services' => EncryptedArray::class,
=======
        'scan_result' => EncryptedArray::class,
>>>>>>> Stashed changes
        'consent_type' => EncryptedString::class,
        'partial_exception' => EncryptedString::class,
        'refused_reason' => EncryptedString::class,
        'allergy_food_detail' => EncryptedString::class,
        'allergy_medicine_detail' => EncryptedString::class,
        'prev_immunization_detail' => EncryptedString::class,
        'other_illness_detail' => EncryptedString::class,
        'file_original_name' => EncryptedString::class,
        'med_cert_original_name' => EncryptedString::class,
        'notes' => EncryptedString::class,
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

    /** Scans of the health-services letter only. */
    public function scopeHealthServices(Builder $query): Builder
    {
        return $query->where('program_type', self::PROGRAM_HEALTH_SERVICES);
    }

    /** Scans of the parent's feeding-programme consent only. */
    public function scopeFeeding(Builder $query): Builder
    {
        return $query->where('program_type', self::PROGRAM_FEEDING);
    }

    /**
     * The school year the application is working in — the one every other
     * model's currentSchoolYear() delegates to. The calendar's, unless the
     * signed-in teacher has set their Teaching Assignment to another year
     * for testing (WorkingSchoolYear).
     */
    public static function currentSchoolYear(): string
    {
        return WorkingSchoolYear::chosen() ?? self::calendarSchoolYear();
    }

    /**
     * Returns the Philippine school year the calendar is in (e.g. "2025-2026").
     * DepEd's academic year runs June–May, so months 6–12 belong to the SY
     * starting that calendar year; months 1–5 belong to the SY that started
     * the previous calendar year.
     */
    public static function calendarSchoolYear(): string
    {
        $month = (int) now()->format('n');
        $year = (int) now()->format('Y');

        return $month >= 6
            ? "{$year}-".($year + 1)
            : ($year - 1)."-{$year}";
    }
}
