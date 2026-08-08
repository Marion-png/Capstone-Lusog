<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\SchemaCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SchoolHeadController extends Controller
{
    /**
     * @var array<int, array<string, string|int>>
     */
    private const APPROVALS = [
    ];

    /** Tables whose rows can move any number on the dashboard. */
    private const WATCHED_TABLES = [
        'student_health_records',
        'parental_consent_forms',
        'health_assessments',
        'feeding_attendances',
        'attendance_imports',
    ];

    public function index(Request $request): View
    {
        $decisions = $request->session()->get('school_head_approval_decisions', []);

        $approvals = collect(self::APPROVALS)
            ->filter(fn (array $approval): bool => ! isset($decisions[(string) $approval['id']]))
            ->values();

        return view('schoolhead-dashboard.school-head', [
            'approvals' => $approvals,
            // Seeded so the page's first pulse compares against what it was
            // rendered from, instead of rebuilding metrics it already has.
            'stamp' => $this->metricsStamp($request),
        ] + $this->buildMetrics($request, $approvals->count()));
    }

    /**
     * The dashboard's live numbers, re-read on demand. Everything the page
     * shows is computed here, so the first paint and every later refresh come
     * from one implementation and can never drift apart.
     */
    public function metrics(Request $request): JsonResponse
    {
        if ($request->session()->get('active_role') !== 'school_head') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $decisions = $request->session()->get('school_head_approval_decisions', []);
        $pending = collect(self::APPROVALS)
            ->filter(fn (array $approval): bool => ! isset($decisions[(string) $approval['id']]))
            ->count();

        $metrics = $this->buildMetrics($request, $pending);

        return response()->json([
            'stamp' => $this->metricsStamp($request),
            'generatedAt' => $metrics['generatedAt'],
            'html' => [
                'stats' => view('schoolhead-dashboard.partials.stat-cards', $metrics)->render(),
                'programs' => view('schoolhead-dashboard.partials.program-overview', $metrics)->render(),
                'chart' => view('schoolhead-dashboard.partials.status-chart', $metrics)->render(),
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
        if ($request->session()->get('active_role') !== 'school_head') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['stamp' => $this->metricsStamp($request)]);
    }

    /**
     * @return array{stats: array<string, mixed>, gradeChart: array<int, array<string, mixed>>, chartAxis: array<string, mixed>, programs: array<int, array<string, string>>, generatedAt: string}
     */
    private function buildMetrics(Request $request, int $pendingApprovals): array
    {
        // Count only the students entered by class advisers of THIS school
        // head's own school. Records are scoped by the same school identity the
        // advisers store on creation, so students from other schools are never
        // counted.
        $schoolName = $request->session()->get('active_school_name');
        $institutionId = $request->session()->get('active_institution_id');
        $schoolYear = StudentHealthRecord::currentSchoolYear();

        $students = collect();

        if (SchemaCache::hasTable('student_health_records') && ($institutionId || $schoolName)) {
            $query = StudentHealthRecord::query();

            if ($institutionId) {
                $query->where('institution_id', $institutionId);
            } else {
                $query->where('school_name', $schoolName);
            }

            $students = $query->forCurrentSchoolYear($schoolYear)
                ->get(['id', 'section', 'nutritional_status', 'baseline_nutritional_status', 'is_at_risk', 'examination']);
        }

        $totalStudents = $students->count();
        $recordIds = $students->pluck('id')->all();

        $wastedCount = 0;
        $gradeBuckets = []; // grade number => ['healthy' => int, 'risk' => int]

        foreach ($students as $student) {
            // nutritional_status is encrypted at rest, so the classification
            // happens here in PHP rather than in a SQL predicate.
            $status = strtolower((string) ($student->nutritional_status ?: $student->baseline_nutritional_status));
            $isAtRisk = $status !== '' && (bool) preg_match('/wast|under|over|obes|severe/', $status);

            if (str_contains($status, 'wast')) {
                $wastedCount++; // covers "wasted" and "severely wasted"
            }

            $grade = $this->resolveGrade((string) $student->section);
            if ($grade === null) {
                continue;
            }

            $bucket = $gradeBuckets[$grade] ?? ['healthy' => 0, 'risk' => 0];
            $bucket[$isAtRisk ? 'risk' : 'healthy']++;
            $gradeBuckets[$grade] = $bucket;
        }

        ksort($gradeBuckets);

        $programs = $this->buildPrograms($schoolYear, $recordIds, $totalStudents, $students);

        $wastedRate = $totalStudents > 0
            ? round(($wastedCount / $totalStudents) * 100, 1).'%'
            : '0%';

        return [
            'gradeChart' => $this->buildGradeChart($gradeBuckets),
            'chartAxis' => $this->buildChartAxis($gradeBuckets),
            'programs' => $programs,
            'generatedAt' => now()->format('g:i A'),
            'stats' => [
                'total_students' => $totalStudents,
                'pending_approvals' => $pendingApprovals,
                'active_programs' => collect($programs)->where('is_running', true)->count(),
                'wasted_rate' => $wastedRate,
                'wasted_count' => $wastedCount,
            ],
        ];
    }

    /**
     * One column per grade, each split into a healthy and an at-risk segment.
     * Heights are a share of the axis maximum (buildChartAxis), so a column's
     * height reads against the gridlines instead of against the tallest bar.
     *
     * @param  array<int, array{healthy: int, risk: int}>  $gradeBuckets
     * @return array<int, array<string, mixed>>
     */
    private function buildGradeChart(array $gradeBuckets): array
    {
        $axisMax = $this->axisMax($gradeBuckets);
        $gradeChart = [];

        foreach ($gradeBuckets as $grade => $bucket) {
            $gradeTotal = $bucket['healthy'] + $bucket['risk'];

            $gradeChart[] = [
                'label' => 'Grade '.$grade,
                'short_label' => 'G'.$grade,
                'healthy' => $bucket['healthy'],
                'risk' => $bucket['risk'],
                'total' => $gradeTotal,
                'healthy_pct' => $axisMax > 0 ? round(($bucket['healthy'] / $axisMax) * 100, 2) : 0,
                'risk_pct' => $axisMax > 0 ? round(($bucket['risk'] / $axisMax) * 100, 2) : 0,
            ];
        }

        return $gradeChart;
    }

    /**
     * Gridline values for the chart, top down. Ticks are whole learners on a
     * clean step (1/2/5 × 10ⁿ) so "12 learners" never lands between two lines.
     *
     * @param  array<int, array{healthy: int, risk: int}>  $gradeBuckets
     * @return array{max: int, ticks: array<int, int>, healthy: int, risk: int}
     */
    private function buildChartAxis(array $gradeBuckets): array
    {
        $max = $this->axisMax($gradeBuckets);
        $step = $max > 0 ? $max / 4 : 0;

        $ticks = [];
        for ($i = 4; $i >= 0; $i--) {
            $ticks[] = (int) round($step * $i);
        }

        return [
            'max' => $max,
            'ticks' => $ticks,
            'healthy' => array_sum(array_column($gradeBuckets, 'healthy')),
            'risk' => array_sum(array_column($gradeBuckets, 'risk')),
        ];
    }

    /**
     * The tallest column rounded up to a clean multiple of four, so the four
     * gridlines above the baseline all land on whole learners.
     *
     * @param  array<int, array{healthy: int, risk: int}>  $gradeBuckets
     */
    private function axisMax(array $gradeBuckets): int
    {
        $tallest = 0;
        foreach ($gradeBuckets as $bucket) {
            $tallest = max($tallest, $bucket['healthy'] + $bucket['risk']);
        }

        if ($tallest === 0) {
            return 0;
        }

        // Step through 1, 2, 5, 10, 20, 50, … until four steps clear the tallest
        // column; that keeps every tick a whole learner at any roster size.
        for ($magnitude = 1; $magnitude <= 1_000_000; $magnitude *= 10) {
            foreach ([1, 2, 5] as $unit) {
                $step = $unit * $magnitude;
                if ($step * 4 >= $tallest) {
                    return $step * 4;
                }
            }
        }

        return $tallest;
    }

    /**
     * The three school health programmes, each reported from its own records
     * rather than a fixed schedule: feeding from uploaded attendance, deworming
     * from parental consent forms on file, screening from submitted health
     * assessments.
     *
     * @param  array<int, int>  $recordIds
     * @param  Collection<int, StudentHealthRecord>  $students
     * @return array<int, array<string, mixed>>
     */
    private function buildPrograms(
        string $schoolYear,
        array $recordIds,
        int $totalStudents,
        Collection $students,
    ): array {
        $sessions = 0;
        $lastSession = null;

        if ($recordIds !== [] && SchemaCache::hasTable('feeding_attendances')) {
            // session_date stays plain precisely so it can be aggregated here.
            $row = DB::table('feeding_attendances')
                ->whereIn('student_health_record_id', $recordIds)
                ->selectRaw('COUNT(DISTINCT session_date) as session_count, MAX(session_date) as last_session')
                ->first();

            $sessions = (int) ($row->session_count ?? 0);
            $lastSession = $row->last_session ?? null;
        }

        $atRisk = $students->where('is_at_risk', true)->count();

        $consented = 0;
        if ($recordIds !== [] && SchemaCache::hasTable('parental_consent_forms')) {
            $consented = DB::table('parental_consent_forms')
                ->whereIn('student_health_record_id', $recordIds)
                ->where('program_type', 'Deworming')
                ->where('school_year', $schoolYear)
                ->distinct()
                ->count('student_health_record_id');
        }

        $assessed = 0;
        if ($recordIds !== [] && SchemaCache::hasTable('health_assessments')) {
            $assessed = DB::table('health_assessments')
                ->whereIn('student_health_record_id', $recordIds)
                ->where('school_year', $schoolYear)
                ->distinct()
                ->count('student_health_record_id');
        }

        // The nurse's own examination sits on the record, so a learner counts as
        // screened either way.
        $examined = $students->filter(fn ($student) => ! empty($student->examination))->count();
        $screened = max($assessed, $examined);

        return [
            [
                'label' => 'Feeding Program',
                'detail' => $sessions > 0
                    ? $sessions.' feeding '.($sessions === 1 ? 'day' : 'days').' recorded'
                        .($lastSession ? ' · last '.Carbon::parse($lastSession)->format('M j') : '')
                    : 'No attendance uploaded yet',
                'note' => $atRisk > 0 ? $atRisk.' learner'.($atRisk === 1 ? '' : 's').' at risk' : null,
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

    /**
     * Pull the grade number out of a free-form section string
     * (e.g. "Grade 12/SPED / SPED-B" => 12). Returns null when absent.
     */
    private function resolveGrade(string $section): ?int
    {
        if (preg_match('/grade\s*(\d{1,2})/i', $section, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function reports(): View
    {
        return view('schoolhead-dashboard.school-headreport', [
            'reportStats' => [
                'submission_rate' => '96.2%',
                'open_findings' => 4,
                'completed_reports' => 12,
                'overdue_reports' => 1,
            ],
        ]);
    }

    public function decide(Request $request, int $approval, string $decision): RedirectResponse
    {
        $approvalExists = collect(self::APPROVALS)->contains(
            fn (array $item): bool => (int) $item['id'] === $approval
        );

        if (! $approvalExists) {
            return back()->with('error', 'Approval request not found.');
        }

        $decisions = $request->session()->get('school_head_approval_decisions', []);
        $decisions[(string) $approval] = $decision;
        $request->session()->put('school_head_approval_decisions', $decisions);

        $message = $decision === 'approve'
            ? 'Request approved successfully.'
            : 'Request declined successfully.';

        return back()->with('success', $message);
    }
}
