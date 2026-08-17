<?php

namespace App\Http\Controllers;

use App\Support\FeedingProgramCycle;
use App\Support\SchoolHeadOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The School Head's Feeding Program tab — "what has been happening".
 *
 * It reads the marks the Feeding Coordinator wrote and offers no way to change
 * one: the head monitors the programme, and a second write path into
 * feeding_attendances is how two screens start disagreeing about whether a
 * child was fed. Nothing on this page posts anything.
 *
 * Three readings, all from one SchoolHeadOverview so they cannot contradict each
 * other: the feeding-day grid, the cycle's statistics and the school's nutritional
 * status by grade level.
 */
class SchoolHeadProgramController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can open this page.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $overview = SchoolHeadOverview::for($institutionId);

        // The one control on the page: which weighing the status chart draws.
        $phase = $request->query('phase') === 'baseline' ? 'baseline' : 'latest';

        return view('schoolhead-dashboard.program', [
            'schoolName' => $request->session()->get('active_school_name', 'School'),
            'schoolYear' => $overview->schoolYear,
            'todayLabel' => now()->format('j F Y'),
            'phase' => $phase,
            'grid' => $this->buildGrid($overview),
            'legend' => $this->legend(),
            'stats' => $this->buildStats($overview),
            'chart' => $this->buildDivergingChart($overview, $phase),
            'callout' => $this->buildCallout($overview, $phase),
            'rule' => $overview->rule->describe(),
            'atRisk' => $overview->atRiskCount(),
            'observing' => $overview->observingCount(),
        ]);
    }

    /**
     * The cycle as 120 cells, one per planned feeding day.
     *
     * Cell *n* is the nth recorded session, never the nth calendar day — a day
     * the school did not feed is not a feeding day, so it is drawn as "not yet
     * reached" rather than as a gap in the middle of the run.
     *
     * @return list<array<string, mixed>>
     */
    private function buildGrid(SchoolHeadOverview $overview): array
    {
        $sessions = collect($overview->sessions())->keyBy('day');
        $today = now()->toDateString();
        $cells = [];

        for ($day = 1; $day <= FeedingProgramCycle::DURATION_DAYS; $day++) {
            $session = $sessions->get($day);

            if ($session === null) {
                $cells[] = [
                    'day' => $day,
                    'state' => 'upcoming',
                    'title' => 'Day '.$day.' — not yet reached',
                ];

                continue;
            }

            $isToday = $session['date'] === $today;

            $cells[] = [
                'day' => $day,
                'state' => $isToday ? 'today' : $session['state'],
                'title' => 'Day '.$day.' — '.$session['label'].' · '
                    .($session['rate'] === null
                        ? 'no confirmed mark yet'
                        : $session['present'].' of '.($session['present'] + $session['absent'])
                            .' present ('.$this->percent((float) $session['rate']).'%)'),
            ];
        }

        return $cells;
    }

    /**
     * @return list<array{state: string, label: string}>
     */
    private function legend(): array
    {
        return [
            ['state' => 'fed', 'label' => 'Fed ('.$this->percent(SchoolHeadOverview::FULL_TURNOUT_PERCENT).'% turnout or better)'],
            ['state' => 'low', 'label' => 'Low turnout'],
            ['state' => 'review', 'label' => 'Marks not yet confirmed'],
            ['state' => 'today', 'label' => 'Today'],
            ['state' => 'upcoming', 'label' => 'Not yet reached'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(SchoolHeadOverview $overview): array
    {
        $served = $overview->mealsServed();
        $planned = $overview->mealsPlanned();

        return [
            'beneficiaries' => $overview->beneficiaries->count(),
            'meals_served' => $served,
            'meals_planned' => $planned,
            // Null rather than 0%: with no feeding day yet there is nothing to
            // take a share of.
            'meals_percent' => $planned > 0 ? round(($served / $planned) * 100, 1) : null,
            'turnout' => $overview->averageTurnout(),
            'days_completed' => $overview->daysCompleted(),
            'days_remaining' => $overview->daysRemaining(),
            'duration' => FeedingProgramCycle::DURATION_DAYS,
            'day' => $overview->cycle->day(),
            'started' => $overview->cycle->hasStarted(),
            'percent' => $overview->cycle->percent(),
        ];
    }

    /**
     * Nutritional status by grade level as a diverging bar: undernutrition
     * extends left of the centre line, normal-and-above extends right.
     *
     * Every bar shares one scale (the widest half of any grade), so a segment
     * twice as long really is twice as many learners. Learners nobody has
     * measured are kept out of the bar and reported beside it — a bar cannot
     * honestly draw a reading that was never taken.
     *
     * @return array<string, mixed>
     */
    private function buildDivergingChart(SchoolHeadOverview $overview, string $phase): array
    {
        $rows = $overview->gradeBreakdown($phase);

        $axisMax = 1;
        foreach ($rows as $row) {
            $left = $row['counts']['Severely Wasted'] + $row['counts']['Wasted'];
            $right = $row['counts']['Normal'] + $row['counts']['Overweight'] + $row['counts']['Obese'];
            $axisMax = max($axisMax, $left, $right);
        }

        $segments = [
            ['key' => 'Severely Wasted', 'side' => 'left', 'tone' => 'sw'],
            ['key' => 'Wasted', 'side' => 'left', 'tone' => 'w'],
            ['key' => 'Normal', 'side' => 'right', 'tone' => 'n'],
            ['key' => 'Overweight', 'side' => 'right', 'tone' => 'ow'],
            ['key' => 'Obese', 'side' => 'right', 'tone' => 'ob'],
        ];

        $bars = [];

        foreach ($rows as $row) {
            $left = [];
            $right = [];

            foreach ($segments as $segment) {
                $count = $row['counts'][$segment['key']];

                if ($count === 0) {
                    continue;
                }

                $piece = [
                    'label' => $segment['key'],
                    'tone' => $segment['tone'],
                    'count' => $count,
                    // Each half of the track is half its width, and a segment's
                    // width is its share of that half — so one scale governs
                    // both sides and a segment twice as long is twice as many
                    // learners, whichever side of the centre line it is on.
                    'width' => round(($count / $axisMax) * 100, 2),
                    // Of the learners measured in that grade — the same
                    // denominator every other share on this page is taken over.
                    'share' => $row['measured'] > 0 ? round(($count / $row['measured']) * 100, 1) : 0.0,
                ];

                if ($segment['side'] === 'left') {
                    $left[] = $piece;
                } else {
                    $right[] = $piece;
                }
            }

            $bars[] = [
                'label' => $row['label'],
                'total' => $row['total'],
                'measured' => $row['measured'],
                'not_measured' => $row['counts'][SchoolHeadOverview::NOT_MEASURED],
                'undernourished' => $row['undernourished'],
                'share' => $row['share'],
                // Left segments are drawn outward from the centre, so the worst
                // category sits furthest from it.
                'left' => array_reverse($left),
                'right' => $right,
            ];
        }

        return [
            'bars' => $bars,
            'axis_max' => $axisMax,
            'totals' => $overview->statusCounts($phase),
            'phase_label' => $phase === 'baseline' ? 'Baseline' : 'Latest',
        ];
    }

    /**
     * The chart in one sentence, so the reading does not depend on hover.
     *
     * @return array<string, mixed>
     */
    private function buildCallout(SchoolHeadOverview $overview, string $phase): array
    {
        $rows = $overview->gradeBreakdown($phase);
        $counts = $overview->statusCounts($phase);
        $undernourished = $counts['Severely Wasted'] + $counts['Wasted'];

        $worst = null;
        foreach ($rows as $row) {
            if ($row['counts']['Severely Wasted'] === 0) {
                continue;
            }
            if ($worst === null || $row['counts']['Severely Wasted'] > $worst['counts']['Severely Wasted']) {
                $worst = $row;
            }
        }

        return [
            'undernourished' => $undernourished,
            'measured' => $overview->records->count() - $counts[SchoolHeadOverview::NOT_MEASURED],
            'total' => $overview->records->count(),
            'not_measured' => $counts[SchoolHeadOverview::NOT_MEASURED],
            'worst_grade' => $worst['label'] ?? null,
            'worst_count' => $worst['counts']['Severely Wasted'] ?? 0,
            'phase_label' => $phase === 'baseline' ? 'baseline weighing' : 'latest weighing',
        ];
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    private function isSchoolHead(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'school_head';
    }
}
