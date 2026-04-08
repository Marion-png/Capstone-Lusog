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