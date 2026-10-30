<?php

namespace App\Http\Controllers;

use App\Models\FeedingAttendance;
use App\Models\FeedingAttendanceConfirmation;
use App\Models\FeedingFollowUp;
use App\Models\StudentHealthRecord;
use App\Support\AuditTrail;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingAttendanceFlag;
use App\Support\FeedingAttendanceMark;
use App\Support\FeedingAttendanceWindow;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingProgramCycle;
use App\Support\SchemaCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Feeding Coordinator's At-Risk Students tab — where the attendance flow
 * the capstone adviser set is worked:
 *
 *   day 3 of unexcused absence in a row → ATTENDANCE NOTICE: verify the record
 *   day 7                                → AWAITING CONFIRMATION: confirm it
 *   confirmed                            → FLAGGED FOR FOLLOW-UP: record follow-ups
 *                                          until one is Resolved
 *
 * Every standing comes from FeedingAttendanceFlag, the one reading every other
 * screen counts from, so this tab and the Dashboard cannot disagree about who is
 * flagged.
 *
 * Three things a human enters here, each through one endpoint, scoped to the
 * coordinator's school and audited:
 *
 * - the **confirmation** that a learner's record is correct
 *   (`feeding_attendance_confirmations`) — the only thing that flags anybody;
 * - a **correction** to one of the absences being verified, through the one
 *   audited correction path (`feedingcor-program.beneficiary.attendance.correct`)
 *   — offered for today's absence only, because a day's attendance closes when
 *   the day does (FeedingAttendanceWindow); an earlier absence is verified as
 *   it stands;
 * - the **follow-up** (`feeding_follow_ups`) on a flagged learner.
 *
 * None of them touches enrolment. A flag is a reason to follow a learner up,
 * never to stop feeding them, so there is no removal and no enrolment endpoint
 * on this page.
 */
class FeedingAtRiskController extends Controller
{
    /** How many recent marks the detail view prints as a sequence. */
    private const RECENT_MARK_COUNT = 5;

    /** How many recent absences the detail view lists for a flagged learner. */
    private const RECENT_ABSENCE_COUNT = 7;

    /** Attendance bands the list can be narrowed to. */
    private const ATTENDANCE_BANDS = ['below_50', '50_69', '70_79', '80_plus', 'no_sessions'];

    /** Absence bands the list can be narrowed to. */
    private const ABSENCE_BANDS = ['1_2', '3_6', '7_plus'];

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
     * has opened to read, and replacing it under their hands while they verify a
     * record would throw away their place in it.
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
     * The list as it stands on screen — the same rows, the same order, narrowed
     * by the same filters. It carries learner names, so the route sits under
     * dashboard/* and is audited by AuditSensitiveAccess.
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
        $writer->addRow(Row::fromValues([
            'Attendance notice: '.$data['cards']['notice_label'].' · Flag threshold: '.$data['cards']['threshold_label'],
        ]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues([
            'PRIORITY', 'STUDENT', 'GRADE', 'SECTION', 'PRESENT', 'ABSENT', 'ATTENDANCE',
            'CONSECUTIVE ABSENCES', 'STATUS', 'CONFIRMED ON', 'FOLLOW-UP STATUS', 'LAST FOLLOW-UP',
        ]));

        foreach ($data['rows'] as $row) {
            $writer->addRow(Row::fromValues([
                FeedingAttendanceFlag::priorityLabel($row['priority']),
                $row['name'],
                $row['grade_number'],
                $row['section'],
                $row['present'],
                $row['absent'],
                // An em dash, never 0%: no confirmed session is not a turnout of nothing.
                $row['rate'] !== null ? number_format($row['rate'], 0).'%' : '—',
                $row['run'],
                $row['state_label'],
                $row['confirmation']['date_label'] ?? '—',
                $row['follow_up']['status_label'],
                $row['follow_up']['date_label'] ?? '—',
            ]));
        }

        $writer->close();

        $filename = 'SBFP-At-Risk-'.str_replace('/', '-', $data['schoolYear']).'-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * The coordinator's word that one learner's attendance record is correct.
     *
     * The stage is decided here, from the learner's standing as the server reads
     * it now — never from the form — so a stale tab or a replayed post cannot
     * flag a learner whose record no longer reaches the threshold:
     *
     * - ATTENDANCE NOTICE → a NOTICE-stage verification. It acknowledges the
     *   notice and flags nobody.
     * - AWAITING CONFIRMATION → a FLAG-stage confirmation. This, and nothing
     *   else, flags the learner for follow-up.
     *
     * Anything else has nothing to confirm and is refused. Enrolment is never
     * touched.
     */
    public function confirmAttendance(Request $request): RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can confirm attendance.');
        }

        if (! SchemaCache::hasTable('feeding_attendance_confirmations')) {
            return back()->with('error', 'Attendance confirmations are not available yet. Run the pending migrations.');
        }

        $validated = $request->validate([
            'record_id' => ['required', 'integer'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $institutionId = $request->session()->get('active_institution_id');
        $learner = $this->scopedLearner($institutionId, (int) $validated['record_id']);

        if (! $learner) {
            return redirect()
                ->route('dashboard.feedingcor-at-risk')
                ->with('error', 'That learner is not on this school’s roster.');
        }

        if (! FeedingBeneficiarySummary::isBeneficiary($learner)) {
            return redirect()
                ->route('dashboard.feedingcor-at-risk')
                ->with('error', 'Attendance can only be confirmed for an enrolled beneficiary.');
        }

        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        $standing = FeedingAttendanceFlag::forRecords(collect([$learner]), $rule)[$learner->id];

        $stage = match (true) {
            $standing['state'] === FeedingAttendanceFlag::AWAITING_CONFIRMATION => FeedingAttendanceConfirmation::STAGE_FLAG,
            $standing['state'] === FeedingAttendanceFlag::NOTICE && ! $standing['notice_verified'] => FeedingAttendanceConfirmation::STAGE_NOTICE,
            default => null,
        };

        if ($stage === null) {
            return back()->with('error', 'There is no attendance record waiting to be confirmed for this learner.');
        }

        $evidence = $standing['evidence'];
        $confirmer = trim((string) $request->session()->get('active_name', '')) ?: null;

        $confirmation = DB::transaction(function () use ($learner, $institutionId, $stage, $evidence, $standing, $confirmer, $validated): FeedingAttendanceConfirmation {
            // Through the model: the casts are what keep the staff name and the
            // remark encrypted, and Auditable records the row itself.
            $confirmation = FeedingAttendanceConfirmation::create([
                'student_health_record_id' => $learner->id,
                'institution_id' => $institutionId,
                'school_year' => (string) $learner->school_year,
                'stage' => $stage,
                'run_started_on' => $stage === FeedingAttendanceConfirmation::STAGE_NOTICE
                    ? $standing['run_started_on']
                    : ($evidence[0] ?? $standing['run_started_on']),
                'confirmed_through' => $evidence !== [] ? end($evidence) : null,
                'absences' => count($evidence),
                'confirmed_by_name' => $confirmer,
                'confirmed_by_role' => 'feeding_coor',
                'remarks' => $this->text($validated['remarks'] ?? null),
            ]);

            // The clinic dashboards count the stored flag, so it moves with the
            // confirmation rather than at the next attendance write.
            FeedingAttendanceFlag::refreshStoredFlags($institutionId);

            return $confirmation;
        });

        AuditTrail::record(
            $stage === FeedingAttendanceConfirmation::STAGE_FLAG ? 'feeding_attendance_confirmed' : 'feeding_attendance_verified',
            'StudentHealthRecord',
            $learner->id,
            $stage === FeedingAttendanceConfirmation::STAGE_FLAG
                ? 'Confirmed the attendance record of learner #'.$learner->id.' and flagged them for follow-up ('.count($evidence).' unexcused absences)'
                : 'Verified the attendance record of learner #'.$learner->id.' at the attendance notice ('.count($evidence).' unexcused absences)',
            [
                'confirmation_id' => $confirmation->id,
                'stage' => $stage,
                'run_started_on' => $confirmation->run_started_on?->toDateString(),
                'confirmed_through' => $confirmation->confirmed_through?->toDateString(),
                'absences' => $confirmation->absences,
            ],
        );

        return back()->with('success', $stage === FeedingAttendanceConfirmation::STAGE_FLAG
            ? 'Attendance confirmed. '.$learner->student_name.' is flagged for follow-up.'
            : 'Attendance verified for '.$learner->student_name.'.');
    }

    /**
     * Records one follow-up on one flagged beneficiary.
     *
     * Only a learner whose record the coordinator has confirmed can be followed
     * up — that confirmation is what the adviser's flow puts first. A Resolved
     * follow-up closes the flag; nothing about it touches enrolment.
     *
     * Written through the model — never a raw insert, because the casts are what
     * keep the note and the staff name encrypted — and audited with the learner
     * it concerns and the status it moved to. The free text is not put in the
     * audit description: the entry says a follow-up happened, the encrypted row
     * says what was in it.
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
        $learner = $this->scopedLearner($institutionId, (int) $request->input('record_id'));

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

        $standing = FeedingAttendanceFlag::forRecords(
            collect([$learner]),
            FeedingAtRiskRule::forInstitution($institutionId)
        )[$learner->id];

        if (! $standing['flagged']) {
            return redirect()
                ->route('dashboard.feedingcor-at-risk')
                ->with('error', 'A follow-up is recorded once the attendance record has been confirmed and the learner is flagged.');
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

        // Resolved is the one status that changes a flag: it closes it. The
        // stored flag the clinic dashboards count follows at once.
        if ($followUp->status === FeedingFollowUp::STATUS_RESOLVED) {
            FeedingAttendanceFlag::refreshStoredFlags($institutionId);
        }

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
     * disagree about how many learners are flagged.
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

        // Grade, section and sex are scope: they move the cards and the list
        // together, so a filtered page reports the size of what it is showing.
        $filters = $this->readFilters($request, $beneficiaries);
        $scoped = FeedingBeneficiarySummary::scopeToSex(
            FeedingBeneficiarySummary::scopeToSection($beneficiaries, [
                'grade' => $filters['grade'],
                'section' => $filters['section'],
            ]),
            $filters['sex']
        );

        $marks = $this->marksFor($scoped);
        $byRecord = $marks->groupBy('record_id');

        // The school's feeding days, so an absence can be named by the day it
        // fell on rather than by its date alone.
        $sessionDates = $marks->pluck('date')->unique()->sort()->values()->all();
        $dayNumbers = array_flip($sessionDates);

        $followUps = $this->followUpsFor($scoped);
        $histories = FeedingAttendanceFlag::historiesFor($scoped->pluck('id'));

        $rows = $scoped
            ->map(fn (StudentHealthRecord $record): array => $this->buildRow(
                $record,
                $byRecord->get($record->id, collect()),
                $dayNumbers,
                $followUps->get($record->id, collect()),
                $histories[$record->id] ?? [],
                $rule,
            ))
            ->values();

        // The official figure, and the only one: at risk means FLAGGED — the
        // threshold reached and the record confirmed. A learner awaiting
        // confirmation or under a notice is never counted.
        $atRiskCount = $rows->where('at_risk', true)->count();
        $total = $rows->count();

        // The list is the learners the coordinator has to look at: flagged,
        // awaiting confirmation, or under an attendance notice.
        $listed = $rows->filter(fn (array $row): bool => in_array($row['state'], FeedingAttendanceFlag::ATTENTION, true));
        $listed = $this->applyFilters($listed, $filters)->values();

        return [
            'schoolYear' => $schoolYear,
            'schoolName' => $request->session()->get('active_school_name', 'School'),
            'todayLabel' => now()->format('F j, Y'),
            'today' => now()->toDateString(),
            'programDay' => $cycle->day(),
            'programDuration' => $cycle->durationDays(),
            'daysRemaining' => $cycle->daysRemaining(),
            'cards' => [
                'at_risk' => $atRiskCount,
                'beneficiaries' => $total,
                // Null, not zero: with no beneficiaries there is no rate to report.
                'at_risk_rate' => $total > 0 ? round(($atRiskCount / $total) * 100, 1) : null,
                'awaiting' => $rows->where('state', FeedingAttendanceFlag::AWAITING_CONFIRMATION)->count(),
                'notices' => $rows->where('state', FeedingAttendanceFlag::NOTICE)->count(),
                'notices_unverified' => $rows
                    ->where('state', FeedingAttendanceFlag::NOTICE)
                    ->where('notice_verified', false)
                    ->count(),
                'threshold_label' => $rule->thresholdLabel(),
                'notice_label' => $rule->noticeLabel(),
                'rule' => $rule->describe(),
                'needs_follow_up' => $listed
                    ->where('follow_up.status', FeedingFollowUp::STATUS_FOLLOW_UP_REQUIRED)
                    ->count(),
            ],
            'rows' => $listed,
            'followUpRows' => $listed->where('at_risk', true)->values(),
            'listedCount' => $listed->count(),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($beneficiaries),
            'statusOptions' => FeedingFollowUp::STATUSES,
            'standingOptions' => [
                FeedingAttendanceFlag::FLAGGED => FeedingAttendanceFlag::label(FeedingAttendanceFlag::FLAGGED),
                FeedingAttendanceFlag::AWAITING_CONFIRMATION => FeedingAttendanceFlag::label(FeedingAttendanceFlag::AWAITING_CONFIRMATION),
                FeedingAttendanceFlag::NOTICE => FeedingAttendanceFlag::label(FeedingAttendanceFlag::NOTICE),
            ],
            'followUpsAvailable' => SchemaCache::hasTable('feeding_follow_ups'),
            'confirmationsAvailable' => SchemaCache::hasTable('feeding_attendance_confirmations'),
            'excusedAvailable' => SchemaCache::hasColumn('feeding_attendances', 'is_excused'),
        ];
    }

    /**
     * One learner's row: where they stand, why, and what has been done about it.
     *
     * @param  Collection<int, array<string, mixed>>  $marks  this learner's marks, oldest first
     * @param  array<string, int>  $dayNumbers  session date => its place in the programme
     * @param  Collection<int, FeedingFollowUp>  $followUps  newest first
     * @param  array<string, mixed>  $history  FeedingAttendanceFlag::historiesFor() for this learner
     * @return array<string, mixed>
     */
    private function buildRow(
        StudentHealthRecord $record,
        Collection $marks,
        array $dayNumbers,
        Collection $followUps,
        array $history,
        FeedingAtRiskRule $rule,
    ): array {
        [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);

        // Unconfirmed and excused marks both become NULL, which the rule keeps
        // out of both the numerator and the denominator — an unread mark is
        // evidence of nothing, and an absence the school accepted is never held
        // against the learner.
        $dated = $marks->map(fn (array $mark): array => [
            'date' => $mark['date'],
            'mark' => match ($mark['status']) {
                FeedingAttendanceMark::PRESENT => true,
                FeedingAttendanceMark::ABSENT => false,
                default => null,
            },
        ])->values()->all();
        $sequence = array_column($dated, 'mark');

        $standing = FeedingAttendanceFlag::evaluate($dated, $rule, $history);

        $confirmed = $rule->confirmedCount($sequence);
        $present = $rule->presentCount($sequence);
        $excused = $marks->where('status', FeedingAttendanceMark::EXCUSED)->count();
        $trend = FeedingAttendanceFlag::trend($sequence);

        // The follow-ups that belong to the flag currently open — a resolved
        // flag's history stays in the timeline, but it does not decide the
        // status column of a new one.
        $openedOn = $standing['flagged'] ? ($standing['confirmation']['confirmed_on'] ?? null) : null;
        $latest = $followUps->first();
        $current = $standing['flagged'] ? $latest : null;
        if ($current !== null && $openedOn !== null && $current->created_at?->toDateString() < $openedOn) {
            $current = null;
        }

        $byDate = $marks->keyBy('date');

        return [
            'id' => $record->id,
            'name' => (string) $record->student_name,
            'grade' => $grade,
            'grade_number' => preg_replace('/^grade\s*/i', '', $grade),
            'section' => $section,
            'present' => $present,
            'absent' => $confirmed - $present,
            'confirmed' => $confirmed,
            // The two states the rule discounts are reported apart: an absence
            // the school accepted is a decision somebody made, and a scanned
            // mark nobody has read is not.
            'excused' => $excused,
            'unconfirmed' => count($sequence) - $confirmed - $excused,
            'rate' => $rule->attendanceRate($sequence),
            'at_risk' => $standing['flagged'],
            'state' => $standing['state'],
            'state_label' => $standing['label'],
            'state_badge' => $standing['badge'],
            'priority' => $standing['priority'],
            'needs_action' => $standing['needs_action'],
            'notice_verified' => $standing['notice_verified'],
            'run' => $standing['run'],
            'confirmation' => $standing['confirmation'],
            // What the dialog's action button does for this learner, decided
            // with the same reading the server re-checks on the post.
            'confirm_stage' => match (true) {
                $standing['state'] === FeedingAttendanceFlag::AWAITING_CONFIRMATION => FeedingAttendanceConfirmation::STAGE_FLAG,
                $standing['state'] === FeedingAttendanceFlag::NOTICE && ! $standing['notice_verified'] => FeedingAttendanceConfirmation::STAGE_NOTICE,
                default => '',
            },
            'trend' => $trend,
            'reason' => $this->reason($standing, $rule),
            // The last few confirmed marks, oldest first: the shape of a
            // learner's recent attendance in one line.
            'recent' => FeedingAttendanceFlag::recentMarks($sequence, self::RECENT_MARK_COUNT),
            // The absences being verified, newest first, each with who took the
            // mark — a record cannot be checked without knowing whose it is.
            // Once a learner is flagged, the dialog shows their recent absences.
            'absences' => $this->absenceRows(
                $standing['evidence'] !== []
                    ? array_reverse($standing['evidence'])
                    : $marks->where('status', FeedingAttendanceMark::ABSENT)->sortByDesc('date')->take(self::RECENT_ABSENCE_COUNT)->pluck('date')->all(),
                $byDate,
                $dayNumbers
            ),
            'can_correct' => $standing['needs_action'],
            'trend_points' => $this->trendPoints($marks, $dayNumbers),
            'is_rate_rule' => $rule->isRateMode(),
            'threshold' => $rule->thresholdPercent(),
            'follow_up' => [
                'status' => $current?->status,
                'status_label' => $current
                    ? FeedingFollowUp::statusLabel($current->status)
                    : ($standing['flagged'] ? 'No follow-up' : '—'),
                'badge' => $current ? FeedingFollowUp::statusBadge($current->status) : 'badge-neutral',
                'date_label' => $current?->followed_up_on?->format('M j, Y'),
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
     * Why this learner is on the list, in one factual line about attendance.
     * It reports the run or the rate and the rule that reached it — never a
     * reason for the absences or a conclusion about the child.
     *
     * @param  array<string, mixed>  $standing
     */
    private function reason(array $standing, FeedingAtRiskRule $rule): string
    {
        $run = (int) $standing['run'];
        $runLabel = $run.' consecutive unexcused '.($run === 1 ? 'absence' : 'absences');

        return match ($standing['state']) {
            FeedingAttendanceFlag::FLAGGED => 'Confirmed for follow-up'
                .(($standing['confirmation']['by'] ?? '') !== '' ? ' by '.$standing['confirmation']['by'] : '')
                .' on '.($standing['confirmation']['date_label'] ?? '—').'.',
            FeedingAttendanceFlag::AWAITING_CONFIRMATION => ucfirst($rule->describeThreshold()).' reached.',
            FeedingAttendanceFlag::NOTICE => $runLabel.($standing['notice_verified']
                ? ' · verified'.(($standing['confirmation']['by'] ?? '') !== '' ? ' by '.$standing['confirmation']['by'] : '').' on '.($standing['confirmation']['date_label'] ?? '—')
                : '').'.',
            default => '',
        };
    }

    /**
     * `open` is whether the absence is from today: a mark can be changed on its
     * own day and never after (FeedingAttendanceWindow), so only today's
     * absence carries the correction form.
     *
     * @param  list<string>  $dates  newest first
     * @param  Collection<string, array<string, mixed>>  $byDate
     * @param  array<string, int>  $dayNumbers
     * @return list<array{date: string, label: string, day: int, remarks: string, recorded_by: string, open: bool}>
     */
    private function absenceRows(array $dates, Collection $byDate, array $dayNumbers): array
    {
        return array_values(array_map(fn (string $date): array => [
            'date' => $date,
            'label' => Carbon::parse($date)->format('M j, Y'),
            'day' => ($dayNumbers[$date] ?? 0) + 1,
            'remarks' => (string) ($byDate->get($date)['remarks'] ?? ''),
            'recorded_by' => (string) ($byDate->get($date)['recorded_by'] ?? ''),
            'open' => FeedingAttendanceWindow::isOpen($date),
        ], $dates));
    }

    /**
     * Every mark these beneficiaries carry, flattened to plain rows.
     *
     * Fetched, never aggregated in SQL: a SUM(CASE WHEN is_present …) folds an
     * unconfirmed NULL into "absent", which is the one thing attendance must
     * never do.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return Collection<int, array{record_id: int, date: string, status: string, remarks: string, recorded_by: string}>
     */
    private function marksFor(Collection $beneficiaries): Collection
    {
        if ($beneficiaries->isEmpty() || ! SchemaCache::hasTable('feeding_attendances')) {
            return collect();
        }

        $columns = ['student_health_record_id', 'session_date', 'is_present'];
        foreach (['needs_review', 'is_excused', 'remarks', 'recorded_by_name', 'reviewed_by_name'] as $column) {
            if (SchemaCache::hasColumn('feeding_attendances', $column)) {
                $columns[] = $column;
            }
        }

        return FeedingAttendance::query()
            ->whereIn('student_health_record_id', $beneficiaries->pluck('id'))
            ->whereDate('session_date', '<=', now()->toDateString())
            ->orderBy('session_date')
            ->get($columns)
            ->map(fn (FeedingAttendance $row): array => [
                'record_id' => (int) $row->student_health_record_id,
                'date' => optional($row->session_date)->toDateString(),
                'status' => FeedingAttendanceMark::state($row),
                'remarks' => trim((string) ($row->remarks ?? '')),
                // Whoever last decided the mark, else whoever took it — never a
                // guess at a person.
                'recorded_by' => trim((string) ($row->reviewed_by_name ?? '')) ?: trim((string) ($row->recorded_by_name ?? '')),
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
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_health_record_id');
    }

    /**
     * The learner's cumulative attendance rate after each confirmed session —
     * the line the trend chart draws. Unconfirmed and excused marks are
     * skipped, exactly as the rule skips them.
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
            if (! in_array($mark['status'], [FeedingAttendanceMark::PRESENT, FeedingAttendanceMark::ABSENT], true)) {
                continue;
            }

            $confirmed++;
            if ($mark['status'] === FeedingAttendanceMark::PRESENT) {
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
     * Seven filters, in the two kinds the coordinator's other tabs already use.
     *
     * Grade, section and gender are scope — they move the cards and the list
     * together. Status, attendance band, days absent and follow-up status
     * narrow the list alone, so the cards keep reporting the size of the
     * programme rather than the size of the last select touched.
     *
     * Every one reads an encrypted or derived value, so every one is applied in
     * PHP after fetch — never as a WHERE.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array{grade: string, section: string, sex: string, status: string, attendance: string, absences: string, follow_up: string}
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

        $sex = trim((string) $request->query('sex', ''));
        if (! in_array($sex, FeedingBeneficiarySummary::SEX_OPTIONS, true)) {
            $sex = '';
        }

        $status = trim((string) $request->query('status', ''));
        if (! in_array($status, FeedingAttendanceFlag::ATTENTION, true)) {
            $status = '';
        }

        $attendance = trim((string) $request->query('attendance', ''));
        if (! in_array($attendance, self::ATTENDANCE_BANDS, true)) {
            $attendance = '';
        }

        $absences = trim((string) $request->query('absences', ''));
        if (! in_array($absences, self::ABSENCE_BANDS, true)) {
            $absences = '';
        }

        // "none" is a real answer here: a flagged learner nobody has followed
        // up yet is exactly who a coordinator opens this filter to find.
        $followUp = trim((string) $request->query('follow_up', ''));
        if (! in_array($followUp, array_merge(['none'], array_keys(FeedingFollowUp::STATUSES)), true)) {
            $followUp = '';
        }

        return [
            'grade' => $grade,
            'section' => $section,
            'sex' => $sex,
            'status' => $status,
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
            ->when($filters['status'] !== '', fn (Collection $r) => $r->where('state', $filters['status']))
            ->when($filters['attendance'] !== '', fn (Collection $r) => $r->filter(
                fn (array $row): bool => $this->inAttendanceBand($row['rate'], $filters['attendance'])
            ))
            ->when($filters['absences'] !== '', fn (Collection $r) => $r->filter(
                fn (array $row): bool => $this->inAbsenceBand($row['run'], $filters['absences'])
            ))
            ->when($filters['follow_up'] !== '', fn (Collection $r) => $r->filter(
                fn (array $row): bool => $filters['follow_up'] === 'none'
                    ? $row['at_risk'] && $row['follow_up']['status'] === null
                    : $row['follow_up']['status'] === $filters['follow_up']
            ))
            // The decision waiting on the coordinator first, then the flags they
            // are following up, then the notices — and inside each the longest
            // run, so the row a coordinator should open is the row at the top.
            ->sortBy(fn (array $row): array => [
                FeedingAttendanceFlag::priorityRank($row['priority']),
                FeedingAttendanceFlag::stateRank($row['state']),
                -$row['run'],
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

    /** Bands on the run of consecutive unexcused absences — the figure the flow reads. */
    private function inAbsenceBand(int $run, string $band): bool
    {
        return match ($band) {
            '1_2' => $run >= 1 && $run <= 2,
            '3_6' => $run >= 3 && $run <= 6,
            '7_plus' => $run >= 7,
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

    private function scopedLearner(mixed $institutionId, int $recordId): ?StudentHealthRecord
    {
        return StudentHealthRecord::query()
            ->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId))
            ->whereKey($recordId)
            ->first();
    }

    private function text(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function isCoordinator(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'feeding_coor';
    }
}
