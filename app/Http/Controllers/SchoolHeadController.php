<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SchoolHeadController extends Controller
{
    /**
     * @var array<int, array<string, string|int>>
     */
    private const APPROVALS = [
        [
            'id' => 1,
            'type' => 'Deworming Tablets',
            'requested_by' => 'Ms. Rodriguez',
            'details' => '25 tablets',
            'date' => '2026-03-25',
        ],
        [
            'id' => 2,
            'type' => 'Medicine Restock',
            'requested_by' => 'Nurse Garcia',
            'details' => 'Paracetamol x200',
            'date' => '2026-03-24',
        ],
    ];

    /**
     * @var array<int, array<string, string>>
     */
    private const REPORT_ROWS = [
        [
            'name' => 'Nutritional Status Summary',
            'owner' => 'School Nurse',
            'period' => 'Q1 2026',
            'status' => 'Submitted',
            'status_class' => 'submitted',
        ],
        [
            'name' => 'Feeding Program Progress',
            'owner' => 'Clinic Staff',
            'period' => 'March 2026',
            'status' => 'Reviewed',
            'status_class' => 'reviewed',
        ],
        [
            'name' => 'Deworming Completion Report',
            'owner' => 'School Nurse',
            'period' => 'Q1 2026',
            'status' => 'Pending Sign-off',
            'status_class' => 'pending',
        ],
    ];

    public function index(Request $request): View
    {
        $decisions = $request->session()->get('school_head_approval_decisions', []);

        $approvals = collect(self::APPROVALS)
            ->filter(fn (array $approval): bool => ! isset($decisions[(string) $approval['id']]))
            ->values();

        return view('schoolhead-dashboard.school-head', [
            'approvals' => $approvals,
            'stats' => [
                'total_students' => 389,
                'pending_approvals' => $approvals->count(),
                'active_programs' => 2,
                'wasted_rate' => '11.8%',
            ],
        ]);
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
            'recentReports' => collect(self::REPORT_ROWS),
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