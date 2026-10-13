<?php

namespace Tests\Concerns;

use App\Models\FeedingAttendanceConfirmation;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAttendanceFlag;

/**
 * The Feeding Coordinator's confirmation, for tests that need a flagged learner.
 *
 * Reaching the threshold never flags anybody on its own — the coordinator has
 * to confirm the record first (FeedingAttendanceFlag). A test about what a
 * screen does with a flagged learner records that confirmation here, exactly
 * as the At-Risk Students tab's confirm endpoint would, then rewrites the
 * stored flags the clinic dashboards count.
 */
trait ConfirmsFeedingFlags
{
    protected function confirmFlag(StudentHealthRecord $record, int $absences = 7): FeedingAttendanceConfirmation
    {
        $confirmation = FeedingAttendanceConfirmation::create([
            'student_health_record_id' => $record->id,
            'institution_id' => $record->institution_id,
            'school_year' => (string) $record->school_year,
            'stage' => FeedingAttendanceConfirmation::STAGE_FLAG,
            'absences' => $absences,
            'confirmed_by_name' => 'Test Coordinator',
            'confirmed_by_role' => 'feeding_coor',
        ]);

        FeedingAttendanceFlag::refreshStoredFlags($record->institution_id ? (int) $record->institution_id : null);

        return $confirmation;
    }
}
