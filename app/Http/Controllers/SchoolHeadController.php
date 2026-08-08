<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\SchemaCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SchoolHeadController extends Controller
{
    /**
     * @var array<int, array<string, string|int>>
     */
    private const APPROVALS = [
    ];

    public function index(Request $request): View
    {
        $decisions = $request->session()->get('school_head_approval_decisions', []);

        $approvals = collect(self::APPROVALS)
            ->filter(fn (array $approval): bool => ! isset($decisions[(string) $approval['id']]))
            ->values();

        // Count only the students entered by class advisers of THIS school head's
        // own school. Records are scoped by the same school identity the advisers
        // store on creation, so students from other schools are never counted.
        $schoolName = $request->session()->get('active_school_name');
        $institutionId = $request->session()->get('active_institution_id');

        $totalStudents = 0;
        $wastedCount = 0;
        $gradeBuckets = []; // grade number => ['healthy' => int, 'risk' => int]

        if (SchemaCache::hasTable('student_health_records') && ($institutionId || $schoolName)) {
            $query = StudentHealthRecord::query();

            if ($institutionId) {
                $query->where('institution_id', $institutionId);
            } else {
                $query->where('school_name', $schoolName);
            }

            $students = $query->forCurrentSchoolYear()->get(['section', 'nutritional_status', 'baseline_nutritional_status']);
            $totalStudents = $students->count();

            foreach ($students as $student) {
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
        }

        ksort($gradeBuckets);
        $maxGradeTotal = 0;
        foreach ($gradeBuckets as $bucket) {
            $maxGradeTotal = max($maxGradeTotal, $bucket['healthy'] + $bucket['risk']);
        }

        // Tallest grade fills ~90% of the stack; the rest scale proportionally,
        // split into a healthy/at-risk segment by that grade's own counts.
        $gradeChart = [];
        foreach ($gradeBuckets as $grade => $bucket) {
            $gradeTotal = $bucket['healthy'] + $bucket['risk'];
            $columnPct = $maxGradeTotal > 0 ? ($gradeTotal / $maxGradeTotal) * 90 : 0;

            $gradeChart[] = [
                'label' => 'Grade '.$grade,
                'healthy' => $bucket['healthy'],
                'risk' => $bucket['risk'],
                'healthy_pct' => $gradeTotal > 0 ? round($columnPct * ($bucket['healthy'] / $gradeTotal), 1) : 0,
                'risk_pct' => $gradeTotal > 0 ? round($columnPct * ($bucket['risk'] / $gradeTotal), 1) : 0,
            ];
        }

        $wastedRate = $totalStudents > 0
            ? round(($wastedCount / $totalStudents) * 100, 1).'%'
            : '0%';

        return view('schoolhead-dashboard.school-head', [
            'approvals' => $approvals,
            'gradeChart' => $gradeChart,
            'stats' => [
                'total_students' => $totalStudents,
                'pending_approvals' => $approvals->count(),
                'active_programs' => 2,
                'wasted_rate' => $wastedRate,
                'wasted_count' => $wastedCount,
            ],
        ]);
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
