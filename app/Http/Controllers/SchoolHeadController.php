<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\FeedingProgramCycle;
use App\Support\SchemaCache;
use App\Support\SchoolHeadOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The School Head's Dashboard — "what needs me today".
 *
 * It is deliberately not a smaller copy of the other three tabs. Those answer
 * how the programme is running, what has to be signed and who is on the roll;
 * this one surfaces the exceptions and hands off to whichever tab resolves
 * them. Every item in the action queue is generated from a live condition in
 * the school's own records — there is no fixed list, and an item disappears the
 * moment the condition it describes stops being true.
 *
 * Every figure comes from SchoolHeadOverview, which all four tabs read, so the
 * Dashboard's count of undernourished learners and the Reports tab's count of
 * the same children cannot drift apart.
 */
class SchoolHeadController extends Controller
{
    /** Tables whose rows can move any number on the dashboard. */
    private const WATCHED_TABLES = [
        'student_health_records',
        'parental_consent_forms',
        'health_assessments',
        'feeding_attendances',
        'attendance_imports',
    ];

    /** How close the cycle has to be to its end before the head is warned. */
    private const ENDLINE_NOTICE_DAYS = 30;

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
        $overview = SchoolHeadOverview::for($institutionId);

        $trend = $overview->undernourishedTrend();
        $outcome = $overview->outcome();
        $turnout = $overview->averageTurnout();
        $sections = $overview->records
            ->map(fn (StudentHealthRecord $record): string => trim((string) $record->section))
            ->filter()
            ->unique()
            ->count();

        return [
            'headName' => trim((string) $request->session()->get('active_name', '')) ?: 'School Head',
            'schoolName' => $request->session()->get('active_school_name', 'this school'),
            'schoolYear' => $overview->schoolYear,
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
            ],
            'queue' => $this->buildQueue($overview),
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
     * The queue, generated from live conditions.
     *
     * Every item names the condition, the figure behind it and where it is
     * resolved. None of them is a stored to-do: an item is present because the
     * records say so this second, and gone when they no longer do.
     *
     * @return list<array<string, mixed>>
     */
    private function buildQueue(SchoolHeadOverview $overview): array
    {
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

        // 2. A report is only as good as the marks behind it, so unread ones
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

        // 3. Today's session. Only once the cycle is actually running — before
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

        // 4. Feeding days whose turnout fell below the monitoring line.
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

        // 5. Qualified by the adviser's measurement, not yet enrolled by the
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

        // 6. A beneficiary with no baseline has nothing to be compared against
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

        // 7. The endline: overdue once the cycle has run its length, a heads-up
        //    while it is still inside the last stretch.
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
     * A fingerprint of every table the dashboard reads. Row counts and
     * last-touched timestamps only — no column that holds personal data is
     * touched, so the polling endpoint stays free of it.
     */
    private function metricsStamp(Request $request): string
    {
        $institutionId = $request->session()->get('active_institution_id');
        $parts = [];

        foreach (self::WATCHED_TABLES as $table) {
            if (! SchemaCache::hasTable($table)) {
                $parts[] = '-';

                continue;
            }

            $query = DB::table($table);
            // The child tables inherit their school scope from the parent
            // record, so only the owning tables filter. A neighbouring school's
            // write can therefore cost one needless refetch — never a missed
            // change, and nothing of theirs is ever read.
            if ($institutionId && SchemaCache::hasColumn($table, 'institution_id')) {
                $query->where('institution_id', $institutionId);
            }

            $row = $query->selectRaw('COUNT(*) as row_count, MAX(updated_at) as last_touched')->first();
            $parts[] = ((int) ($row->row_count ?? 0)).'@'.((string) ($row->last_touched ?? ''));
        }

        return md5(implode('|', $parts));
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
