<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Casts\EncryptedBoolean;
use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use App\Support\SchoolYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentalConsentForm extends Model
{
    use Auditable;
    use HasFactory;

    /**
     * The health-services letter (the Sulat-Pahibalo), stored under the name
     * it had when deworming was its only service. Every upload is filed as it.
     */
    public const PROGRAM_HEALTH_SERVICES = 'Deworming';

    /**
     * The parent's feeding-programme consent. Enrolment no longer needs one, so
     * nothing files it any more; rows filed while it did keep this name and are
     * never read as the health-services letter.
     */
    public const PROGRAM_FEEDING = 'SBFP';

    protected $fillable = [
        'student_health_record_id',
        'program_type',
        'school_year',
        'services',
        'excluded_services',
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
        'incomplete',
        'scan_review',
    ];

    /** Where the files sit on disk is the server's business; pages link by id. */
    protected $hidden = ['file_path', 'med_cert_path'];

    /**
     * Consent decisions and health details are encrypted at rest. Only
     * program_type / school_year / student_health_record_id remain plain
     * because queries filter on them.
     */
    protected $casts = [
        // The services the letter lists, as ticked off the paper. NULL on a
        // row filed before they were recorded: it covers its program alone.
        'services' => EncryptedArray::class,
        // The services a "yes, except" left out, as the adviser marked them on
        // the upload's confirmation. NULL when nobody has: the exceptions are
        // then only the parent's handwriting, and each service is checked.
        'excluded_services' => EncryptedArray::class,
        'scan_result' => EncryptedArray::class,
        // For each service and field, what the photo reader proposed and what
        // the adviser saved (ConsentReviewLog). NULL without a reading.
        'scan_review' => EncryptedArray::class,
        // Missing the parent's name or signature: filed, and gives no service
        // until a complete form is on file. NULL on older rows reads complete.
        'incomplete' => EncryptedBoolean::class,
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

    /**
     * The school year the application is working in — the one every other
     * model's currentSchoolYear() delegates to. The calendar's, unless the
     * signed-in teacher has set their Teaching Assignment to another year
     * for testing (WorkingSchoolYear). Decided by App\Support\SchoolYear.
     */
    public static function currentSchoolYear(): string
    {
        return SchoolYear::current();
    }

    /**
     * The Philippine school year the calendar is in (e.g. "2026-2027"), on
     * the school's clock (Asia/Manila): DepEd's year runs June–May.
     */
    public static function calendarSchoolYear(): string
    {
        return SchoolYear::calendar();
    }
}
