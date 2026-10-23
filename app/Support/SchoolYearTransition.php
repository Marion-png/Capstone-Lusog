<?php

namespace App\Support;

use App\Models\SectionClosure;
use App\Models\StudentHealthRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The writes of the school-year handover. LearnerPlacement reads; this writes.
 *
 * Every write here changes where a learner stands and nothing else. A
 * learner's profile, measurements, documents, consents, incidents and clinic
 * history are never touched: they are filed under the LRN and the school
 * year, so they stay where they are whichever section the learner is in next
 * (rule 2, nothing is re-encoded). Every row written goes through the model,
 * so `Auditable` records each field that changed, and each action also leaves
 * one summary entry on the trail.
 */
final class SchoolYearTransition
{
    /**
     * The school year an adviser's section is closed for.
     *
     * Last school year's, while it still has learners nobody closed — a year
     * left open past the first of June is overdue, and it is the one the next
     * adviser is waiting on — otherwise this school year's.
     */
    public static function closableYear(int $institutionId, string $grade, string $section): string
    {
        $current = StudentHealthRecord::currentSchoolYear();
        $previous = self::previousSchoolYear($current);

        return self::openLearners($institutionId, $previous, $grade, $section)->isNotEmpty() ? $previous : $current;
    }

    public static function previousSchoolYear(string $schoolYear): string
    {
        [$start] = array_pad(explode('-', $schoolYear, 2), 1, '');

        return ((int) $start - 1).'-'.(int) $start;
    }

    public static function nextSchoolYear(string $schoolYear): string
    {
        [, $end] = array_pad(explode('-', $schoolYear, 2), 2, '');

        return (int) $end.'-'.((int) $end + 1);
    }

    /**
     * Rows of one section in one school year: the learners still in it
     * (open), or every learner who was placed in it.
     *
     * The section string is compared in PHP, without regard to case, because
     * a section is stored as typed and the account carries its own spelling.
     *
     * @return Collection<int, StudentHealthRecord>
     */
    public static function sectionRows(int $institutionId, string $schoolYear, string $grade, string $section): Collection
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            return collect();
        }

        return StudentHealthRecord::query()
            ->where('institution_id', $institutionId)
            ->where('school_year', $schoolYear)
            ->get()
            ->filter(fn (StudentHealthRecord $record): bool => LearnerPlacement::inSection($record, $grade, $section))
            ->values();
    }

    /** @return Collection<int, StudentHealthRecord> */
    public static function openLearners(int $institutionId, string $schoolYear, string $grade, string $section): Collection
    {
        return self::sectionRows($institutionId, $schoolYear, $grade, $section)
            ->filter(fn (StudentHealthRecord $record): bool => LearnerPlacement::isOpen($record))
            ->values();
    }

    public static function closureFor(int $institutionId, string $schoolYear, string $grade, string $section): ?SectionClosure
    {
        if (! SchemaCache::hasTable('section_closures')) {
            return null;
        }

        return SectionClosure::query()
            ->where('institution_id', $institutionId)
            ->where('school_year', $schoolYear)
            ->get()
            ->first(fn (SectionClosure $closure): bool => strcasecmp(trim($closure->grade_level), trim($grade)) === 0
                && strcasecmp(trim($closure->section), trim($section)) === 0);
    }

    /** Whether a section has been closed for this school year, so nobody can be added to it. */
    public static function isClosed(int $institutionId, string $schoolYear, string $grade, string $section): bool
    {
        return self::closureFor($institutionId, $schoolYear, $grade, $section) !== null;
    }

    /**
     * Close a section's school year: one status per learner, one transaction.
     *
     * Every learner still in the section must be given a status the grade
     * offers, or nothing is written. On success each placement is closed
     * (`left_at`, `end_status`), a Promoted or Retained learner waits for the
     * grade they will be placed in, everyone else is archived with a deletion
     * date five years out, and the section is closed. The adviser never picks
     * the next section or the next adviser.
     *
     * @param  array<string, string>  $statuses  LRN => end status
     * @return array{waiting: int, archived: int, closed_at: CarbonImmutable}
     */
    public static function closeYear(
        int $institutionId,
        string $schoolYear,
        string $grade,
        string $section,
        array $statuses,
        string $by,
    ): array {
        if (! LearnerPlacement::supported()) {
            throw new InvalidArgumentException('The database has not been updated for the school-year handover yet.');
        }

        $now = CarbonImmutable::now();
        $result = ['waiting' => 0, 'archived' => 0, 'closed_at' => $now];

        DB::transaction(function () use ($institutionId, $schoolYear, $grade, $section, $statuses, $by, $now, &$result): void {
            $learners = self::openLearners($institutionId, $schoolYear, $grade, $section);

            if ($learners->isEmpty()) {
                throw new InvalidArgumentException('There is nobody left in this section to close the year for.');
            }

            // Every status is checked before the first row is written.
            $chosen = [];
            foreach ($learners as $record) {
                $lrn = (string) $record->student_id;
                $status = (string) ($statuses[$lrn] ?? '');
                [$rowGrade] = LearnerPlacement::sectionOf($record);

                if (! in_array($status, LearnerPlacement::endStatusOptions($rowGrade !== '' ? $rowGrade : $grade), true)) {
                    throw new InvalidArgumentException('Choose an end-of-year status for every learner (LRN '.$lrn.').');
                }

                $chosen[$record->id] = $status;
            }

            foreach ($learners as $record) {
                self::applyEndStatus($record, $chosen[$record->id], $by, $now);
                $record->save();

                in_array($chosen[$record->id], LearnerPlacement::ARCHIVING_STATUSES, true)
                    ? $result['archived']++
                    : $result['waiting']++;
            }

            $closure = self::closureFor($institutionId, $schoolYear, $grade, $section) ?? new SectionClosure([
                'institution_id' => $institutionId,
                'school_year' => $schoolYear,
                'grade_level' => trim($grade),
                'section' => trim($section),
            ]);
            $closure->closed_at = $now;
            $closure->closed_by = $by;
            $closure->save();

            AuditTrail::record(
                'section_closed',
                'SectionClosure',
                (int) $closure->id,
                'Closed S.Y. '.$schoolYear.' for '.LearnerPlacement::sectionLabel($grade, $section)
                    .': '.$result['waiting'].' waiting, '.$result['archived'].' archived',
                ['school_year' => $schoolYear, 'waiting' => $result['waiting'], 'archived' => $result['archived']]
            );
        });

        LearnerPlacement::forget();

        return $result;
    }

    /**
     * Whether a closed learner's end-of-year status may still be corrected:
     * the section's year is closed, the learner left it, and they have not
     * been placed in a new section since. After that it is the school's.
     */
    public static function canCorrect(StudentHealthRecord $record): bool
    {
        if (! LearnerPlacement::supported() || LearnerPlacement::isOpen($record) || $record->left_at === null) {
            return false;
        }

        if (! in_array((string) $record->end_status, array_keys(LearnerPlacement::END_STATUS_LABELS), true)) {
            return false;
        }

        [$grade, $section] = LearnerPlacement::sectionOf($record);

        if (! self::isClosed((int) $record->institution_id, (string) $record->school_year, $grade, $section)) {
            return false;
        }

        $latest = LearnerPlacement::latest(LearnerPlacement::rowsForLearner((int) $record->institution_id, (string) $record->student_id));

        return $latest !== null && (int) $latest->id === (int) $record->id;
    }

    /** Correct a closed learner's end-of-year status. The caller checks canCorrect(). */
    public static function correctStatus(StudentHealthRecord $record, string $status, string $by): void
    {
        [$grade] = LearnerPlacement::sectionOf($record);

        if (! in_array($status, LearnerPlacement::endStatusOptions($grade), true)) {
            throw new InvalidArgumentException('That status is not offered for '.$grade.'.');
        }

        $previous = (string) $record->end_status;

        DB::transaction(function () use ($record, $status, $by, $previous): void {
            $leftAt = $record->left_at ? CarbonImmutable::instance($record->left_at) : CarbonImmutable::now();

            self::applyEndStatus($record, $status, $by, CarbonImmutable::now(), $leftAt);
            $record->save();

            AuditTrail::record(
                'end_status_corrected',
                'StudentHealthRecord',
                (int) $record->id,
                'Corrected the end-of-year status for LRN '.$record->student_id.' (S.Y. '.$record->school_year.')',
                ['from' => $previous, 'to' => $status]
            );
        });

        LearnerPlacement::forget();
    }

    /**
     * Mark a learner as having left the school mid-year: archived today, to be
     * deleted five years from today, the placement closed.
     */
    public static function markLeft(StudentHealthRecord $record, string $reason, string $by): void
    {
        if (! in_array($reason, LearnerPlacement::LEFT_SCHOOL_REASONS, true)) {
            throw new InvalidArgumentException('Choose why the learner left the school.');
        }

        if (! LearnerPlacement::supported() || ! LearnerPlacement::isOpen($record)) {
            throw new InvalidArgumentException('This learner is no longer in the section.');
        }

        DB::transaction(function () use ($record, $reason, $by): void {
            self::applyEndStatus($record, $reason, $by, CarbonImmutable::now());
            $record->save();

            AuditTrail::record(
                'learner_left_school',
                'StudentHealthRecord',
                (int) $record->id,
                'Marked LRN '.$record->student_id.' as left school (S.Y. '.$record->school_year.')',
                ['end_status' => $reason]
            );
        });

        LearnerPlacement::forget();
    }

    /**
     * Fill one placement's closing fields for a status. Nothing is saved here.
     *
     * The waiting and archive fields are each set or cleared, so applying a
     * corrected status leaves no trace of the one it replaced.
     */
    public static function applyEndStatus(
        StudentHealthRecord $record,
        string $status,
        string $by,
        CarbonImmutable $today,
        ?CarbonImmutable $leftAt = null,
    ): void {
        [$grade] = LearnerPlacement::sectionOf($record);

        $record->left_at = $leftAt ?? $today;
        $record->end_status = $status;

        if (in_array($status, LearnerPlacement::ARCHIVING_STATUSES, true)) {
            $record->wait_grade = null;
            $record->archived_at = $today;
            $record->archived_by = $by;
            $record->archive_reason = LearnerPlacement::endStatusLabel($status);
            $record->delete_after = LearnerPlacement::deleteAfter($today)->toDateString();

            return;
        }

        $record->wait_grade = $status === LearnerPlacement::PROMOTED
            ? (LearnerPlacement::nextGrade($grade) ?? $grade)
            : $grade;
        $record->archived_at = null;
        $record->archived_by = null;
        $record->archive_reason = null;
        $record->delete_after = null;
    }

    /**
     * Restore a learner archived this school year to a section, on the same
     * row: the placement is reopened and the deletion cancelled. The section
     * is the restoring adviser's, which may not be the one the learner left.
     */
    public static function restoreInPlace(StudentHealthRecord $record, string $grade, string $section, ?array $name = null): void
    {
        $details = is_array($record->student_details) ? $record->student_details : [];
        $details['grade_level'] = trim($grade);
        $details['section'] = trim($section);

        if ($name !== null) {
            $details['last_name'] = $name['last_name'];
            $details['first_name'] = $name['first_name'];
            $details['middle_name'] = $name['middle_name'] !== '' ? $name['middle_name'] : null;
            $record->student_name = LearnerPlacement::displayName($name);
        }

        $record->student_details = $details;
        $record->section = LearnerPlacement::sectionLabel($grade, $section);
        $record->left_at = null;
        $record->end_status = null;
        $record->wait_grade = null;
        $record->archived_at = null;
        $record->archived_by = null;
        $record->archive_reason = null;
        $record->delete_after = null;
        $record->joined_at = CarbonImmutable::now();
        $record->joined_as = LearnerPlacement::RESTORED;
        $record->save();

        LearnerPlacement::forget();
    }

    /**
     * Cancel the deletion of a learner archived in an earlier year, once they
     * have a row in a section again. Their old placement keeps its end status
     * and the date they left — that is their history — but the learner is no
     * longer archived and nothing is due for deletion.
     */
    public static function clearArchive(int $institutionId, string $lrn): void
    {
        StudentHealthRecord::query()
            ->where('institution_id', $institutionId)
            ->where('student_id', $lrn)
            ->where(fn ($q) => $q->whereNotNull('archived_at')->orWhereNotNull('delete_after'))
            ->get()
            ->each(function (StudentHealthRecord $record): void {
                $record->archived_at = null;
                $record->archived_by = null;
                $record->delete_after = null;
                $record->save();
            });

        LearnerPlacement::forget();
    }

    /** Stamp how a learner came into the section. */
    public static function stampJoined(StudentHealthRecord $record, string $joinedAs): void
    {
        if (! SchemaCache::hasColumn('student_health_records', 'joined_as')) {
            return;
        }

        $record->joined_at = CarbonImmutable::now();
        $record->joined_as = $joinedAs;
        $record->save();
    }
}
