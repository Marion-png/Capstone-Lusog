<?php

namespace App\Http\Controllers;

use App\Support\ClinicVisualization;
use App\Support\SchoolPulse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The clinic's Data Visualization page.
 *
 * Every chart is drawn from the live tables through ClinicVisualization, and
 * the page keeps itself current the way the School Head's dashboard does: it
 * polls the school's shared change stamp (`workspace.pulse`, a hash with no
 * personal information) and, only when the stamp moves, re-reads `data()` and
 * swaps each chart's panel in place. The first paint and every refresh render
 * the same Blade partials from the same reading, so the two cannot drift — and
 * nothing the reader chose (the weighing on screen, a table view, a hidden
 * series) is lost to a reload.
 *
 * Aggregates only. No learner's name, complaint narrative or treatment is on
 * this page; the counts are the clinic's statistics about the school.
 */
class DataVisualizationController extends Controller
{
    /** The clinic's roles, plus the System Admin who can open every nurse page. */
    private const ROLES = ['school_nurse', 'clinic_staff', 'clinic_teacher', 'system_admin'];

    /** Each live panel and the partial that draws it. */
    public const PANES = [
        'kpis' => 'dashboard.visualization.kpis',
        'nutrition' => 'dashboard.visualization.nutrition',
        'grades' => 'dashboard.visualization.grades',
        'trend' => 'dashboard.visualization.trend',
        'cases' => 'dashboard.visualization.cases',
        'stock' => 'dashboard.visualization.stock',
        'feeding' => 'dashboard.visualization.feeding',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        if (! in_array($role, self::ROLES, true)) {
            $redirectByRole = [
                'class_adviser' => 'dashboard.class-adviser',
                'school_head' => 'dashboard.school-head',
                'feeding_coor' => 'dashboard.feedingcor-dashboard',
                'nutricor' => 'dashboard.nutricor-dashboard',
            ];

            return redirect()->route($redirectByRole[$role] ?? 'login');
        }

        return view('dashboard.data-visualization', $this->read($request) + [
            'stamp' => SchoolPulse::stamp($this->institutionId($request)),
        ]);
    }

    /**
     * The panels, re-rendered for the page's own query string. Read only when
     * the shared stamp has moved or a filter changed, never on a timer.
     */
    public function data(Request $request): JsonResponse
    {
        if (! in_array((string) $request->session()->get('active_role', ''), self::ROLES, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $this->read($request);

        $html = [];
        foreach (self::PANES as $pane => $view) {
            $html[$pane] = view($view, $data)->render();
        }

        return response()->json([
            'stamp' => SchoolPulse::stamp($this->institutionId($request)),
            'filters' => $data['filters'],
            'sections' => $data['filterOptions']['sections'],
            'html' => $html,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(Request $request): array
    {
        return ClinicVisualization::read($this->institutionId($request), $request->query());
    }

    private function institutionId(Request $request): ?int
    {
        $id = $request->session()->get('active_institution_id');

        return $id ? (int) $id : null;
    }
}
