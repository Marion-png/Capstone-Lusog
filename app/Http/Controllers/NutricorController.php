<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use Illuminate\View\View;

class NutricorController extends Controller
{
    /** Ordered nutritional status categories (matches AdviserController classification). */
    private const CATEGORIES = [
        'Severely Wasted',
        'Wasted',
        'Underweight',
        'Normal',
        'Overweight',
    ];

    /**
     * Consolidated school-wide nutritional assessment report for the
     * Nutritional Coordinator.  Aggregates baseline AND endline data
     * submitted by all Class Advisers for the coordinator's school.
     */
    public function consolidatedReport(): View
    {
        abort_unless(session('active_role') === 'nutricor', 403);

        $schoolName = session('active_school_name');

        // Single query — all relevant columns for the school.
        $records = StudentHealthRecord::query()
            ->when($schoolName, fn ($q) => $q->where('school_name', $schoolName))
            ->get(['section', 'baseline_nutritional_status', 'endline_nutritional_status']);

        // ── Baseline ──────────────────────────────────────────────────────────
        $baselineRows  = $records->filter(fn ($r) => $r->baseline_nutritional_status !== null
            && $r->baseline_nutritional_status !== 'Not enough data');
        $baselineTotal = $baselineRows->count();

        $baselineCounts = [];
        foreach (self::CATEGORIES as $cat) {
            $n = $baselineRows->where('baseline_nutritional_status', $cat)->count();
            $baselineCounts[$cat] = [
                'count'   => $n,
                'percent' => $baselineTotal > 0 ? round($n / $baselineTotal * 100, 1) : 0.0,
            ];
        }

        // ── Endline ───────────────────────────────────────────────────────────
        $endlineRows  = $records->filter(fn ($r) => $r->endline_nutritional_status !== null
            && $r->endline_nutritional_status !== 'Not enough data');
        $endlineTotal = $endlineRows->count();

        $endlineCounts = [];
        foreach (self::CATEGORIES as $cat) {
            $n = $endlineRows->where('endline_nutritional_status', $cat)->count();
            $endlineCounts[$cat] = [
                'count'   => $n,
                'percent' => $endlineTotal > 0 ? round($n / $endlineTotal * 100, 1) : 0.0,
            ];
        }

        // ── Total enrolled (at least one valid assessment) ────────────────────
        $totalStudents = $records->filter(
            fn ($r) => ($r->baseline_nutritional_status !== null && $r->baseline_nutritional_status !== 'Not enough data')
                    || ($r->endline_nutritional_status  !== null && $r->endline_nutritional_status  !== 'Not enough data')
        )->count();

        // ── Per-section breakdown ─────────────────────────────────────────────
        $sectionBreakdown = $records
            ->groupBy('section')
            ->map(function ($rows, $section) {
                $bl = $rows->filter(fn ($r) => $r->baseline_nutritional_status !== null
                    && $r->baseline_nutritional_status !== 'Not enough data');
                $el = $rows->filter(fn ($r) => $r->endline_nutritional_status !== null
                    && $r->endline_nutritional_status  !== 'Not enough data');

                $blCount = $bl->count();
                $elCount = $el->count();

                $baselineByCat = [];
                $endlineByCat  = [];
                foreach (self::CATEGORIES as $cat) {
                    $baselineByCat[$cat] = $bl->where('baseline_nutritional_status', $cat)->count();
                    $endlineByCat[$cat]  = $el->where('endline_nutritional_status',  $cat)->count();
                }

                return [
                    'total'          => $rows->count(),
                    'baseline_count' => $blCount,
                    'endline_count'  => $elCount,
                    'baseline'       => $baselineByCat,
                    'endline'        => $endlineByCat,
                ];
            })
            ->sortKeys();

        return view('nutricor.consolidated-report', [
            'schoolName'       => $schoolName,
            'categories'       => self::CATEGORIES,
            'totalStudents'    => $totalStudents,
            'baselineTotal'    => $baselineTotal,
            'baselineCounts'   => $baselineCounts,
            'endlineTotal'     => $endlineTotal,
            'endlineCounts'    => $endlineCounts,
            'sectionBreakdown' => $sectionBreakdown,
        ]);
    }
}
