<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use App\Support\SchemaCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An incident involving one learner, charted by the school nurse in FDAR.
 *
 * Focus / Data / Action / Response, in that order: the category is the focus,
 * the description the data, then what was done and how the learner responded.
 * Severity, location, witnesses and whether the guardian was told sit outside
 * the chart — they are not FDAR, but they are what makes a report findable and
 * followable afterwards.
 *
 * Keyed by the plain `student_lrn` + `institution_id` pair, like medical
 * certificates, so the profile can list a learner's reports without ever
 * naming an encrypted column in a WHERE. Everything a person wrote is
 * personal information about a child and is encrypted at rest.
 */
class StudentIncidentReport extends Model
{
    use Auditable;

    /**
     * Who files one, and who may read it.
     *
     * The report is charted in FDAR — a clinical documentation format — so the
     * **school nurse** writes it: assessing a learner and recording what was
     * done and how they responded is the nurse's work, not the adviser's. The
     * **class adviser reads it**, because an incident involving a learner in
     * their class is something they have to know about, and their profile is
     * where they would look.
     *
     * Declared here rather than on the controller so the endpoints and the two
     * profiles that render the panel read one list. A view that decides for
     * itself who may write is a view that eventually disagrees with the
     * endpoint, and the disagreement always favours drawing a control that
     * then 403s.
     *
     * @var list<string>
     */
    public const WRITE_ROLES = ['school_nurse'];

    /** @var list<string> */
    public const READ_ROLES = ['school_nurse', 'class_adviser'];

    public static function canFile(?string $role): bool
    {
        return in_array((string) $role, self::WRITE_ROLES, true);
    }

    public static function canView(?string $role): bool
    {
        return in_array((string) $role, self::READ_ROLES, true);
    }

    /**
     * Whether the Response column is on this database yet.
     *
     * Guarded rather than assumed: a machine that has pulled this code but not
     * yet run the migration must chart F, D and A rather than 500 on save. The
     * form drops its R section and the controller drops the field, so the
     * report still files — an incomplete chart being better than none.
     */
    public static function supportsResponse(): bool
    {
        return SchemaCache::hasColumn('student_incident_reports', 'response');
    }

    /**
     * Whether the F-DAR sheet's own columns are on this database yet: the
     * time of the incident and the nurse's focus statement. Guarded the same
     * way as the Response — an un-migrated machine charts without them rather
     * than 500ing on save.
     */
    public static function supportsChartColumns(): bool
    {
        return SchemaCache::hasColumn('student_incident_reports', 'occurred_time')
            && SchemaCache::hasColumn('student_incident_reports', 'focus');
    }

    /**
     * What kind of incident. A fixed catalogue rather than free text: the
     * list is filtered by it, and a report filed under a category nobody
     * recognises is a report nobody finds. It is the kind; the F-DAR Focus
     * printed on the sheet is the nurse's own `focus` statement, with this as
     * the fallback where none was written.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'injury' => 'Injury / Accident',
        'illness' => 'Sudden illness',
        'behavioural' => 'Behavioural',
        'bullying' => 'Bullying / Harassment',
        'property' => 'Property damage or loss',
        'absence' => 'Unexplained absence',
        'other' => 'Other',
    ];

    /**
     * How serious, in the adviser's judgement. Deliberately three, not five:
     * a scale nobody can tell the middle of gets used as a coin toss.
     *
     * @var array<string, string>
     */
    public const SEVERITIES = [
        'minor' => 'Minor',
        'moderate' => 'Moderate',
        'serious' => 'Serious',
    ];

    protected $fillable = [
        'institution_id',
        'student_lrn',
        'school_year',
        'occurred_at',
        'occurred_time',
        'category',
        'severity',
        'focus',
        'location',
        'description',
        'action_taken',
        'response',
        'witnesses',
        'reported_by_name',
        'reported_by_role',
        'guardian_notified',
    ];

    protected $casts = [
        'occurred_at' => 'date',
        'guardian_notified' => 'boolean',
        'focus' => EncryptedString::class,
        'location' => EncryptedString::class,
        'description' => EncryptedString::class,
        'action_taken' => EncryptedString::class,
        'response' => EncryptedString::class,
        'witnesses' => EncryptedString::class,
        'reported_by_name' => EncryptedString::class,
    ];

    /** @param  Builder<self>  $query */
    public function scopeForLearner(Builder $query, string $lrn, mixed $institutionId): Builder
    {
        return $query
            ->where('student_lrn', $lrn)
            ->where('institution_id', $institutionId);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Other';
    }

    /**
     * The Focus as the F-DAR sheet prints it: the nurse's own statement, or
     * the kind of incident where none was written.
     */
    public function focusLabel(): string
    {
        $focus = self::supportsChartColumns() ? trim((string) $this->focus) : '';

        return $focus !== '' ? $focus : $this->categoryLabel();
    }

    /** "10:20 AM", or '' where no time was charted. */
    public function occurredTimeLabel(): string
    {
        if (! self::supportsChartColumns() || ! $this->occurred_time) {
            return '';
        }

        try {
            return Carbon::parse((string) $this->occurred_time)->format('g:i A');
        } catch (\Throwable) {
            return '';
        }
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? 'Minor';
    }
}
