<?php

namespace App\Http\Controllers;

use App\Models\FeedingAttendance;
use App\Models\FeedingFollowUp;
use App\Models\StudentHealthRecord;
use App\Support\AuditTrail;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingProgramCycle;
use App\Support\FeedingRiskSeverity;
use App\Support\SchemaCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Feeding Coordinator's At-Risk Students tab.
 *
 * It answers one question the other tabs do not: **which beneficiaries are
 * below the school's attendance threshold, why, and who needs following up.**
 *
 * That is deliberately not what the Attendance tab does. Attendance records
 * marks — who was fed on which day. This tab records nothing about a session at
 * all: it reads the marks the Attendance tab wrote, judges them with the
 * school's own rule, and adds the one thing no rule can derive — what a human
 * did about it. So there is no way to mark a learner present or absent here, and
 * there never should be: a second write path into feeding_attendances is exactly
 * how two screens start disagreeing about a child's attendance.
 *
 * Everything on screen is derived at read time from three sources:
 *
 * - `feeding_attendances` — the marks, with NULL still meaning "unconfirmed" and
 *   voting neither way (see FeedingAtRiskRule);
 * - FeedingAtRiskRule::forInstitution() — the school's own threshold, never a
 *   constant in this file, so a System Admin moving it moves this page;
 * - FeedingRiskSeverity — the Critical / At Risk / Watch bands, which are a
 *   monitoring aid layered on the rule and never a replacement for it. Only
 *   at-risk learners are counted by the At-Risk Students figure; a Watch learner
 *   is above the threshold and must never be added to it.
 *
 * The only stored thing is the follow-up (`feeding_follow_ups`), which is
 * scoped, audited and encrypted like every other note about a named child. It
 * records what was done, never a conclusion about the learner — and resolving a
 * concern never touches enrolment, which follows its own workflow.
 */
class FeedingAtRiskController extends Controller
{
    /** How many recent marks the detail view prints as a sequence. */
    private const RECENT_MARK_COUNT = 5;

    /** How many recent absences the detail view lists for follow-up. */
    private const RECENT_ABSENCE_COUNT = 6;

    /** Attendance bands the list can be narrowed to. */
    private const ATTENDANCE_BANDS = ['below_50', '50_69', '70_79', '80_plus', 'no_sessions'];

    /** Absence bands the list can be narrowed to. */
    private const ABSENCE_BANDS = ['1_2', '3_5', '6_plus'];

    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can open the at-risk list.');
        }

        return view('feedingcor-dashboard.at-risk', $this->build($request));
    }

    /**
     * The summary cards, re-rendered from the same Blade partial the first paint
     * used so a live view and a reloaded one cannot drift.
     *
     * The list itself is deliberately not returned. It holds rows the coordinator
     * has expanded to read, and replacing it under their hands while they work
     * through a follow-up would throw away their place in it — the same reason
     * the Attendance tab never live-replaces its marking sheet.
     */
    public function metrics(Request $request): JsonResponse
    {
        if (! $this->isCoordinator($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $this->build($request);

        return response()->json([
            'generatedAt' => now()->toIso8601String(),
            'html' => [
                'cards' => view('feedingcor-dashboard.partials.at-risk-cards', $data)->render(),
            ],
        ]);
    }

    /**
     * The at-risk list as it stands on screen — the same rows, the same order,
     * narrowed by the same filters.
     *
     * It carries learner names, so it is a read of personal data like any other:
     * the route sits under dashboard/* and is audited by AuditSensitiveAccess.
     */
    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can export the at-risk list.');
        }

        $data = $this->build($request);

        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path — otherwise every export
        // leaves an empty temp file behind.
        $reserved = tempnam(sys_get_temp_dir(), 'sbfp-at-risk-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues(['AT-RISK FEEDING BENEFICIARIES']));
        $writer->addRow(Row::fromValues([(string) $data['schoolName']]));
        $writer->addRow(Row::fromValues(['S.Y. '.$data['schoolYear']]));
        $writer->addRow(Row::fromValues(['At-risk threshold: '.$data['cards']['threshold_label'].'%']));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues([
            'PRIORITY', 'STUDENT', 'GRADE', 'SECTION', 'PRESENT', 'ABSENT',
            'ATTENDANCE', 'DAYS REMAINING', 'RISK', 'FOLLOW-UP STATUS', 'LAST FOLLOW-UP',
        ]));

        foreach ($data['rows'] as $row) {
            $writer->addRow(Row::fromValues([
                FeedingRiskSeverity::priorityLabel($row['priority']),
                $row['name'],
                $row['grade_number'],
                $row['section'],
                $row['present'],
                $row['absent'],
                // An em dash, never 0%: no confirmed session is not a turnout of nothing.
                $row['rate'] !== null ? number_format($row['rate'], 1).'%' : '—',
                $row['days_remaining'],
                FeedingRiskSeverity::severityLabel($row['severity']),
                $row['follow_up']['status_label'],
                $row['follow_up']['date_label'] ?? '—',
            ]));
        }

        $writer->close();

        $filename = 'SBFP-At-Risk-'.str_replace('/', '-', $data['schoolYear']).'-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Records one follow-up on one beneficiary.
     *
     * Coordinator-only, re-scoped to the coordinator's own school, written
     * through the model — never a raw insert, because the casts are what keep
     * the note and the staff name encrypted — and audited with the learner it
     * concerns and the status it moved to. The free text is not put in the audit
     * description: the entry says a follow-up happened, the encrypted row says
     * what was in it.
     */
    public function storeFollowUp(Request $request): RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can record a follow-up.');
        }

        if (! SchemaCache::hasTable('feeding_follow_ups')) {
            return back()->with('error', 'Follow-up records are not available yet. Run the pending migrations.');
        }

        $institutionId = $request->session()->get('active_institution_id');

        // Keyed by record id, never by name: this posts from a dialog whose
        // learner can be chosen, and a name is neither unique nor safe in a URL.
        $learner = StudentHealthRecord::query()
            ->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId))
            ->whereKey((int) $request->input('record_id'))
            ->first();

        if (! $learner) {
            return redirect()
                ->route('dashboard.feedingcor-at-risk')
                ->with('error', 'That learner is not on this school’s roster.');
        }

        if (! FeedingBeneficiarySummary::isBeneficiary($learner)) {
            return redirect()
                ->route('dashboard.feedingcor-at-risk')
                ->with('error', 'A follow-up can only be recorded for an enrolled beneficiary.');
        }

        $validated = $request->validate([
            'record_id' => ['required', 'integer'],
            // A follow-up is something that happened, so it cannot be dated
            // into the future.
            'followed_up_on' => ['required', 'date', 'before_or_equal:'.now()->toDateString()],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(FeedingFollowUp::STATUSES))],
            'reason' => ['nullable', 'string', 'max:500'],
            'action_taken' => ['nullable', 'string', 'max:500'],
            'person_contacted' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $followUp = FeedingFollowUp::create([
            'student_health_record_id' => $learner->id,
            'institution_id' => $institutionId,
            'school_year' => (string) $learner->school_year,
            'followed_up_on' => Carbon::parse($validated['followed_up_on'])->toDateString(),
            'status' => $validated['status'],
            'reason' => $this->text($validated['reason'] ?? null),
            'action_taken' => $this->text($validated['action_taken'] ?? null),
            'person_contacted' => $this->text($validated['person_contacted'] ?? null),
            'remarks' => $this->text($validated['remarks'] ?? null),
            'recorded_by_name' => trim((string) $request->session()->get('active_name', '')) ?: null,
            'recorded_by_role' => 'feeding_coor',
        ]);

        AuditTrail::record(
            'feeding_follow_up_recorded',
            'StudentHealthRecord',
            $learner->id,
            'Recorded a feeding follow-up for learner #'.$learner->id.' (status: '.FeedingFollowUp::statusLabel($followUp->status).')',
            [
                'follow_up_id' => $followUp->id,
                'status' => $followUp->status,
                'followed_up_on' => $followUp->followed_up_on?->toDateString(),
            ],
        );

        return back()->with('success', 'Follow-up recorded.');
    }

    /**
     * Everything the tab shows, from one reading of one set of marks.
     *
     * Building it in one place is what keeps the cards and the list in
     * agreement: they are two views of the same rows, not two queries that might
     * disagree about how many learners the threshold has flagged.
     *
     * @return array<string, mixed>
     */
    private function build(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');
        $schoolYear = StudentHealthRecord::currentSchoolYear();
        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        $cycle = FeedingProgramCycle::forInstitution($institutionId);

        // The enrolled roll, decided by the one class that decides it. Sorted in
        // PHP because student_name is encrypted at rest.
        $beneficiaries = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $beneficiaries = StudentHealthRecord::query()
                ->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId))
                ->forCurrentSchoolYear($schoolYear)
                ->get()
                ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
                ->sortBy(fn (StudentHealthRecord $record): string => strtolower((string) $record->student_name))
                ->values();
        }

        // Grade and section are scope: they move the cards and the list
        // together, so a filtered page reports the size of what it is showing.
        $filters = $this->readFilters($request, $beneficiaries);
        $scoped = FeedingBeneficiarySummary::scopeToSection($beneficiaries, [
            'grade' => $filters['grade'],
            'section' => $filters['section'],
        ]);

        $marks = $this->marksFor($scoped);
        $byRecord = $marks->groupBy('record_id');

        // The school's feeding days, so an absence can be named by the day it
        // fell on rather than by its date alone.
        $sessionDates = $marks->pluck('date')->unique()->sort()->values()->all();
        $dayNumbers = array_flip($sessionDates);

        $followUps = $this->followUpsFor($scoped);

        $rows = $scoped
            ->map(fn (StudentHealthRecord $record): array => $this->buildRow(
                $record,
                $byRecord->get($record->id, collect()),
                $dayNumbers,
                $followUps->get($record->id, collect()),
                $rule,
                $cycle,
            ))
            ->values();

        // The official figure, and the only one: at-risk means below the
        // school's threshold. Watch learners are above it and are never counted.
        $atRiskCount = $rows->where('at_risk', true)->count();
        $total = $rows->count();

        // The list is the learners who need attention — at risk or in the watch
        // band. A learner comfortably above the threshold has nothing to follow
        // up and is not printed as a row with no action.
        $listed = $rows->filter(fn (array $row): bool => $row['severity'] !== FeedingRiskSeverity::STEADY);
        $listed = $this->applyFilters($listed, $filters)->values();

        return [
            'schoolYear' => $schoolYear,
            'schoolName' => $request->session()->get('active_school_name', 'School'),
            'todayLabel' => now()->format('F j, Y'),
            'today' => now()->toDateString(),
            'programDay' => $cycle->day(),
            'programDuration' => FeedingProgramCycle::DURATION_DAYS,
            'daysRemaining' => $cycle->daysRemaining(),
            'cards' => [
                'at_risk' => $atRiskCount,
                'beneficiaries' => $total,
                // Null, not zero: with no beneficiaries there is no rate to report.
                'at_risk_rate' => $total > 0 ? round(($atRiskCount / $total) * 100, 1) : null,
                'threshold' => $rule->thresholdPercent(),
                'threshold_label' => $this->percent($rule->thresholdPercent()),
                'watch_label' => $this->percent(FeedingRiskSeverity::watchThresholdPercent($rule)),
                'rule' => $rule->describe(),
                'critical' => $rows->where('severity', FeedingRiskSeverity::CRITICAL)->count(),
                'watch' => $rows->where('severity', FeedingRiskSeverity::WATCH)->count(),
                'needs_follow_up' => $listed
                    ->where('follow_up.status', FeedingFollowUp::STATUS_FOLLOW_UP_REQUIRED)
                    ->count(),
            ],
            'rows' => $listed,
            'listedCount' => $listed->count(),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($beneficiaries),
            'statusOptions' => FeedingFollowUp::STATUSES,
            'followUpsAvailable' => SchemaCache::hasTable('feeding_follow_ups'),
        ];
    }

    /**
     * One learner's row: where they stand, why, and what has been done about it.
     *
     * @param  Collection<int, array<string, mixed>>  $marks  this learner's marks, oldest first
     * @param  array<string, int>  $dayNumbers  session date => its place in the programme
     * @param  Collection<int, FeedingFollowUp>  $followUps  newest first
     * @return array<string, mixed>
     */
    private function buildRow(
        StudentHealthRecord $record,
        Collection $marks,
        array $dayNumbers,
        Collection $followUps,
        FeedingAtRiskRule $rule,
        FeedingProgramCycle $cycle,
    ): array {
        [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);

        // Unconfirmed marks become NULL, which the rule keeps out of both the
        // numerator and the denominator.
        $sequence = $marks->map(fn (array $mark) => match ($mark['status']) {
            'present' => true,
            'absent' => false,
            default => null,
        })->values()->all();

        $standing = FeedingRiskSeverity::evaluate($sequence, $rule);
        $latest = $followUps->first();

        return [
            'id' => $record->id,
            'name' => (string) $record->student_name,
            'grade' => $grade,
            'grade_number' => preg_replace('/^grade\s*/i', '', $grade),
            'section' => $section,
            'present' => $standing['present'],
            'absent' => $standing['absent'],
            'confirmed' => $standing['confirmed'],
            'unconfirmed' => $standing['unconfirmed'],
            'rate' => $standing['rate'],
            'at_risk' => $standing['at_risk'],
            'severity' => $standing['severity'],
            'priority' => $standing['priority'],
            'trend' => $standing['trend'],
            'reason' => $standing['reason'],
            'threshold' => $rule->thresholdPercent(),
            // The programme's own figure, printed per row so a coordinator can
            // see how much of the cycle is left to recover the shortfall in.
            'days_remaining' => $cycle->daysRemaining(),
            // The last few confirmed marks, oldest first: the shape of a
            // learner's recent attendance in one line.
            'recent' => FeedingRiskSeverity::recentMarks($sequence, self::RECENT_MARK_COUNT),
            'absences' => $this->recentAbsences($marks, $dayNumbers),
            'trend_points' => $this->trendPoints($marks, $dayNumbers),
            'follow_up' => [
                'status' => $latest?->status,
                'status_label' => $latest ? FeedingFollowUp::statusLabel($latest->status) : 'No follow-up',
                'badge' => $latest ? FeedingFollowUp::statusBadge($latest->status) : 'badge-neutral',
                'date_label' => $latest?->followed_up_on?->format('M j, Y'),
            ],
            'follow_up_history' => $followUps->map(fn (FeedingFollowUp $entry): array => [
                'date_label' => $entry->followed_up_on?->format('F j, Y') ?? '—',
                'status' => (string) $entry->status,
                'status_label' => FeedingFollowUp::statusLabel($entry->status),
                'badge' => FeedingFollowUp::statusBadge($entry->status),
                'reason' => trim((string) $entry->reason),
                'action_taken' => trim((string) $entry->action_taken),
                'person_contacted' => trim((string) $entry->person_contacted),
                'remarks' => trim((string) $entry->remarks),
                'recorded_by' => trim((string) $entry->recorded_by_name),
            ])->values()->all(),
        ];
    }

    /**
     * Every mark these beneficiaries carry, flattened to plain rows.
     *
     * Fetched, never aggregated in SQL: a SUM(CASE WHEN is_present …) folds an
     * unconfirmed NULL into "absent", which is the one thing attendance must
     * never do.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return Collection<int, array{record_id: int, date: string, status: string, remarks: string}>
     */
    private function marksFor(Collection $beneficiaries): Collection
    {
        if ($beneficiaries->isEmpty() || ! SchemaCache::hasTable('feeding_attendances')) {
            return collect();
        }

        $columns = ['student_health_record_id', 'session_date', 'is_present'];
        if (SchemaCache::hasColumn('feeding_attendances', 'needs_review')) {
            $columns[] = 'needs_review';
        }
        if (SchemaCache::hasColumn('feeding_attendances', 'remarks')) {
            $columns[] = 'remarks';
        }

        return FeedingAttendance::query()
            ->whereIn('student_health_record_id', $beneficiaries->pluck('id'))
            ->whereDate('session_date', '<=', now()->toDateString())
            ->orderBy('session_date')
            ->get($columns)
            ->map(fn (FeedingAttendance $row): array => [
                'record_id' => (int) $row->student_health_record_id,
                'date' => optional($row->session_date)->toDateString(),
                'status' => match (true) {
                    (bool) ($row->needs_review ?? false), $row->is_present === null => 'unconfirmed',
                    (bool) $row->is_present => 'present',
                    default => 'absent',
                },
                'remarks' => trim((string) ($row->remarks ?? '')),
            ])
            ->filter(fn (array $row): bool => $row['date'] !== null)
            ->values();
    }

    /**
     * Every follow-up on these learners, newest first, grouped by learner.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return Collection<int, Collection<int, FeedingFollowUp>>
     */
    private function followUpsFor(Collection $beneficiaries): Collection
    {
        if ($beneficiaries->isEmpty() || ! SchemaCache::hasTable('feeding_follow_ups')) {
            return collect();
        }

        return FeedingFollowUp::query()
            ->whereIn('student_health_record_id', $beneficiaries->pluck('id'))
            ->orderByDesc('followed_up_on')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_health_record_id');
    }

    /**
     * The sessions this learner missed, most recent first, each named by the
     * feeding day it fell on — which is what makes a follow-up conversation
     * possible.
     *
     * @param  Collection<int, array<string, mixed>>  $marks
     * @param  array<string, int>  $dayNumbers
     * @return list<array{date: string, label: string, day: int, remarks: string}>
     */
    private function recentAbsences(Collection $marks, array $dayNumbers): array
    {
        return $marks
            ->where('status', 'absent')
            ->sortByDesc('date')
            ->take(self::RECENT_ABSENCE_COUNT)
            ->map(fn (array $mark): array => [
                'date' => $mark['date'],
                'label' => Carbon::parse($mark['date'])->format('M j, Y'),
                'day' => ($dayNumbers[$mark['date']] ?? 0) + 1,
                'remarks' => $mark['remarks'],
            ])
            ->values()
            ->all();
    }

    /**
     * The learner's cumulative attendance rate after each confirmed session —
     * the line the trend chart draws.
     *
     * Cumulative rather than per-session, because a per-session line for a
     * present/absent mark is only ever 0 or 100 and says nothing about
     * direction. Unconfirmed marks are skipped, exactly as the rule skips them,
     * so the line ends on the very figure the flag was decided by.
     *
     * @param  Collection<int, array<string, mixed>>  $marks
     * @param  array<string, int>  $dayNumbers
     * @return list<array{day: int, rate: float}>
     */
    private function trendPoints(Collection $marks, array $dayNumbers): array
    {
        $points = [];
        $present = 0;
        $confirmed = 0;

        foreach ($marks as $mark) {
            if ($mark['status'] === 'unconfirmed') {
                continue;
            }

            $confirmed++;
            if ($mark['status'] === 'present') {
                $present++;
            }

            $points[] = [
                'day' => ($dayNumbers[$mark['date']] ?? 0) + 1,
                'rate' => round(($present / $confirmed) * 100, 1),
            ];
        }

        return $points;
    }

    /**
     * Six filters, in the two kinds the coordinator's other tabs already use.
     *
     * Grade and section are scope — they move the cards and the list together.
     * Risk level, attendance band, days absent and follow-up status narrow the
     * list alone, so the cards keep reporting the size of the programme rather
     * than the size of the last select touched.
     *
     * Every one reads an encrypted or derived value, so every one is applied in
     * PHP after fetch — never as a WHERE.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array{grade: string, section: string, risk: string, attendance: string, absences: string, follow_up: string}
     */
    private function readFilters(Request $request, Collection $beneficiaries): array
    {
        $options = $this->filterOptions($beneficiaries);

        $grade = trim((string) $request->query('grade', ''));
        if (! in_array($grade, $options['grades'], true)) {
            $grade = '';
        }

        $section = trim((string) $request->query('section', ''));
        if (! in_array($section, $options['sections'], true)) {
            $section = '';
        }

        $risk = trim((string) $request->query('risk', ''));
        if (! in_array($risk, [FeedingRiskSeverity::CRITICAL, FeedingRiskSeverity::AT_RISK, FeedingRiskSeverity::WATCH], true)) {
            $risk = '';
        }

        $attendance = trim((string) $request->query('attendance', ''));
        if (! in_array($attendance, self::ATTENDANCE_BANDS, true)) {
            $attendance = '';
        }

        $absences = trim((string) $request->query('absences', ''));
        if (! in_array($absences, self::ABSENCE_BANDS, true)) {
            $absences = '';
        }

        // "none" is a real answer here: a learner nobody has followed up yet is
        // exactly who a coordinator opens this filter to find.
        $followUp = trim((string) $request->query('follow_up', ''));
        if (! in_array($followUp, array_merge(['none'], array_keys(FeedingFollowUp::STATUSES)), true)) {
            $followUp = '';
        }

        return [
            'grade' => $grade,
            'section' => $section,
            'risk' => $risk,
            'attendance' => $attendance,
            'absences' => $absences,
            'follow_up' => $followUp,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when($filters['risk'] !== '', fn (Collection $r) => $r->where('severity', $filters['risk']))
            ->when($filters['attendance'] !== '', fn (Collection $r) => $r->filter(
                fn (array $row): bool => $this->inAttendanceBand($row['rate'], $filters['attendance'])
            ))
            ->when($filters['absences'] !== '', fn (Collection $r) => $r->filter(
                fn (array $row): bool => $this->inAbsenceBand($row['absent'], $filters['absences'])
            ))
            ->when($filters['follow_up'] !== '', fn (Collection $r) => $r->filter(
                fn (array $row): bool => $filters['follow_up'] === 'none'
                    ? $row['follow_up']['status'] === null
                    : $row['follow_up']['status'] === $filters['follow_up']
            ))
            // Worst first: highest priority, then the lowest rate inside it, so
            // the row a coordinator should open is the row at the top. A learner
            // with no confirmed session sorts last rather than as 0%.
            ->sortBy(fn (array $row): array => [
                FeedingRiskSeverity::priorityRank($row['priority']),
                $row['rate'] ?? 101,
                strtolower($row['name']),
            ])
            ->values();
    }

    private function inAttendanceBand(?float $rate, string $band): bool
    {
        if ($rate === null) {
            return $band === 'no_sessions';
        }

        return match ($band) {
            'below_50' => $rate < 50,
            '50_69' => $rate >= 50 && $rate < 70,
            '70_79' => $rate >= 70 && $rate < 80,
            '80_plus' => $rate >= 80,
            default => false,
        };
    }

    private function inAbsenceBand(int $absences, string $band): bool
    {
        return match ($band) {
            '1_2' => $absences >= 1 && $absences <= 2,
            '3_5' => $absences >= 3 && $absences <= 5,
            '6_plus' => $absences >= 6,
            default => false,
        };
    }

    /**
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array{grades: list<string>, sections: list<string>}
     */
    private function filterOptions(Collection $beneficiaries): array
    {
        $split = $beneficiaries->map(
            fn (StudentHealthRecord $record): array => FeedingBeneficiarySummary::splitSection((string) $record->section)
        );

        return [
            'grades' => $split->pluck(0)->filter()->unique()->sort(SORT_NATURAL)->values()->all(),
            'sections' => $split->pluck(1)->filter()->unique()->sort(SORT_NATURAL)->values()->all(),
        ];
    }

    private function text(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    private function isCoordinator(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'feeding_coor';
    }
}
