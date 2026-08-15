<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded follow-up on an at-risk feeding beneficiary.
 *
 * The coordinator's operational note — who was contacted, on what day, what was
 * done, and where the concern stands. It is not a health record and carries no
 * clinical judgement: a coordinator records the follow-up, never a diagnosis.
 *
 * Every free-text column holds information about a named child and is therefore
 * encrypted at rest. The model is Auditable, so each entry lands in the
 * append-only audit trail with its field-level values.
 */
class FeedingFollowUp extends Model
{
    use Auditable, HasFactory;

    /**
     * The lifecycle a concern moves through. It runs alongside enrolment and
     * never touches it: RESOLVED means the attendance concern has been addressed
     * or the learner is back above the threshold — it never un-enrols anybody.
     */
    public const STATUS_AT_RISK = 'at_risk';

    public const STATUS_FOLLOW_UP_REQUIRED = 'follow_up_required';

    public const STATUS_MONITORING = 'monitoring';

    public const STATUS_RESOLVED = 'resolved';

    /** @var array<string, string> status => label, in lifecycle order */
    public const STATUSES = [
        self::STATUS_AT_RISK => 'At Risk',
        self::STATUS_FOLLOW_UP_REQUIRED => 'Follow-Up Required',
        self::STATUS_MONITORING => 'Monitoring',
        self::STATUS_RESOLVED => 'Resolved',
    ];

    protected $fillable = [
        'student_health_record_id',
        'institution_id',
        'school_year',
        'followed_up_on',
        'status',
        'reason',
        'action_taken',
        'person_contacted',
        'remarks',
        'recorded_by_name',
        'recorded_by_role',
    ];

    protected $casts = [
        'followed_up_on' => 'date',
        // Everything a coordinator typed about a named child, plus the staff
        // name that recorded it: personal information, encrypted at rest.
        'reason' => EncryptedString::class,
        'action_taken' => EncryptedString::class,
        'person_contacted' => EncryptedString::class,
        'remarks' => EncryptedString::class,
        'recorded_by_name' => EncryptedString::class,
    ];

    public function studentHealthRecord(): BelongsTo
    {
        return $this->belongsTo(StudentHealthRecord::class);
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUSES[(string) $status] ?? 'At Risk';
    }

    /** The status scale's badge class, so a status reads the same wherever it appears. */
    public static function statusBadge(?string $status): string
    {
        return match ((string) $status) {
            self::STATUS_RESOLVED => 'badge-normal',
            self::STATUS_MONITORING => 'badge-info',
            self::STATUS_FOLLOW_UP_REQUIRED => 'badge-monitor',
            self::STATUS_AT_RISK => 'badge-risk',
            default => 'badge-neutral',
        };
    }
}
