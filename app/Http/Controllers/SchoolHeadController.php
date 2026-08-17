<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\FeedingProgramCycle;
use App\Support\SchemaCache;
use App\Support\SchoolHeadHealthOverview;
use App\Support\SchoolHeadOverview;
use App\Support\SchoolHeadPulse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The School Head's Dashboard — oversight and decision support.
 *
 * It answers three questions and nothing else: what is happening in the clinic
 * and the feeding programme, what needs management attention, and whether the
 * school is meeting its health-programme obligations. It is deliberately not a
 * smaller copy of the operational tabs: there is no encoding here, no
 * consultation form, no attendance mark, no inventory movement — those belong
 * to the roles that own them, and RestrictSchoolHeadWrites refuses them
 * server-side.
 *
 * Two readings feed every panel: SchoolHeadOverview (roster, feeding,
 * nutrition) and SchoolHeadHealthOverview (clinic, consent, inventory). Both
 * are taken once per request and shared, so the Dashboard's consultation count
 * and the Health tab's, or its consent rate and the Consent tab's, cannot
 * drift apart.
 *
 * Four filters scope the whole screen — school year, grade and section move
 * every panel together; only the school year is a SQL filter, because every
 * other column the filters read is encrypted at rest.
 */
class SchoolHeadController extends Controller
{
    /** How close the cycle has to be to its end before the head is warned. */
    private const ENDLINE_NOTICE_DAYS = 30;

    /** Consent completion under this share is named on the queue. */
    private const CONSENT_TARGET_PERCENT = 95.0;

    public function index(Request $request): View
    {
        return view('schoolhead-dashboard.school-head', [
            // Seeded so the page's first pulse compares against what it was
            // rendered from, instead of rebuilding metrics it already has.
            'stamp' => $this->metricsStamp($request),
        ] + $this->buildMetrics($request));
    }

    /**
     * The dashboard's live numbers, re-read on demand. Everything the page
     * shows is computed here, so the first paint and every later refresh come
     * from one implementation and can never drift apart.
     */
    public function metrics(Request $request): JsonResponse
    {
        if (! $this->isSchoolHead($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $metrics = $this->buildMetrics($request);

        return response()->json([
            'stamp' => $this->metricsStamp($request),
            'generatedAt' => $metrics['generatedAt'],
            'html' => [
                'stats' => view('schoolhead-dashboard.partials.stat-cards', $metrics)->render(),
                'queue' => view('schoolhead-dashboard.partials.action-queue', $metrics)->render(),
                'cycle' => view('schoolhead-dashboard.partials.cycle-bar', $metrics)->render(),
                'snapshot' => view('schoolhead-dashboard.partials.grade-snapshot', $metrics)->render(),
                'programs' => view('schoolhead-dashboard.partials.program-overview', $metrics)->render(),
                'performance' => view('schoolhead-dashboard.partials.performance', $metrics)->render(),
                'clinic' => view('schoolhead-dashboard.partials.clinic-panel', $metrics)->render(),
                'feeding' => view('schoolhead-dashboard.partials.feeding-panel', $metrics)->render(),
                'nutrition' => view('schoolhead-dashboard.partials.nutrition-panel', $metrics)->render(),
                'consent' => view('schoolhead-dashboard.partials.consent-panel', $metrics)->render(),
                'inventory' => view('schoolhead-dashboard.partials.inventory-panel', $metrics)->render(),
            ],
        ]);
    }

    /**
     * Change-detection only: a hash of row counts and last-touched timestamps.
     * It carries no personal information, so the page can poll it on a timer
     * and pay for the (much heavier) metrics rebuild only when it moves.
     */
    public function pulse(Request $request): JsonResponse
    {
        if (! $this->isSchoolHead($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['stamp' => $this->metricsStamp($request)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetrics(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');
        $years = SchoolHeadOverview::schoolYears($institutionId);

        $schoolYear = trim((string) $request->query('school_year', ''));
        if (! in_array($schoolYear, $years, true)) {
            $schoolYear = $years[0] ?? StudentHealthRecord::currentSchoolYear();
        }

        // The whole school first: the filter selects have to offer every grade
        // and section on the roll, not only those left after the last choice.
        $school = SchoolHeadOverview::for($institutionId, $schoolYear);
        $options = $school->sectionOptions();

        $grade = trim((string) $request->query('grade', ''));
        if (! in_array($grade, $options['grades'], true)) {
            $grade = '';
        }

        $section = trim((string) $request->query('section', ''));
        if (! in_array($section, $options['sections'], true)) {
            $section = '';
        }

        $overview = $school->scopedTo($grade, $section);
        // One filter moves every panel: a narrowed roster narrows the clinic
        // figures too, rather than leaving them reporting the whole school.
        $health = SchoolHeadHealthOverview::for(
            $institutionId,
            $schoolYear,
            $overview->records,
            $grade !== '' || $section !== '',
        );

        $trend = $overview->undernourishedTrend();
        $outcome = $overview->outcome();
        $turnout = $overview->averageTurnout();
        $clinic = $health->clinic();
        $consent = $health->consent();
        $inventory = $health->inventory();
        $sections = $overview->records
            ->map(fn (StudentHealthRecord $record): string => trim((string) $record->section))
            ->filter()
            ->unique()
            ->count();

        return [
            'headName' => trim((string) $request->session()->get('active_name', '')) ?: 'School Head',
            'schoolName' => $request->session()->get('active_school_name', 'this school'),
            'schoolYear' => $schoolYear,
            'schoolYears' => $years,
            'filters' => ['school_year' => $schoolYear, 'grade' => $grade, 'section' => $section],
            'filterOptions' => $options,
            'todayLabel' => now()->format('l, j F Y'),
            'generatedAt' => now()->format('g:i A'),
            'cycle' => $this->buildCycle($overview),
            'stats' => [
                'total_students' => $overview->records->count(),
                'sections' => $sections,
                'undernourished' => $trend['latest'],
                'undernourished_change' => $trend['change'],
                'undernourished_baseline' => $trend['baseline'],
                'beneficiaries' => $overview->beneficiaries->count(),
                'rehabilitation_rate' => $outcome['rate'],
                'rehabilitated' => $outcome['rehabilitated'],
                'endline_measured' => $outcome['measured'],
                'turnout' => $turnout,
                'turnout_days' => $overview->daysCompleted(),
                'at_risk' => $overview->atRiskCount(),
                'consultations' => $clinic['total'],
                'consultations_this_month' => $clinic['this_month'],
                'referrals' => $clinic['referred'],
                'referrals_this_month' => $clinic['referred_this_month'],
                'clinic_month_label' => $clinic['month_label'],
                'consent_rate' => $consent['rate'],
                'consent_valid' => $consent['valid'],
                'consent_missing' => $consent['missing'],
                'medicines_low' => $inventory['low'] + $inventory['out'],
                'medicines_out' => $inventory['out'],
                'medicines_tracked' => $inventory['tracked'],
            ],
            'clinic' => $clinic,
            'consent' => $consent,
            'inventory' => $inventory,
            'feeding' => $this->buildFeeding($overview, $outcome),
            'nutrition' => $this->buildNutrition($overview, $outcome),
            'performance' => $this->buildPerformance($overview, $consent, $outcome, $turnout),
            'queue' => $this->buildQueue($overview, $consent, $inventory, $clinic),
            'gradeSnapshot' => $this->buildGradeSnapshot($overview),
            'programs' => $this->buildPrograms($overview),
        ];
    }

    /**
     * Where the cycle stands. Two figures, never merged: the calendar day the
     * cycle is on, and the feeding days actually recorded — a school on day 40
     * that has recorded 12 sheets has fed twelve times, not forty.
     *
     * @return array<string, mixed>
     */
    private function buildCycle(SchoolHeadOverview $overview): array
    {
        $completed = $overview->daysCompleted();

        return [
            'started' => $overview->cycle->hasStarted(),
            'day' => $overview->cycle->day(),
            'duration' => FeedingProgramCycle::DURATION_DAYS,
            'percent' => $overview->cycle->percent(),
            'days_completed' => $completed,
            'days_remaining' => $overview->daysRemaining(),
            'completed_percent' => round(($completed / FeedingProgramCycle::DURATION_DAYS) * 100, 1),
            'start_date' => $overview->cycle->startDateIso(),
        ];
    }

    /**
     * The feeding programme at a glance, as the head is accountable for it.
     *
     * Every figure is the one the coordinator's own tabs report — this panel
     * re-decides nothing, it only gathers.
     *
     * @param  array<string, mixed>  $outcome
     * @return array<string, mixed>
     */
    private function buildFeeding(SchoolHeadOverview $overview, array $outcome): array
    {
        $baseline = $overview->statusCounts('baseline', $overview->beneficiaries);
        $sessions = $overview->sessions();
        $today = now()->toDateString();

        $todaySession = collect($sessions)->firstWhere('date', $today);
        $latest = $sessions === [] ? null : $sessions[count($sessions) - 1];

        return [
            'beneficiaries' => $overview->beneficiaries->count(),
            'severely_wasted' => $baseline['Severely Wasted'],
            'wasted' => $baseline['Wasted'],
            'pending' => $overview->pendingEnrollment()->count(),
            'at_risk' => $overview->atRiskCount(),
            'observing' => $overview->observingCount(),
            'days_completed' => $overview->daysCompleted(),
            // Today's session only when today is actually a recorded feeding
            // day: an unheld day has no turnout, and 0% would say it did.
            'today_rate' => $todaySession['rate'] ?? null,
            'today_present' => $todaySession['present'] ?? 0,
            'today_recorded' => $todaySession !== null,
            'latest_label' => $latest['label'] ?? null,
            'latest_rate' => $latest['rate'] ?? null,
            'cumulative_rate' => $overview->averageTurnout(),
            'meals_served' => $overview->mealsServed(),
            'endline_measured' => $outcome['measured'],
            'endline_rate' => $outcome['beneficiaries'] > 0
                ? round(($outcome['measured'] / $outcome['beneficiaries']) * 100, 1)
                : null,
        ];
    }

    /**
     * The consolidated baseline-to-endline picture over the whole roster.
     *
     * A learner nobody weighed is carried in its own row and never folded into
     * Normal, so a head reading "5,585 Normal" is not reading children no one
     * has looked at.
     *
     * @param  array<string, mixed>  $outcome
     * @return array<string, mixed>
     */
    private function buildNutrition(SchoolHeadOverview $overview, array $outcome): array
    {
        $baseline = $overview->statusCounts('baseline');
        $endline = $overview->statusCounts('endline');
        $total = $overview->records->count();

        $baselineMeasured = $total - $baseline[SchoolHeadOverview::NOT_MEASURED];
        $endlineMeasured = $total - $endline[SchoolHeadOverview::NOT_MEASURED];

        $rows = array_map(static fn (string $status): array => [
            'label' => $status,
            'baseline' => $baseline[$status],
            'endline' => $endline[$status],
            'baseline_share' => $baselineMeasured > 0 ? round(($baseline[$status] / $baselineMeasured) * 100, 1) : null,
            'endline_share' => $endlineMeasured > 0 ? round(($endline[$status] / $endlineMeasured) * 100, 1) : null,
            'change' => $endlineMeasured > 0 ? $endline[$status] - $baseline[$status] : null,
        ], SchoolHeadOverview::NUTRITION_SCALE);

        return [
            'rows' => $rows,
            'baseline_measured' => $baselineMeasured,
            'endline_measured' => $endlineMeasured,
            'not_measured' => $baseline[SchoolHeadOverview::NOT_MEASURED],
            'total' => $total,
            'has_endline' => $endlineMeasured > 0,
            'improved' => $outcome['improved'],
            'improved_rate' => $outcome['improved_rate'],
            'rehabilitated' => $outcome['rehabilitated'],
            'rehabilitation_rate' => $outcome['rate'],
            'beneficiaries' => $outcome['beneficiaries'],
        ];
    }

    /**
     * How the school is performing against its health-programme obligations.
     *
     * Five shares, each printed with the counts behind it, and each NULL —
     * an em dash — where the denominator is empty. A programme nobody has
     * started is not a programme at 0%.
     *
     * @param  array<string, mixed>  $consent
     * @param  array<string, mixed>  $outcome
     * @return list<array<string, mixed>>
     */
    private function buildPerformance(
        SchoolHeadOverview $overview,
        array $consent,
        array $outcome,
        ?float $turnout,
    ): array {
        $total = $overview->records->count();
        $beneficiaries = $overview->beneficiaries->count();
        $atRisk = $overview->atRiskCount();

        $baselineMeasured = $total - $overview->statusCounts('baseline')[SchoolHeadOverview::NOT_MEASURED];

        return [
            [
                'label' => 'Consent Completion',
                'value' => $consent['rate'],
                'detail' => $total > 0 ? $consent['valid'].' of '.$total : null,
                // High is good for four of the five; the at-risk rate is the
                // one where a big number is the bad news, and the bar is drawn
                // in the risk colour so the direction is never guessed.
                'tone' => 'good',
            ],
            [
                'label' => 'SBFP Attendance',
                'value' => $turnout,
                'detail' => $overview->daysCompleted() > 0
                    ? $overview->daysCompleted().' recorded feeding '.$this->plural('day', 'days', $overview->daysCompleted())
                    : null,
                'tone' => 'good',
            ],
            [
                'label' => 'SBFP At-Risk Rate',
                'value' => $beneficiaries > 0 ? round(($atRisk / $beneficiaries) * 100, 1) : null,
                'detail' => $beneficiaries > 0 ? $atRisk.' of '.$beneficiaries : null,
                'tone' => 'risk',
            ],
            [
                'label' => 'Baseline Assessments',
                'value' => $total > 0 ? round(($baselineMeasured / $total) * 100, 1) : null,
                'detail' => $total > 0 ? $baselineMeasured.' of '.$total : null,
                'tone' => 'good',
            ],
            [
                'label' => 'Endline Assessments',
                'value' => $outcome['beneficiaries'] > 0
                    ? round(($outcome['measured'] / $outcome['beneficiaries']) * 100, 1)
                    : null,
                'detail' => $outcome['beneficiaries'] > 0
                    ? $outcome['measured'].' of '.$outcome['beneficiaries']
                    : null,
                'tone' => 'good',
            ],
        ];
    }

    /**
     * The queue, generated from live conditions.
     *
     * Every item names the condition, the figure behind it and where it is
     * resolved. None of them is a stored to-do: an item is present because the
     * records say so this second, and gone when they no longer do.
     *
     * @param  array<string, mixed>  $consent
     * @param  array<string, mixed>  $inventory
     * @param  array<string, mixed>  $clinic
     * @return list<array<string, mixed>>
     */
    private function buildQueue(
        SchoolHeadOverview $overview,
        array $consent,
        array $inventory,
        array $clinic,
    ): array {
        $items = [];
        $today = now()->toDateString();
        $sessionDates = $overview->sessionDates();

        // 1. Learners the school's own rule has flagged. The rule is read from
        //    the institution, never from a constant here.
        $atRisk = $overview->atRiskCount();
        if ($atRisk > 0) {
            $items[] = [
                'severity' => 'high',
                'title' => $atRisk.' '.$this->plural('beneficiary', 'beneficiaries', $atRisk).' below the attendance threshold',
                'detail' => 'Flagged by this school’s rule: '.$overview->rule->describe().'.',
                'action' => ['label' => 'Review attendance', 'url' => route('dashboard.school-head.masterlist', ['attendance' => 'at_risk'])],
            ];
        }

        // 2. Consent the school is required to hold and does not.
        if ($consent['missing'] > 0 && $consent['required'] > 0) {
            $below = $consent['rate'] !== null && $consent['rate'] < self::CONSENT_TARGET_PERCENT;
            $items[] = [
                'severity' => $below ? 'high' : 'medium',
                'title' => $consent['missing'].' '.$this->plural('learner', 'learners', $consent['missing']).' without valid health services consent',
                'detail' => 'Completion is '.$this->percent((float) ($consent['rate'] ?? 0)).'% of '.$consent['required'].' on the roll.',
                'action' => ['label' => 'Open consent compliance', 'url' => route('dashboard.school-head.consent')],
            ];
        }

        // 3. Stock the clinic can no longer dispense from.
        if ($inventory['out'] > 0) {
            $items[] = [
                'severity' => 'high',
                'title' => $inventory['out'].' '.$this->plural('medicine', 'medicines', $inventory['out']).' out of stock',
                'detail' => 'The clinic cannot dispense them until they are received.',
                'action' => ['label' => 'Open inventory', 'url' => route('dashboard.school-head.inventory')],
            ];
        }

        if ($inventory['low'] > 0) {
            $items[] = [
                'severity' => 'medium',
                'title' => $inventory['low'].' '.$this->plural('medicine', 'medicines', $inventory['low']).' below the reorder threshold',
                'detail' => 'Each is under the minimum the clinic set for it.',
                'action' => ['label' => 'Open inventory', 'url' => route('dashboard.school-head.inventory')],
            ];
        }

        // 4. A report is only as good as the marks behind it, so unread ones
        //    are named before anything is concluded from them.
        $unconfirmed = $overview->unconfirmedMarkCount();
        if ($unconfirmed > 0) {
            $items[] = [
                'severity' => 'medium',
                'title' => $unconfirmed.' scanned '.$this->plural('mark', 'marks', $unconfirmed).' still unconfirmed',
                'detail' => 'Until somebody reads them they count neither as attendance nor as absence.',
                'action' => ['label' => 'Open feeding program', 'url' => route('dashboard.school-head.program')],
            ];
        }

        // 5. Today's session. Only once the cycle is actually running — before
        //    the first sheet there is no session to be missing.
        if ($overview->cycle->hasStarted() && $sessionDates !== [] && ! in_array($today, $sessionDates, true)) {
            $items[] = [
                'severity' => 'medium',
                'title' => 'Today’s feeding session has not been recorded',
                'detail' => 'The last recorded feeding day was '
                    .Carbon::parse((string) $sessionDates[count($sessionDates) - 1])->format('j F Y').'.',
                'action' => ['label' => 'Open feeding program', 'url' => route('dashboard.school-head.program')],
            ];
        }

        // 6. Feeding days whose turnout fell below the monitoring line.
        $lowDays = collect($overview->sessions())->where('state', 'low');
        if ($lowDays->isNotEmpty()) {
            $worst = $lowDays->sortBy('rate')->first();
            $items[] = [
                'severity' => 'high',
                'title' => $lowDays->count().' feeding '.$this->plural('day', 'days', $lowDays->count())
                    .' below '.$this->percent(SchoolHeadOverview::FULL_TURNOUT_PERCENT).'% turnout',
                'detail' => 'Lowest was '.$worst['label'].' at '.$this->percent((float) $worst['rate']).'%.',
                'action' => ['label' => 'Open feeding program', 'url' => route('dashboard.school-head.program')],
            ];
        }

        // 7. Qualified by the adviser's measurement, not yet enrolled by the
        //    coordinator. The head cannot enrol — this is visibility, not an act.
        $pending = $overview->pendingEnrollment()->count();
        if ($pending > 0) {
            $items[] = [
                'severity' => 'medium',
                'title' => $pending.' qualified '.$this->plural('learner', 'learners', $pending).' awaiting enrolment',
                'detail' => 'Measured into a qualifying status; the Feeding Coordinator enrols them.',
                'action' => ['label' => 'View learners', 'url' => route('dashboard.school-head.masterlist', ['standing' => 'pending'])],
            ];
        }

        // 8. A beneficiary with no baseline has nothing to be compared against
        //    at endline, so the whole outcome report loses them.
        $noBaseline = $overview->beneficiaries
            ->filter(fn (StudentHealthRecord $record): bool => SchoolHeadOverview::phaseStatus($record, 'baseline') === '')
            ->count();
        if ($noBaseline > 0) {
            $items[] = [
                'severity' => 'medium',
                'title' => $noBaseline.' '.$this->plural('beneficiary', 'beneficiaries', $noBaseline).' have no baseline measurement',
                'detail' => 'Without one they cannot appear in the baseline-to-endline comparison.',
                'action' => ['label' => 'Open masterlist', 'url' => route('dashboard.school-head.masterlist', ['baseline' => 'not_measured'])],
            ];
        }

        // 9. Clinic referrals this month: a learner sent out of school is a
        //    management fact, not a clinical one.
        if ($clinic['referred_this_month'] > 0) {
            $items[] = [
                'severity' => 'info',
                'title' => $clinic['referred_this_month'].' clinic '.$this->plural('referral', 'referrals', $clinic['referred_this_month'])
                    .' in '.$clinic['month_label'],
                'detail' => 'Out of '.$clinic['this_month'].' '.$this->plural('consultation', 'consultations', $clinic['this_month']).' logged this month.',
                'action' => ['label' => 'Open health overview', 'url' => route('dashboard.school-head.health')],
            ];
        }

        // 10. The endline: overdue once the cycle has run its length, a heads-up
        //     while it is still inside the last stretch.
        $outcome = $overview->outcome();
        if ($outcome['beneficiaries'] > 0 && $outcome['not_measured'] > 0) {
            if ($overview->cycle->isComplete()) {
                $items[] = [
                    'severity' => 'high',
                    'title' => $outcome['not_measured'].' '.$this->plural('beneficiary', 'beneficiaries', $outcome['not_measured']).' have no endline measurement',
                    'detail' => 'The cycle has run its '.FeedingProgramCycle::DURATION_DAYS.' days; the endline report stays a draft until they are measured.',
                    'action' => ['label' => 'Open reports', 'url' => route('dashboard.school-head.reports')],
                ];
            } elseif ($overview->cycle->hasStarted() && $overview->cycle->daysRemaining() <= self::ENDLINE_NOTICE_DAYS) {
                $items[] = [
                    'severity' => 'info',
                    'title' => 'Endline weighing opens in '.$overview->cycle->daysRemaining().' '.$this->plural('day', 'days', $overview->cycle->daysRemaining()),
                    'detail' => 'Class advisers record the closing measurement for each beneficiary.',
                    'action' => ['label' => 'Open reports', 'url' => route('dashboard.school-head.reports')],
                ];
            }
        }

        // Worst first, so the row the head should open is the row at the top.
        $rank = ['high' => 0, 'medium' => 1, 'info' => 2];
        usort($items, fn (array $a, array $b): int => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $items;
    }

    /**
     * One row per grade level: how much of it is undernourished now, and which
     * way that has moved since the baseline.
     *
     * @return list<array<string, mixed>>
     */
    private function buildGradeSnapshot(SchoolHeadOverview $overview): array
    {
        $baseline = collect($overview->gradeBreakdown('baseline'))->keyBy('label');

        return collect($overview->gradeBreakdown('latest'))
            ->map(function (array $row) use ($baseline): array {
                $before = $baseline->get($row['label'])['undernourished'] ?? 0;

                return $row + [
                    'baseline_undernourished' => $before,
                    'change' => $row['undernourished'] - $before,
                    // Null, not zero: a grade nobody has measured has no share.
                    'bar' => $row['share'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The three school health programmes, each reported from its own records
     * rather than a fixed schedule: feeding from recorded attendance, deworming
     * from parental consent forms on file, screening from submitted health
     * assessments.
     *
     * @return list<array<string, mixed>>
     */
    private function buildPrograms(SchoolHeadOverview $overview): array
    {
        $recordIds = $overview->records->pluck('id')->all();
        $totalStudents = $overview->records->count();
        $sessions = $overview->daysCompleted();
        $sessionDates = $overview->sessionDates();
        $lastSession = $sessionDates !== [] ? (string) $sessionDates[count($sessionDates) - 1] : null;
        $atRisk = $overview->atRiskCount();

        $consented = 0;
        if ($recordIds !== [] && SchemaCache::hasTable('parental_consent_forms')) {
            $consented = DB::table('parental_consent_forms')
                ->whereIn('student_health_record_id', $recordIds)
                ->where('program_type', 'Deworming')
                ->where('school_year', $overview->schoolYear)
                ->distinct()
                ->count('student_health_record_id');
        }

        $assessed = 0;
        if ($recordIds !== [] && SchemaCache::hasTable('health_assessments')) {
            $assessed = DB::table('health_assessments')
                ->whereIn('student_health_record_id', $recordIds)
                ->where('school_year', $overview->schoolYear)
                ->distinct()
                ->count('student_health_record_id');
        }

        // The nurse's own examination sits on the record, so a learner counts as
        // screened either way.
        $examined = $overview->records->filter(fn ($student) => ! empty($student->examination))->count();
        $screened = max($assessed, $examined);

        return [
            [
                'label' => 'Feeding Program',
                'detail' => $sessions > 0
                    ? $sessions.' feeding '.$this->plural('day', 'days', $sessions).' recorded'
                        .($lastSession ? ' · last '.Carbon::parse($lastSession)->format('M j') : '')
                    : 'No attendance recorded yet',
                'note' => $atRisk > 0 ? $atRisk.' '.$this->plural('learner', 'learners', $atRisk).' at risk' : null,
                'status' => $sessions > 0 ? 'Active' : 'Not started',
                'tone' => $sessions > 0 ? 'ok' : 'idle',
                'is_running' => $sessions > 0,
            ],
            [
                'label' => 'Deworming',
                'detail' => $totalStudents > 0
                    ? $consented.' / '.$totalStudents.' consent forms on file'
                    : 'No learners enrolled yet',
                'note' => null,
                'status' => match (true) {
                    $totalStudents > 0 && $consented >= $totalStudents => 'Complete',
                    $consented > 0 => 'In progress',
                    default => 'Awaiting consent',
                },
                'tone' => match (true) {
                    $totalStudents > 0 && $consented >= $totalStudents => 'ok',
                    $consented > 0 => 'warn',
                    default => 'idle',
                },
                'is_running' => $consented > 0,
            ],
            [
                'label' => 'Health Screening',
                'detail' => $totalStudents > 0
                    ? $screened.' / '.$totalStudents.' learners screened'
                    : 'No learners enrolled yet',
                'note' => null,
                'status' => match (true) {
                    $totalStudents > 0 && $screened >= $totalStudents => 'Completed',
                    $screened > 0 => 'In progress',
                    default => 'Not started',
                },
                'tone' => match (true) {
                    $totalStudents > 0 && $screened >= $totalStudents => 'ok',
                    $screened > 0 => 'warn',
                    default => 'idle',
                },
                'is_running' => $screened > 0,
            ],
        ];
    }

    /**
     * A fingerprint of every table the head's screens read — see
     * SchoolHeadPulse, which every School Head tab shares so that an adviser's
     * or a nurse's entry reaches all of them on the same signal.
     */
    private function metricsStamp(Request $request): string
    {
        return SchoolHeadPulse::stamp($request->session()->get('active_institution_id'));
    }

    private function isSchoolHead(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'school_head';
    }

    private function plural(string $singular, string $plural, int $count): string
    {
        return $count === 1 ? $singular : $plural;
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
