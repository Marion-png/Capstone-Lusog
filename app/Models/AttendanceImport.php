<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per uploaded feeding-attendance sheet. Records who uploaded what and
 * the match/row-error outcome, and — because a row exists per institution +
 * school year — answers "has attendance been uploaded for this period?" which
 * gates the rest of the coordinator workflow.
 *
 * institution_id / school_year stay plain (scoping + period gate); the
 * uploader name and row-error payload are encrypted at rest.
 */
class AttendanceImport extends Model
{
    use Auditable;

    protected $fillable = [
        'institution_id',
        'school_year',
        'uploaded_by_name',
        'original_filename',
        'stored_path',
        'sessions_count',
        'matched_count',
        'unmatched_count',
        'row_errors',
        'kind',
        'session_date',
        'unclear_count',
        'photo_purged_at',
    ];

    protected $casts = [
        'uploaded_by_name' => EncryptedString::class,
        'row_errors' => EncryptedArray::class,
        'sessions_count' => 'integer',
        'matched_count' => 'integer',
        'unmatched_count' => 'integer',
        'unclear_count' => 'integer',
        'session_date' => 'date',
        'photo_purged_at' => 'datetime',
    ];

    public const KIND_SPREADSHEET = 'spreadsheet';

    public const KIND_PHOTO = 'photo_scan';

    /** One session recorded by hand on the bulk attendance screen — no file. */
    public const KIND_MANUAL = 'manual_entry';

    /** Marks this batch produced that no human has confirmed yet. */
    public function pendingReviewCount(): int
    {
        return FeedingAttendance::query()
            ->where('attendance_import_id', $this->id)
            ->where('needs_review', true)
            ->count();
    }

    /**
     * Whether attendance has been uploaded for the given institution + period.
     * This is the gate the rest of the coordinator workflow depends on.
     */
    public static function existsForPeriod(?int $institutionId, ?string $schoolYear = null): bool
    {
        $schoolYear ??= StudentHealthRecord::currentSchoolYear();

        return static::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->where('school_year', $schoolYear)
            ->exists();
    }

    /** The most recent upload for the period (for the "uploaded on/by" status). */
    public static function latestForPeriod(?int $institutionId, ?string $schoolYear = null): ?self
    {
        $schoolYear ??= StudentHealthRecord::currentSchoolYear();

        return static::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->where('school_year', $schoolYear)
            ->latest('id')
            ->first();
    }
}
