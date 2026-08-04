<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A clinic observation the School Nurse / Clinic Staff records against a
 * learner — separate from a Consultation (a clinic visit with a diagnosis)
 * and from the adviser's health assessment.
 */
class ClinicNote extends Model
{
    use Auditable;
    use HasFactory;
    use TenantConnection;

    /** Roles allowed to read and write clinic notes. */
    public const CLINIC_ROLES = ['school_nurse', 'clinic_staff'];

    protected $fillable = [
        'institution_id',
        'student_lrn',
        'school_year',
        'note',
        'author_name',
        'follow_up_date',
    ];

    /**
     * The observation and its author are sensitive personal information and
     * are encrypted at rest. student_lrn / school_year / institution_id stay
     * plain — every query filters on them.
     */
    protected $casts = [
        'note' => EncryptedString::class,
        'author_name' => EncryptedString::class,
        'follow_up_date' => 'date',
    ];

    /**
     * Restrict to the viewer's school. Notes are never shared across
     * institutions; a session without a school sees none.
     */
    public function scopeForActiveInstitution(Builder $query): Builder
    {
        $institutionId = session('active_institution_id');

        return $institutionId ? $query->where('institution_id', $institutionId) : $query->whereRaw('1 = 0');
    }

    public static function currentSchoolYear(): string
    {
        return ParentalConsentForm::currentSchoolYear();
    }

    public static function canManage(?string $role): bool
    {
        return in_array($role, self::CLINIC_ROLES, true);
    }
}
