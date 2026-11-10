<?php

namespace App\Support;

use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The feeding attendance of one month laid out as the DepEd School Form 2
 * (SF2): one row per beneficiary, males then females, one column per weekday
 * of the month, totals per learner and per day, and the SF2 summary block.
 *
 * One reading for both surfaces that draw it — the Record Attendance page,
 * where the session's own column takes a mark, and the Attendance tab's
 * sheet, which only reads it — so the two can never disagree about a figure.
 */
final class Sf2AttendanceForm
{
    /**
     * @param  string|null  $sessionDate  the session the form is opened on, inside the month, or null
     * @param  string  $grade  scope: '' for every grade
     * @param  string  $section  scope: '' for every section
     * @param  string  $sex  scope: '' for both
     * @param  list<int>|null  $onlyIds  narrows the rows alone (never the summary), or null
     * @param  bool  $writable  false draws every column as a record
     * @return array<string, mixed>
     */
    public static function build(
        ?int $institutionId,
        ?string $sessionDate,
        Carbon $monthStart,
        string $grade = '',
        string $section = '',
        string $sex = '',
        ?array $onlyIds = null,
        bool $writable = true,
    ): array {
        $today = FeedingAttendanceWindow::today();
        $monthStart = $monthStart->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        // The page's one read of the roster (memoized), and nothing at all
        // without a school: a missing scope reads nobody, never every school.
        $schoolRecords = $institutionId && SchemaCache::hasTable('student_health_records')
            ? StudentHealthRecord::rosterFor($institutionId)
            : collect();

        $beneficiaries = $schoolRecords
            ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
            ->values();

        // Options come from the whole roll, so choosing one never deletes the
        // choice that would widen it again.
        $gradeOf = fn (StudentHealthRecord $record): string => FeedingBeneficiarySummary::splitSection((string) $record->section)[0];
        $sectionOf = fn (StudentHealthRecord $record): string => FeedingBeneficiarySummary::splitSection((string) $record->section)[1];
        $gradeOrder = fn (string $value): string => sprintf('%04d %s', preg_match('/\d+/', $value, $m) ? (int) $m[0] : 9999, $value);

        $grades = $beneficiaries->map($gradeOf)->unique()->sortBy($gradeOrder)->values();
        $grade = $grades->contains($grade) ? $grade : '';
        $sections = $beneficiaries
            ->filter(fn (StudentHealthRecord $record): bool => $grade === '' || $gradeOf($record) === $grade)
            ->map($sectionOf)
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
        $section = $sections->contains($section) ? $section : '';

        $inScope = fn (StudentHealthRecord $record): bool => ($grade === '' || $gradeOf($record) === $grade)
            && ($section === '' || $sectionOf($record) === $section)
            && ($sex === '' || FeedingBeneficiarySummary::sexOf($record) === $sex);

        $roster = $beneficiaries->filter($inScope)->values();

        // Every mark of the month for the learners in scope, in one query.
        $monthMarks = collect();
        if ($roster->isNotEmpty() && SchemaCache::hasTable('feeding_attendances')) {
            $monthMarks = FeedingAttendance::query()
                ->whereIn('student_health_record_id', $roster->pluck('id'))
                ->whereDate('session_date', '>=', $monthStart->toDateString())
                ->whereDate('session_date', '<=', $monthEnd->toDateString())
                ->get()
                ->groupBy('student_health_record_id')
                ->map(fn (Collection $rows): Collection => $rows->keyBy(
                    fn (FeedingAttendance $row): string => (string) optional($row->session_date)->toDateString()
                ));
        }

        // Only the session's own day, inside the running cycle, can be written.
        $cycle = FeedingProgramCycle::forInstitution($institutionId);
        $cycleStart = $cycle->startDateIso();
        $cycleEnd = $cycle->endDateIso();
        $sessionOpen = $writable
            && $sessionDate !== null
            && FeedingAttendanceWindow::isOpen($sessionDate)
            && FeedingProgramCycle::isFeedingDay($sessionDate, $institutionId)
            && ($cycleStart === null || ($sessionDate >= $cycleStart && $sessionDate <= $cycleEnd));

        // The month's weekdays, in SF2's M T W TH F columns. A weekend is never
        // a session, so it is never a column.
        $days = [];
        for ($cursor = $monthStart->copy(); $cursor->lte($monthEnd); $cursor->addDay()) {
            if ($cursor->isWeekend()) {
                continue;
            }
            $date = $cursor->toDateString();
            $closure = SchoolCalendar::closureOn($date, $institutionId);

            $days[] = [
                'date' => $date,
                'weekday' => ['', 'M', 'T', 'W', 'TH', 'F'][$cursor->dayOfWeekIso],
                'day' => $cursor->day,
                'closed' => $closure['name'] ?? null,
                'future' => $date > $today,
                'today' => $date === $today,
                // The session the form was opened on, marked out on the grid.
                'selected' => $date === $sessionDate,
                'editable' => $sessionOpen && $date === $sessionDate,
            ];
        }

        $allRows = $roster
            ->map(function (StudentHealthRecord $record) use ($monthMarks, $sessionDate, $gradeOf, $sectionOf, $days): array {
                $marks = $monthMarks->get($record->id, collect());
                $session = $sessionDate !== null ? $marks->get($sessionDate) : null;

                $states = [];
                foreach ($days as $day) {
                    $row = $marks->get($day['date']);
                    $states[$day['date']] = $row === null ? '' : FeedingAttendanceMark::state($row);
                }

                // The reason last given this month, for the Remarks column.
                $latest = $marks->sortKeysDesc()
                    ->first(fn (FeedingAttendance $row): bool => trim((string) ($row->remarks ?? '')) !== '');

                return [
                    'id' => $record->id,
                    'name' => (string) $record->student_name,
                    'grade' => $gradeOf($record),
                    'section' => $sectionOf($record),
                    'sex' => FeedingBeneficiarySummary::sexOf($record),
                    'marks' => $states,
                    // The session's own mark. An unconfirmed scanned mark and a
                    // learner no sheet has covered both arrive with nothing
                    // selected, so saving the form is what decides them.
                    'mark' => match (FeedingAttendanceMark::state($session)) {
                        FeedingAttendanceMark::PRESENT => FeedingAttendanceMark::PRESENT,
                        FeedingAttendanceMark::ABSENT => FeedingAttendanceMark::ABSENT,
                        FeedingAttendanceMark::EXCUSED => FeedingAttendanceMark::EXCUSED,
                        default => '',
                    },
                    'remarks' => (string) ($session->remarks ?? ''),
                    'latest_remark' => $latest === null ? '' : trim((string) $latest->remarks),
                    'latest_remark_date' => $latest === null ? '' : (string) optional($latest->session_date)->format('M j'),
                ];
            })
            ->sortBy(fn (array $row): string => strtolower($row['name']))
            ->values();

        // The summary is the class's, whatever narrows the list on screen.
        $summary = self::summary($schoolRecords->filter($inScope), $allRows, $days, $monthStart, $monthEnd, $today, $cycleStart);

        $rows = $onlyIds === null
            ? $allRows
            : $allRows->filter(fn (array $row): bool => in_array((int) $row['id'], $onlyIds, true))->values();

        return [
            'rows' => $rows,
            'days' => $days,
            'summary' => $summary,
            'grades' => $grades,
            'sections' => $sections,
            'grade' => $grade,
            'section' => $section,
            'beneficiaryIds' => $beneficiaries->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'sessionOpen' => $sessionOpen,
            'monthLabel' => $monthStart->format('F Y'),
        ];
    }

    /**
     * The SF2 summary block, by sex: enrollment as of the programme's cut-off
     * (its first feeding day), late enrollment during the month, learners
     * registered at its end, average daily attendance and the two
     * percentages, learners absent five days running, and learners taken off
     * the programme during the month.
     *
     * A day counts as a feeding day here only once somebody recorded it: a day
     * nobody recorded is a missing record, never a day of absence, and must
     * not move a rate.
     *
     * @param  Collection<int, StudentHealthRecord>  $records  the school's learners in scope, enrolled or not
     * @param  Collection<int, array<string, mixed>>  $rows  the form's rows
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private static function summary(Collection $records, Collection $rows, array $days, Carbon $monthStart, Carbon $monthEnd, string $today, ?string $programStart): array
    {
        $first = $monthStart->toDateString();
        $last = min($monthEnd->toDateString(), $today);

        // SF2's base is one fixed cut-off for the year ("enrollment as of the
        // 1st Friday"), not the start of each month — a monthly base let a
        // month's late enrollees push the percentage of enrollment past 100%.
        // The programme's cut-off is its first feeding day. Before it has
        // started, or for a month before it, the base is the end of the month
        // read, so nobody is late against a cut-off that has not happened.
        $cutoff = $programStart !== null && $programStart <= $last ? $programStart : $last;
        $localDate = fn ($at): ?string => $at === null ? null : SchoolClock::local($at)?->toDateString();

        $blank = ['Male' => 0, 'Female' => 0, 'Total' => 0];
        $tally = function (array &$counts, string $sex): void {
            if ($sex !== '') {
                $counts[$sex]++;
            }
            $counts['Total']++;
        };
        $count = function (callable $test) use ($records, $blank, $tally): array {
            $out = $blank;
            foreach ($records as $record) {
                if ($test($record)) {
                    $tally($out, FeedingBeneficiarySummary::sexOf($record));
                }
            }

            return $out;
        };

        $enrolled = fn (StudentHealthRecord $record): ?string => $localDate($record->feeding_enrolled_at);
        $removed = fn (StudentHealthRecord $record): ?string => $localDate(FeedingBeneficiarySummary::removedAt($record));

        $atStart = $count(fn ($r): bool => ($e = $enrolled($r)) !== null && $e <= $cutoff
            && (($x = $removed($r)) === null || $x > $cutoff));
        // Enrolled after the cut-off, during the month being read.
        $late = $count(fn ($r): bool => ($e = $enrolled($r)) !== null && $e > $cutoff && $e >= $first && $e <= $last);
        $registered = $count(fn ($r): bool => ($e = $enrolled($r)) !== null && $e <= $last
            && (($x = $removed($r)) === null || $x > $last));
        $dropped = $count(fn ($r): bool => ($x = $removed($r)) !== null && $x >= $first && $x <= $last);

        // A feeding day is a day somebody on the form was recorded on.
        $held = collect($days)->pluck('date')->filter(
            fn (string $date): bool => $rows->contains(
                fn (array $row): bool => in_array($row['marks'][$date] ?? '', FeedingAttendanceMark::RECORDABLE, true)
            )
        )->values();

        $present = $blank;
        $consecutive = $blank;
        foreach ($rows as $row) {
            $run = 0;
            $longest = 0;
            foreach ($held as $date) {
                $state = $row['marks'][$date] ?? '';
                if ($state === FeedingAttendanceMark::PRESENT) {
                    $tally($present, $row['sex']);
                    $run = 0;
                } elseif ($state === FeedingAttendanceMark::ABSENT) {
                    $longest = max($longest, ++$run);
                }
                // Excused, unconfirmed and unrecorded neither break nor extend
                // a run: none of them is a day the learner was missed for.
            }
            if ($longest >= 5) {
                $tally($consecutive, $row['sex']);
            }
        }

        $ada = [];
        $percentEnrolment = [];
        $percentAttendance = [];
        foreach (array_keys($blank) as $key) {
            $ada[$key] = $held->isEmpty() ? null : round($present[$key] / $held->count(), 2);
            $percentEnrolment[$key] = $atStart[$key] > 0 ? round($registered[$key] / $atStart[$key] * 100, 1) : null;
            $percentAttendance[$key] = $ada[$key] !== null && $registered[$key] > 0
                ? round($ada[$key] / $registered[$key] * 100, 1)
                : null;
        }

        return [
            'feeding_days' => $held->count(),
            'cutoff' => $cutoff,
            'enrolment_start' => $atStart,
            'late_enrolment' => $late,
            'registered' => $registered,
            'percent_enrolment' => $percentEnrolment,
            'ada' => $ada,
            'percent_attendance' => $percentAttendance,
            'consecutive_absent' => $consecutive,
            'dropped' => $dropped,
        ];
    }
}
