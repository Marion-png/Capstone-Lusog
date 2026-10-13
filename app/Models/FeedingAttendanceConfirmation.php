<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Feeding Coordinator's confirmation that one learner's attendance record is
 * correct.
 *
 * Two stages, one per step of the flow (see FeedingAttendanceFlag):
 *
 * - NOTICE — the coordinator verified the record when the early attendance
 *   notice came up. It acknowledges the notice and flags nobody.
 * - FLAG — the coordinator confirmed the record once the run reached the flag
 *   threshold. This, and nothing else, puts the learner on the follow-up list;
 *   the flag stays until a Resolved follow-up closes it.
 *
 * It never touches enrolment. The staff name and the remark are personal
 * information, encrypted at rest, and the model is Auditable so each
 * confirmation lands in the append-only trail with its field-level values.
 */
class FeedingAttendanceConfirmation extends Model
{
    use Auditable;

    public const STAGE_NOTICE = 'notice';

    public const STAGE_FLAG = 'flag';

    protected $fillable = [
        'student_health_record_id',
        'institution_id',
        'school_year',
        'stage',
        'run_started_on',
        'confirmed_through',
        'absences',
        'confirmed_by_name',
        'confirmed_by_role',
        'remarks',
    ];

    protected $casts = [
        'run_started_on' => 'date',
        'confirmed_through' => 'date',
        'absences' => 'integer',
        'confirmed_by_name' => EncryptedString::class,
        'remarks' => EncryptedString::class,
    ];

    public function studentHealthRecord(): BelongsTo
    {
        return $this->belongsTo(StudentHealthRecord::class);
    }
}
