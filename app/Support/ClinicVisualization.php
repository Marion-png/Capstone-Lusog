<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\StudentHealthRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The clinic's Data Visualization page, read once.
 *
 * Every chart on the page is drawn from the live tables at read time. Nothing
 * here is stored and nothing is computed a second time: the nutritional and
 * feeding figures come from SchoolHeadOverview, the clinic and inventory
 * figures from SchoolHeadHealthOverview — the same two readings the School
 * Head's tabs print — so the nurse's chart and the head's panel cannot report
 * different numbers for one school. What this class adds is only the geometry
 * a chart needs (shares, axis scales, point positions), never a new rule about
 * who counts.
 *
 * The rules those readings keep hold here too:
 *
 * - A learner nobody weighed is "Not measured", never Normal, and every share
 *   is taken over the learners actually measured. A share over nobody is
 *   NULL — an em dash on screen — never 0%.
 * - The period is the school year; the consultation trend reads June through
 *   May and stops at the current month.
 * - Encrypted columns are never named in SQL; every grouping runs in PHP.
 * - The institution scope is required: without one this reads nothing.
 */
final class ClinicVisualization
{
    /** Which weighing the nutrition charts draw. Latest = endline if taken, else the current reading. */
    public const PHASES = ['baseline' => 'Baseline', 'latest' => 'Latest', 'endline' => 'Endline'];

    public const DEFAULT_PHASE = 'latest';

    /** Complaints drawn as their own bar; the rest fold into one "All other" bar. */
    public const TOP_COMPLAINTS = 10;

    /** Medicines listed before the rest are folded behind "Show all". */
    public const STOCK_ROWS = 6;

    /** Each DepEd category's fill, keyed to the validated --nv-* steps in the page sheet. */
    public const TONES = [
        'Severely Wasted' => 'sw',
        'Wasted' => 'w',
        'Normal' => 'n',
        'Overweight' => 'ow',
        'Obese' => 'ob',
    ];

    /** Donut length units (the ring's pathLength) and the 2px surface gap between slices in those units. */
    private const RING = 1000.0;

    private const RING_GAP = 6.0;

    /**
     * Everything the page and its live refresh need, keyed as the views name it.
     *
     * @param  array<string, mixed>  $query  the request's query string
     * @return array<string, mixed>
     */
    public static function read(?int $institutionId, array $query): array
    {
        $years = SchoolHeadOverview::schoolYears($institutionId);
        $schoolYear = trim((string) ($query['school_year'] ?? ''));
        if (! in_array($schoolYear, $years, true)) {
            $current = StudentHealthRecord::currentSchoolYear();
            $schoolYear = in_array($current, $years, true) ? $current : ($years[0] ?? $current);
        }

        // The whole school first: the selects offer every grade and section on
        // the roll, never only what the last choice left behind.
        $school = SchoolHeadOverview::for($institutionId, $schoolYear);
        $sectionsByGrade = self::sectionsByGrade($school->records);

        $grade = trim((string) ($query['grade'] ?? ''));
        if (! array_key_exists($grade, $sectionsByGrade)) {
            $grade = '';
        }

        if ($grade !== '') {
            $offered = $sectionsByGrade[$grade];
        } else {
            $offered = array_values(array_unique(array_merge([], ...array_values($sectionsByGrade))));
            natcasesort($offered);
            $offered = array_values($offered);
        }

        $section = trim((string) ($query['section'] ?? ''));
        if (! in_array($section, $offered, true)) {
            $section = '';
        }

        $phase = trim((string) ($query['phase'] ?? ''));
        if (! array_key_exists($phase, self::PHASES)) {
            $phase = self::DEFAULT_PHASE;
        }

        $overview = $school->scopedTo($grade, $section);

        // One filter moves every chart: a narrowed roster narrows the clinic
        // figures with it, exactly as it does on the head's Health Overview.
        $health = SchoolHeadHealthOverview::for(
            $institutionId,
            $schoolYear,
            $overview->records,
            $grade !== '' || $section !== '',
        );

        $clinic = $health->clinic();
        $inventory = $health->inventory();
        $nutrition = self::nutrition($overview);

        return [
            'schoolYear' => $schoolYear,
            'schoolYears' => $years,
            'filters' => [
                'school_year' => $schoolYear,
                'grade' => $grade,
                'section' => $section,
                'phase' => $phase,
            ],
            'filterOptions' => [
                'grades' => array_keys($sectionsByGrade),
                'sections' => $offered,
                'sectionsByGrade' => $sectionsByGrade,
            ],
            'phases' => self::PHASES,
            'kpis' => self::kpis($overview, $nutrition, $clinic, $inventory),
            'nutrition' => $nutrition,
            'trend' => self::trend($health->consultations, $health->periodStart, $health->periodEnd),
            'cases' => self::cases($clinic),
            'stock' => self::stock($inventory),
            'feeding' => self::feeding($overview),
            'generatedAt' => now()->format('g:i A'),
        ];
    }

    /** A share for the screen: "18.5%", "20%", or an em dash for a share of nobody. */
    public static function percent(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    }

    /**
     * Every grade on the roll and the sections it runs, both in natural order.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array<string, list<string>>
     */
    private static function sectionsByGrade(Collection $records): array
    {
        $grouped = [];

        foreach ($records as $record) {
            [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);
            $grouped[$grade][$section] = true;
        }

        uksort($grouped, 'strnatcasecmp');

        return array_map(static function (array $sections): array {
            $names = array_keys($sections);
            natcasesort($names);

            return array_values(array_map('strval', $names));
        }, $grouped);
    }

    /**
     * The nutritional breakdown at each weighing: the school as a whole (the
     * donut) and grade by grade (the stacked bars).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function nutrition(SchoolHeadOverview $overview): array
    {
        $phases = [];

        foreach (self::PHASES as $phase => $label) {
            $counts = $overview->statusCounts($phase);
            $total = array_sum($counts);
            $notMeasured = $counts[SchoolHeadOverview::NOT_MEASURED];
            $measured = $total - $notMeasured;

            $rows = [];
            foreach (SchoolHeadOverview::NUTRITION_SCALE as $status) {
                $rows[] = [
                    'status' => $status,
                    'tone' => self::TONES[$status],
                    'count' => $counts[$status],
                    'share' => $measured > 0 ? round(($counts[$status] / $measured) * 100, 1) : null,
                ];
            }

            $undernourished = $counts['Severely Wasted'] + $counts['Wasted'];

            $phases[$phase] = [
                'key' => $phase,
                'label' => $label,
                'total' => $total,
                'measured' => $measured,
                'not_measured' => $notMeasured,
                'undernourished' => $undernourished,
                'undernourished_share' => $measured > 0 ? round(($undernourished / $measured) * 100, 1) : null,
                'rows' => $rows,
                'ring' => self::ring($rows, $measured),
                'grades' => self::gradeRows($overview->gradeBreakdown($phase)),
            ];
        }

        return $phases;
    }

    /**
     * The donut's slices as dash lengths on a ring of RING units, starting at
     * twelve o'clock and running clockwise in scale order, with a surface gap
     * between neighbouring slices. A lone slice is a full ring with no gap.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function ring(array $rows, int $measured): array
    {
        if ($measured <= 0) {
            return [];
        }

        $present = array_values(array_filter($rows, static fn (array $row): bool => $row['count'] > 0));
        $gap = count($present) > 1 ? self::RING_GAP : 0.0;

        $slices = [];
        $start = 0.0;

        foreach ($present as $row) {
            $length = ($row['count'] / $measured) * self::RING;
            // A slice of one learner in a school of hundreds still draws: a
            // category that exists must never vanish from the ring.
            $drawn = max(1.5, $length - $gap);

            $slices[] = [
                'status' => $row['status'],
                'tone' => $row['tone'],
                'count' => $row['count'],
                'share' => $row['share'],
                'dash' => round($drawn, 2),
                'rest' => round(self::RING - $drawn, 2),
                'offset' => round(-($start + $gap / 2), 2),
            ];

            $start += $length;
        }

        return $slices;
    }

    /**
     * One stacked bar per grade, each segment a share of the learners in that
     * grade actually measured.
     *
     * @param  list<array<string, mixed>>  $breakdown
     * @return list<array<string, mixed>>
     */
    private static function gradeRows(array $breakdown): array
    {
        return array_map(static function (array $grade): array {
            $measured = (int) $grade['measured'];
            $segments = [];

            foreach (SchoolHeadOverview::NUTRITION_SCALE as $status) {
                $count = (int) $grade['counts'][$status];
                if ($count === 0) {
                    continue;
                }

                $segments[] = [
                    'status' => $status,
                    'tone' => self::TONES[$status],
                    'count' => $count,
                    'share' => $measured > 0 ? round(($count / $measured) * 100, 1) : null,
                ];
            }

            return [
                'label' => (string) $grade['label'],
                'total' => (int) $grade['total'],
                'measured' => $measured,
                'not_measured' => (int) $grade['counts'][SchoolHeadOverview::NOT_MEASURED],
                'undernourished' => (int) $grade['undernourished'],
                'undernourished_share' => $grade['share'],
                'counts' => $grade['counts'],
                'segments' => $segments,
            ];
        }, $breakdown);
    }

    /**
     * The four headline figures, the two nutritional ones at every weighing so
     * the Weighing control moves them with the charts.
     *
     * @param  array<string, array<string, mixed>>  $nutrition
     * @param  array<string, mixed>  $clinic
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    private static function kpis(SchoolHeadOverview $overview, array $nutrition, array $clinic, array $inventory): array
    {
        $baseline = $nutrition['baseline'];

        $byPhase = [];
        foreach ($nutrition as $phase => $reading) {
            $byPhase[$phase] = [
                'measured' => $reading['measured'],
                'total' => $reading['total'],
                'undernourished' => $reading['undernourished'],
                'undernourished_share' => $reading['undernourished_share'],
                // Movement against the baseline, in learners. Undefined until
                // both weighings have somebody in them.
                'change' => $phase !== 'baseline' && $reading['measured'] > 0 && $baseline['measured'] > 0
                    ? $reading['undernourished'] - $baseline['undernourished']
                    : null,
            ];
        }

        return [
            'roster' => $overview->records->count(),
            'phases' => $byPhase,
            'consultations' => (int) $clinic['total'],
            'consultations_month' => (int) $clinic['this_month'],
            'month_label' => (string) $clinic['month_label'],
            'is_current_period' => (bool) $clinic['is_current_period'],
            'referred' => (int) $clinic['referred'],
            'learners_seen' => (int) $clinic['learners'],
            'stock_alerts' => (int) $inventory['out'] + (int) $inventory['low'],
            'stock_out' => (int) $inventory['out'],
            'stock_monitor' => (int) $inventory['monitor'],
            'stock_tracked' => (int) $inventory['tracked'],
        ];
    }

    /**
     * Consultations month by month across the school year, treated and
     * referred as two lines on one axis. Months run from June to the current
     * month (or May, for a year that has closed), and a month with no visit is
     * a zero on the line — a gap in a trend has to be visible as one.
     *
     * Grouped from the same consultations the head's Health Overview counts,
     * so the monthly totals here add up to its figures.
     *
     * @param  Collection<int, Consultation>  $consultations
     * @return array<string, mixed>
     */
    private static function trend(Collection $consultations, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $first = $start->startOfMonth();
        $last = CarbonImmutable::now()->lessThan($end) ? CarbonImmutable::now()->startOfMonth() : $end->startOfMonth();

        $tally = [];
        for ($month = $first; $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $tally[$month->format('Y-m')] = ['total' => 0, 'treated' => 0, 'referred' => 0];
        }

        foreach ($consultations as $consultation) {
            $key = $consultation->consulted_at?->format('Y-m');
            if ($key === null || ! isset($tally[$key])) {
                continue;
            }

            $tally[$key]['total']++;
            if (in_array($consultation->status, ['treated', 'referred'], true)) {
                $tally[$key][$consultation->status]++;
            }
        }

        $total = array_sum(array_column($tally, 'total'));
        $peak = 0;
        foreach ($tally as $counts) {
            $peak = max($peak, $counts['treated'], $counts['referred']);
        }

        $axisMax = SchoolHeadHealthOverview::axisMax($peak);
        $n = count($tally);

        $points = [];
        $i = 0;
        foreach ($tally as $key => $counts) {
            $month = CarbonImmutable::parse($key.'-01');
            $points[] = [
                'month' => $key,
                'label' => $month->format('M'),
                'full_label' => $month->format('F Y'),
                'total' => $counts['total'],
                'treated' => $counts['treated'],
                'referred' => $counts['referred'],
                'x' => $n > 0 ? round((($i + 0.5) / $n) * 100, 3) : 0.0,
                'y_treated' => $axisMax > 0 ? round(($counts['treated'] / $axisMax) * 100, 3) : 0.0,
                'y_referred' => $axisMax > 0 ? round(($counts['referred'] / $axisMax) * 100, 3) : 0.0,
            ];
            $i++;
        }

        // The polyline lives in a 1000 × 300 box stretched over the plot; the
        // dots and labels sit in HTML at the same percentages, so a circle
        // stays round whatever the card's width.
        $polyline = static fn (string $series): string => implode(' ', array_map(
            static fn (array $p): string => round($p['x'] * 10, 2).','.round(300 - $p['y_'.$series] * 3, 2),
            $points
        ));

        return [
            'points' => $points,
            'total' => $total,
            'treated' => array_sum(array_column($points, 'treated')),
            'referred' => array_sum(array_column($points, 'referred')),
            'axis_max' => $axisMax,
            'ticks' => array_reverse(SchoolHeadHealthOverview::ticks($axisMax)),
            'lines' => ['treated' => $polyline('treated'), 'referred' => $polyline('referred')],
            'end_labels' => self::endLabels($points),
            'busiest' => $total > 0 ? collect($points)->sortByDesc('total')->first() : null,
        ];
    }

    /**
     * The value at the end of each line, labelled only where it can be read:
     * two ends that would overlap are split one above, one below, and a pair
     * that sits on the same spot is labelled once.
     *
     * @param  list<array<string, mixed>>  $points
     * @return list<array{series: string, value: int, y: float, place: string}>
     */
    private static function endLabels(array $points): array
    {
        $last = end($points);
        if ($last === false || ($last['treated'] === 0 && $last['referred'] === 0)) {
            return [];
        }

        $treated = ['series' => 'treated', 'value' => $last['treated'], 'y' => $last['y_treated']];
        $referred = ['series' => 'referred', 'value' => $last['referred'], 'y' => $last['y_referred']];

        if ($treated['value'] === $referred['value']) {
            return [$treated + ['place' => 'above']];
        }

        [$high, $low] = $treated['y'] >= $referred['y'] ? [$treated, $referred] : [$referred, $treated];

        if ($high['y'] - $low['y'] >= 14) {
            return [$high + ['place' => 'above'], $low + ['place' => 'above']];
        }

        return [$high + ['place' => 'above'], $low + ['place' => 'below']];
    }

    /**
     * The most common complaints, biggest first, with the tail folded into one
     * "All other complaints" bar so the bars still add up to every visit.
     *
     * @param  array<string, mixed>  $clinic
     * @return array<string, mixed>
     */
    private static function cases(array $clinic): array
    {
        $complaints = $clinic['complaints'];
        $total = (int) $clinic['total'];

        $rows = array_map(static fn (array $row): array => [
            'label' => (string) $row['label'],
            'count' => (int) $row['count'],
            'share' => $row['share'],
            'is_other' => false,
            'kinds' => 1,
        ], array_slice($complaints, 0, self::TOP_COMPLAINTS));

        $rest = array_slice($complaints, self::TOP_COMPLAINTS);
        if ($rest !== []) {
            $other = (int) array_sum(array_column($rest, 'count'));
            $rows[] = [
                'label' => 'All other complaints',
                'count' => $other,
                'share' => $total > 0 ? round(($other / $total) * 100, 1) : null,
                'is_other' => true,
                'kinds' => count($rest),
            ];
        }

        // Every bar prints its value at the tip, so there is no axis to read
        // against: the longest bar takes the full track and the rest follow.
        $peak = $rows === [] ? 0 : max(array_column($rows, 'count'));

        foreach ($rows as $index => $row) {
            $rows[$index]['pct'] = $peak > 0 ? round(($row['count'] / $peak) * 100, 2) : 0.0;
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'kinds' => count($complaints),
        ];
    }

    /**
     * Each medicine's stock against its own reorder line, worst first.
     *
     * Medicines are counted in different units, so each bar is scaled to its
     * own item — the reading is the fill against the reorder tick, never one
     * bar against another.
     *
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    private static function stock(array $inventory): array
    {
        $rows = collect($inventory['rows'])
            ->map(static function (array $row): array {
                $stock = (int) $row['stock'];
                $threshold = (int) $row['threshold'];
                $scale = max($stock, $threshold * 2, 1);

                return $row + [
                    'fill' => round(($stock / $scale) * 100, 2),
                    'tick' => $threshold > 0 ? round(($threshold / $scale) * 100, 2) : null,
                ];
            })
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'tracked' => (int) $inventory['tracked'],
            'counts' => [
                'out' => (int) $inventory['out'],
                'low' => (int) $inventory['low'],
                'monitor' => (int) $inventory['monitor'],
                'good' => (int) $inventory['good'],
            ],
            'visible' => self::STOCK_ROWS,
        ];
    }

    /**
     * The feeding programme's beneficiaries at baseline and at endline, per
     * category, and the outcome split the coordinator and the head both read.
     *
     * The endline series is drawn only once somebody has been re-measured: a
     * row of zeros would read as "every learner left this category", the
     * opposite of "nobody has been weighed again yet".
     *
     * @return array<string, mixed>
     */
    private static function feeding(SchoolHeadOverview $overview): array
    {
        $beneficiaries = $overview->beneficiaries;
        $baseline = $overview->statusCounts('baseline', $beneficiaries);
        $endline = $overview->statusCounts('endline', $beneficiaries);

        $endlineMeasured = $beneficiaries->count() - $endline[SchoolHeadOverview::NOT_MEASURED];

        $statuses = array_values(array_filter(
            SchoolHeadOverview::NUTRITION_SCALE,
            // The wasting scale always draws; above Normal only when somebody is there.
            static fn (string $status): bool => in_array($status, FeedingNutritionProgress::SCALE, true)
                || $baseline[$status] > 0
                || $endline[$status] > 0
        ));

        // Both series share one scale — the longest bar of either — so a
        // baseline bar and its endline bar are read against each other.
        $peak = 0;
        foreach ($statuses as $status) {
            $peak = max($peak, $baseline[$status], $endlineMeasured > 0 ? $endline[$status] : 0);
        }

        $rows = array_map(static fn (string $status): array => [
            'status' => $status,
            'baseline' => $baseline[$status],
            'endline' => $endline[$status],
            'baseline_pct' => $peak > 0 ? round(($baseline[$status] / $peak) * 100, 2) : 0.0,
            'endline_pct' => $peak > 0 ? round(($endline[$status] / $peak) * 100, 2) : 0.0,
            'change' => $endline[$status] - $baseline[$status],
        ], $statuses);

        return [
            'beneficiaries' => $beneficiaries->count(),
            'endline_measured' => $endlineMeasured,
            'has_endline' => $endlineMeasured > 0,
            'rows' => $rows,
            'outcome' => $overview->outcome(),
        ];
    }
}
