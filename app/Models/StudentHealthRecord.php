<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use App\Support\RequestMemo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentHealthRecord extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'school_year',
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
        'feeding_enrolled_at',
        'feeding_enrolled_by',
        'feeding_removed_at',
        'feeding_removed_by',
        'feeding_removal_reason',
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
        // Enrolment state is plain (queries filter on it); the staff name that
        // made the decision is personal data, so it is encrypted.
        'feeding_enrolled_at' => 'datetime',
        'feeding_enrolled_by' => EncryptedString::class,
        // Removal is a stamp beside the enrolment, never a deletion of it: a
        // learner who leaves the programme was still fed, and the record has
        // to keep saying so.
        'feeding_removed_at' => 'datetime',
        'feeding_removed_by' => EncryptedString::class,
        'feeding_removal_reason' => EncryptedString::class,
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

    public function consentForms(): HasMany
    {
        return $this->hasMany(ParentalConsentForm::class);
    }

    /**
     * The single canonical DepEd school-year string ("2026-2027"), delegated
     * to ParentalConsentForm so there is exactly one implementation of the
     * June-May formula in the codebase.
     */
    public static function currentSchoolYear(): string
    {
        return ParentalConsentForm::currentSchoolYear();
    }

    /**
     * Filters to rows for the given (or current) school year. school_year is
     * required with no NULL fallback, so "current" always means exactly one
     * thing — no stray row can silently double-match alongside a real one.
     */
    public function scopeForCurrentSchoolYear(Builder $query, ?string $schoolYear = null): Builder
    {
        return $query->where('school_year', $schoolYear ?? static::currentSchoolYear());
    }

    /**
     * The single reusable replacement for ad-hoc
     * `where('student_id', $lrn)->first()` reads across the app. Resolves
     * exactly one row per LRN even once a student has rows from multiple
     * school years (e.g. after grade promotion / re-encoding).
     */
    public static function currentForStudent(string $lrn, ?int $institutionId = null, ?string $schoolYear = null): ?self
    {
        return static::query()
            ->where('student_id', $lrn)
            ->when($institutionId, fn (Builder $q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear($schoolYear)
            ->latest('id')
            ->first();
    }

    /**
     * A school's learners for the current year, read once per request.
     *
     * The roster sync and the adviser dashboard both want this exact set, and
     * on a hosted database a second identical read is most of a second spent
     * for nothing. Unordered by design — callers that need an order sort the
     * collection, which is free.
     *
     * @return Collection<int, self>
     */
    public static function currentYearForInstitution(?int $institutionId)
    {
        return static::rosterFor($institutionId);
    }

    /**
     * A school's learners for one school year, read once per request.
     *
     * The same roster is wanted several times over on a single page — the
     * Feeding Coordinator's dashboard reads it once for its panels and again to
     * build the Record Attendance dialog, and the year filter usually names the
     * current year, so the two are the identical query. Every column worth
     * filtering on is encrypted, so each of those reads also re-hydrates and
     * re-decrypts the whole roll. Memoizing the read is the difference between
     * paying that once and paying it per caller.
     *
     * Callers treat the result as read-only; nothing here writes to a model.
     *
     * @return Collection<int, self>
     */
    public static function rosterFor(?int $institutionId, ?string $schoolYear = null)
    {
        $year = $schoolYear ?: static::currentSchoolYear();

        return RequestMemo::remember(
            'student_health_records:'.($institutionId ?? 'all').':'.$year,
            fn () => static::query()
                ->when($institutionId, fn (Builder $q, $id) => $q->where('institution_id', $id))
                ->forCurrentSchoolYear($year)
                ->get()
        );
    }
}
