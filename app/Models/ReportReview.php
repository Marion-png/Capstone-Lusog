<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One School Head decision on one report.
 *
 * The report itself is never stored — it is derived from the learners' records
 * every time it is read, so it cannot drift from the data it summarises. What
 * is stored is the decision: who approved, returned or locked it, when, and
 * with what remark.
 *
 * Locking is the end of the line. `isLocked()` is the one test every write path
 * consults, so a locked report cannot be re-approved, re-returned or re-locked
 * through the UI or by a hand-made request.
 */
class ReportReview extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_LOCKED = 'locked';

    /** Every state a report can be in, and the words the interface uses for them. */
    public const STATUSES = [
        self::STATUS_PENDING => 'Awaiting review',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_RETURNED => 'Returned for correction',
        self::STATUS_LOCKED => 'Locked',
    ];

    protected $fillable = [
        'institution_id',
        'school_year',
        'report_key',
        'status',
        'remarks',
        'reviewed_by_name',
        'reviewed_by_role',
        'reviewed_at',
        'locked_by_name',
        'locked_at',
    ];

    /**
     * The remark and the reviewer's name are personal information, so they are
     * encrypted at rest. The status, year and report key stay plain because
     * they are lookup keys.
     */
    protected $casts = [
        'remarks' => EncryptedString::class,
        'reviewed_by_name' => EncryptedString::class,
        'locked_by_name' => EncryptedString::class,
        'reviewed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUSES[$status] ?? self::STATUSES[self::STATUS_PENDING];
    }

    /** The shared status scale, so a report badge reads like every other badge. */
    public static function statusBadge(?string $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED => 'badge-normal',
            self::STATUS_RETURNED => 'badge-risk',
            self::STATUS_LOCKED => 'badge-info',
            default => 'badge-monitor',
        };
    }
}
