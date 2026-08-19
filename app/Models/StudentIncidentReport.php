<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An incident involving one learner, filed by their class adviser.
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
     * What kind of incident. A fixed catalogue rather than free text: the
     * list is filtered by it, and a report filed under a category nobody
     * recognises is a report nobody finds.
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
        'category',
        'severity',
        'location',
        'description',
        'action_taken',
        'witnesses',
        'reported_by_name',
        'reported_by_role',
        'guardian_notified',
    ];

    protected $casts = [
        'occurred_at' => 'date',
        'guardian_notified' => 'boolean',
        'location' => EncryptedString::class,
        'description' => EncryptedString::class,
        'action_taken' => EncryptedString::class,
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

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? 'Minor';
    }
}
