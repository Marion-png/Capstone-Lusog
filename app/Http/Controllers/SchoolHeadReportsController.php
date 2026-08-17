<?php

namespace App\Http\Controllers;

use App\Models\ReportReview;
use App\Models\StudentHealthRecord;
use App\Support\AuditTrail;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingProgramCycle;
use App\Support\SchemaCache;
use App\Support\SchoolHeadOverview;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The School Head's Reports tab — "what needs my signature".
 *
 * Two halves. The first is the programme's outcome, derived every time it is
 * read: the school's nutritional status at baseline against the same roll at
 * endline, and how many beneficiaries actually left wasting. Nothing there is
 * stored, so a report can never disagree with the learners' records it
 * summarises.
 *
 * The second is the only thing this role writes: the head's decision on a
 * report (ReportReview). Approving stamps the approver and writes an audit
 * entry; returning requires a remark saying what has to be corrected; locking
 * ends the line, and a locked report is refused by every write path here — not
 * merely by a hidden button.
 *
 * Improvement and rehabilitation are counted separately and never conflated: a
 * learner who climbed from Severely Wasted to Wasted has improved but is not
 * rehabilitated, and reporting them as rehabilitated would overstate the
 * programme to the Division.
 */
class SchoolHeadReportsController extends Controller
{
    /** The reports whose key is fixed. Monthly keys are built from the data. */
    private const FIXED_REPORTS = ['baseline', 'endline'];

    /** The decisions a head can record. */
    private const DECISIONS = ['approve', 'return', 'lock'];

    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can open the reports.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $years = SchoolHeadOverview::schoolYears($institutionId);

        $schoolYear = trim((string) $request->query('school_year', ''));
        if (! in_array($schoolYear, $years, true)) {
            $schoolYear = $years[0] ?? StudentHealthRecord::currentSchoolYear();
        }

        $overview = SchoolHeadOverview::for($institutionId, $schoolYear);
        $monthly = $this->monthlyReports($overview);
        $reviews = $this->reviews($institutionId, $schoolYear);

        return view('schoolhead-dashboard.school-headreport', [
            'schoolName' => $request->session()->get('active_school_name', 'School'),
            'schoolYear' => $schoolYear,
            'schoolYears' => $years,
            'todayLabel' => now()->format('j F Y'),
            'comparison' => $this->comparison($overview),
            'shift' => $this->buildShiftChart($overview),
            'turnout' => $this->buildTurnoutChart($monthly),
            'outcome' => $overview->outcome(),
            'target' => $this->target(),
            'monthly' => $monthly,
            'reports' => $this->reportCards($overview, $monthly, $reviews),
            'headName' => trim((string) $request->session()->get('active_name', '')) ?: 'School Head',
            'reviewsAvailable' => SchemaCache::hasTable('report_reviews'),
        ]);
    }

    /**
     * Records one decision on one report.
     *
     * School Head only, re-scoped to their own school, written through the
     * model — never a raw upsert, because the casts are what keep the remark
     * and the reviewer's name encrypted — and audited with the value the
     * decision replaced as well as the new one. A locked report is refused
     * here, server-side, so a stale tab cannot reopen a closed decision.
     */
    public function review(Request $request): RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can review a report.');
        }

        if (! SchemaCache::hasTable('report_reviews')) {
            return back()->with('error', 'Report reviews are not available yet. Run the pending migrations.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $years = SchoolHeadOverview::schoolYears($institutionId);

        $validated = $request->validate([
            'report' => ['required', 'string', 'max:64'],
            'decision' => ['required', 'string', 'in:'.implode(',', self::DECISIONS)],
            'school_year' => ['required', 'string', 'in:'.implode(',', $years)],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $report = $validated['report'];
        $schoolYear = $validated['school_year'];
        $overview = SchoolHeadOverview::for($institutionId, $schoolYear);

        if (! in_array($report, $this->reportKeys($overview), true)) {
            return back()->with('error', 'That report does not exist for this school year.');
        }

        // Returning a report is an instruction to whoever has to correct it, so
        // it is worthless without one.
        $remarks = trim((string) ($validated['remarks'] ?? ''));
        if ($validated['decision'] === 'return' && $remarks === '') {
            return back()->with('error', 'Say what has to be corrected before returning a report.');
        }

        $existing = ReportReview::query()
            ->where('institution_id', $institutionId)
            ->where('school_year', $schoolYear)
            ->where('report_key', $report)
            ->first();

        if ($existing?->isLocked()) {
            return back()->with('error',
                'This report was locked on '.$existing->locked_at?->format('j F Y')
                .' and can no longer be changed. A System Admin can reopen it.');
        }

        $previous = $existing?->status ?? ReportReview::STATUS_PENDING;
        $headName = trim((string) $request->session()->get('active_name', '')) ?: null;

        $attributes = [
            'status' => match ($validated['decision']) {
                'approve' => ReportReview::STATUS_APPROVED,
                'return' => ReportReview::STATUS_RETURNED,
                default => ReportReview::STATUS_LOCKED,
            },
            'remarks' => $remarks !== '' ? $remarks : null,
            'reviewed_by_name' => $headName,
            'reviewed_by_role' => 'school_head',
            'reviewed_at' => now(),
        ];

        if ($validated['decision'] === 'lock') {
            $attributes['locked_by_name'] = $headName;
            $attributes['locked_at'] = now();
        }

        $review = ReportReview::updateOrCreate(
            [
                'institution_id' => $institutionId,
                'school_year' => $schoolYear,
                'report_key' => $report,
            ],
            $attributes,
        );

        AuditTrail::record(
            'report_review_recorded',
            'ReportReview',
            $review->id,
            'Recorded a '.$validated['decision'].' decision on the '.$this->reportLabel($report).' report for S.Y. '.$schoolYear,
            [
                'report_key' => $report,
                'school_year' => $schoolYear,
                'previous_status' => $previous,
                'new_status' => $review->status,
            ],
        );

        $message = match ($validated['decision']) {
            'approve' => 'Approved. Your name and the time are stamped on the report.',
            'return' => 'Returned for correction.',
            default => 'Locked. This report can no longer be changed.',
        };

        return back()->with('success', $message);
    }

    /**
     * One report as a workbook.
     *
     * Every export re-reads and re-scopes the school's own records: what is on
     * the wire decides which report, never whose data.
     */
    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can export a report.');
        }

        $report = trim((string) $request->query('report', 'packet'));
        $institutionId = $request->session()->get('active_institution_id');
        $years = SchoolHeadOverview::schoolYears($institutionId);

        $schoolYear = trim((string) $request->query('school_year', ''));
        if (! in_array($schoolYear, $years, true)) {
            $schoolYear = $years[0] ?? StudentHealthRecord::currentSchoolYear();
        }

        $overview = SchoolHeadOverview::for($institutionId, $schoolYear);
        $schoolName = (string) $request->session()->get('active_school_name', 'School');

        if ($report !== 'packet' && ! in_array($report, $this->reportKeys($overview), true)) {
            return back()->with('error', 'That report does not exist for this school year.');
        }

        $reserved = tempnam(sys_get_temp_dir(), 'sh-report-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        if ($report === 'packet') {
            // One workbook, one sheet per report: the Division asks for the set
            // together, and three separate files is three chances to send the
            // wrong year.
            $this->writeNutritionSheet($writer, $overview, $schoolName, 'baseline');
            $writer->addNewSheetAndMakeItCurrent();
            $this->writeNutritionSheet($writer, $overview, $schoolName, 'endline');
            $writer->addNewSheetAndMakeItCurrent();
            $this->writeAccomplishmentSheet($writer, $overview, $schoolName);
        } elseif (str_starts_with($report, 'monthly')) {
            $this->writeAccomplishmentSheet($writer, $overview, $schoolName, substr($report, strlen('monthly:')));
        } else {
            $this->writeNutritionSheet($writer, $overview, $schoolName, $report);
        }

        $writer->close();

        $filename = str_replace([':', '/'], '-', $this->reportLabel($report))
            .'-'.str_replace('/', '-', $schoolYear).'-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Baseline against endline, by category, over every learner on the roll.
     *
     * Percentages are of the learners actually **measured** at that phase, and
     * the number measured is printed beside them — a category share taken over
     * children nobody weighed would be a made-up figure.
     *
     * @return array<string, mixed>
     */
    private function comparison(SchoolHeadOverview $overview): array
    {
        $panels = [];

        foreach (['baseline', 'endline'] as $phase) {
            $counts = $overview->statusCounts($phase);
            $measured = $overview->records->count() - $counts[SchoolHeadOverview::NOT_MEASURED];

            $panels[$phase] = [
                'phase' => $phase,
                'label' => $phase === 'baseline' ? 'Baseline weighing' : 'Endline weighing',
                'measured' => $measured,
                'not_measured' => $counts[SchoolHeadOverview::NOT_MEASURED],
                'total' => $overview->records->count(),
                'date' => $this->weighingDateRange($overview, $phase),
                'rows' => array_map(fn (string $label): array => [
                    'label' => $label,
                    'count' => $counts[$label],
                    // Null, not 0%: with nobody measured there is no share.
                    'share' => $measured > 0 ? round(($counts[$label] / $measured) * 100, 1) : null,
                ], SchoolHeadOverview::NUTRITION_SCALE),
            ];
        }

        $normalShare = static function (array $panel): ?float {
            foreach ($panel['rows'] as $row) {
                if ($row['label'] === 'Normal') {
                    return $row['share'];
                }
            }

            return null;
        };

        $baselineNormal = $normalShare($panels['baseline']);
        $endlineNormal = $normalShare($panels['endline']);

        return $panels + [
            // The headline gain, in percentage points — the figure the Division
            // form asks for. Null while either side is unmeasured, because a
            // difference against nothing is not a gain.
            'normal_gain' => ($baselineNormal === null || $endlineNormal === null)
                ? null
                : round($endlineNormal - $baselineNormal, 1),
        ];
    }

    /**
     * The programme's outcome as a chart: how many learners sat in each
     * nutritional category at the baseline, and how many sit there now.
     *
     * Two series on one shared scale — the theme's validated
     * --series-risk / --series-healthy pair, the same one the coordinator's
     * Nutritional Progress panel uses, so a reader learns one meaning for those
     * colours across the product. Colour carries *which weighing*, the row
     * label carries the status and every bar is direct-labelled, so identity
     * never rests on hue alone.
     *
     * The endline series is drawn only once somebody has taken an endline
     * reading. A row of zeros would read as "every learner left this category",
     * which is the opposite of "nobody has been re-measured" — the same
     * honesty rule SchoolHeadOverview applies to an unweighed learner.
     *
     * @return array<string, mixed>
     */
    private function buildShiftChart(SchoolHeadOverview $overview): array
    {
        $baseline = $overview->statusCounts('baseline');
        $endline = $overview->statusCounts('endline');
        $total = $overview->records->count();

        $peak = 0;
        foreach (SchoolHeadOverview::NUTRITION_SCALE as $status) {
            $peak = max($peak, $baseline[$status], $endline[$status]);
        }

        $axisMax = $this->axisMax($peak);

        $rows = array_map(fn (string $status): array => [
            'label' => $status,
            'baseline' => $baseline[$status],
            'endline' => $endline[$status],
            // Share of the axis, so a bar reads against the gridlines rather
            // than against the longest bar on the chart.
            'baseline_pct' => $axisMax > 0 ? round(($baseline[$status] / $axisMax) * 100, 2) : 0.0,
            'endline_pct' => $axisMax > 0 ? round(($endline[$status] / $axisMax) * 100, 2) : 0.0,
            'change' => $endline[$status] - $baseline[$status],
        ], SchoolHeadOverview::NUTRITION_SCALE);

        return [
            'rows' => $rows,
            'axis_max' => $axisMax,
            'ticks' => $this->ticks($axisMax),
            'baseline_measured' => $total - $baseline[SchoolHeadOverview::NOT_MEASURED],
            'endline_measured' => $total - $endline[SchoolHeadOverview::NOT_MEASURED],
            'has_endline' => ($total - $endline[SchoolHeadOverview::NOT_MEASURED]) > 0,
            'total' => $total,
        ];
    }

    /**
     * Turnout month by month — whether attendance is holding up over the cycle.
     *
     * One column per month the school actually fed in, on a fixed 0–100 axis
     * because a percentage's scale is not the data's to choose, with the
     * programme's full-turnout line drawn across it: the gap between a column
     * and that line is what the eye reads, not the number.
     *
     * A month whose every mark is still unconfirmed has no turnout to draw and
     * is left empty rather than plotted at zero.
     *
     * @param  list<array<string, mixed>>  $monthly
     * @return array<string, mixed>
     */
    private function buildTurnoutChart(array $monthly): array
    {
        // monthlyReports() is newest first; time reads left to right.
        $columns = array_map(fn (array $month): array => [
            'label' => Carbon::parse($month['month'].'-01')->format('M'),
            'full_label' => $month['label'],
            'rate' => $month['turnout'],
            'days_fed' => $month['days_fed'],
            'meals' => $month['meals_served'],
        ], array_reverse($monthly));

        $rates = array_values(array_filter(
            array_column($columns, 'rate'),
            static fn (?float $rate): bool => $rate !== null
        ));

        return [
            'columns' => $columns,
            'average' => $rates === [] ? null : round(array_sum($rates) / count($rates), 1),
            'full_turnout' => SchoolHeadOverview::FULL_TURNOUT_PERCENT,
            'ticks' => [100, 75, 50, 25, 0],
        ];
    }

    /**
     * The tallest bar rounded up to a clean multiple of four, so the four
     * gridlines above the baseline all land on whole learners — "12 learners"
     * never sits between two lines.
     */
    private function axisMax(int $peak): int
    {
        if ($peak <= 0) {
            return 0;
        }

        // Step through 1, 2, 5, 10, 20, 50, … until four steps clear the peak.
        for ($magnitude = 1; $magnitude <= 1_000_000; $magnitude *= 10) {
            foreach ([1, 2, 5] as $unit) {
                $step = $unit * $magnitude;
                if ($step * 4 >= $peak) {
                    return $step * 4;
                }
            }
        }

        return $peak;
    }

    /**
     * Gridline values, low to high.
     *
     * @return list<int>
     */
    private function ticks(int $axisMax): array
    {
        if ($axisMax <= 0) {
            return [0];
        }

        $step = $axisMax / 4;

        return array_map(static fn (int $i): int => (int) round($step * $i), [0, 1, 2, 3, 4]);
    }

    /**
     * When a weighing was taken: the span of recorded dates, or null when none
     * has been.
     */
    private function weighingDateRange(SchoolHeadOverview $overview, string $phase): ?string
    {
        $column = $phase === 'baseline' ? 'baseline_recorded_at' : 'endline_recorded_at';

        $dates = $overview->records
            ->map(fn (StudentHealthRecord $record) => $record->{$column})
            ->filter()
            ->map(fn ($date) => Carbon::parse($date))
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            return null;
        }

        $first = $dates->first();
        $last = $dates->last();

        return $first->isSameDay($last)
            ? $first->format('j F Y')
            : $first->format('j F Y').' – '.$last->format('j F Y');
    }

    /**
     * One row per month the school actually fed in, with turnout by grade.
     *
     * @return list<array<string, mixed>>
     */
    private function monthlyReports(SchoolHeadOverview $overview): array
    {
        $sessionsByMonth = collect($overview->sessions())->groupBy(
            fn (array $session): string => substr($session['date'], 0, 7)
        );

        if ($sessionsByMonth->isEmpty()) {
            return [];
        }

        // Marks bucketed by month and grade, in one pass over the roll.
        $byMonthGrade = [];
        foreach ($overview->beneficiaries as $record) {
            $grade = FeedingBeneficiarySummary::gradeNumber((string) $record->section);
            $label = $grade !== null ? 'Grade '.$grade : 'Unassigned';

            foreach ($overview->marksFor($record->id) as $mark) {
                if ($mark['status'] === 'unconfirmed') {
                    continue;
                }

                $month = substr($mark['date'], 0, 7);
                $bucket = $byMonthGrade[$month][$label] ?? ['present' => 0, 'confirmed' => 0];
                $bucket['confirmed']++;
                if ($mark['status'] === 'present') {
                    $bucket['present']++;
                }
                $byMonthGrade[$month][$label] = $bucket;
            }
        }

        return $sessionsByMonth
            ->map(function ($sessions, string $month) use ($byMonthGrade, $overview): array {
                $rates = $sessions->pluck('rate')->filter(fn ($rate) => $rate !== null);
                $grades = collect($byMonthGrade[$month] ?? [])
                    ->map(fn (array $bucket, string $label): array => [
                        'label' => $label,
                        'present' => $bucket['present'],
                        'confirmed' => $bucket['confirmed'],
                        'rate' => $bucket['confirmed'] > 0
                            ? round(($bucket['present'] / $bucket['confirmed']) * 100, 1)
                            : null,
                    ])
                    ->sortBy('label', SORT_NATURAL)
                    ->values()
                    ->all();

                return [
                    'key' => 'monthly:'.$month,
                    'month' => $month,
                    'label' => Carbon::parse($month.'-01')->format('F Y'),
                    'days_fed' => $sessions->count(),
                    'meals_served' => (int) $sessions->sum('present'),
                    'beneficiaries' => $overview->beneficiaries->count(),
                    'turnout' => $rates->isEmpty() ? null : round($rates->avg(), 1),
                    'grades' => $grades,
                ];
            })
            ->sortKeysDesc()
            ->values()
            ->all();
    }

    /**
     * The report cards, each with the head's decision on it.
     *
     * @param  list<array<string, mixed>>  $monthly
     * @param  array<string, ReportReview>  $reviews
     * @return list<array<string, mixed>>
     */
    private function reportCards(SchoolHeadOverview $overview, array $monthly, array $reviews): array
    {
        $outcome = $overview->outcome();
        $cards = [];

        $cards[] = [
            'key' => 'baseline',
            'name' => 'Baseline report',
            'summary' => 'Opening nutritional status of every learner, by grade level and section.',
            'detail' => $overview->records->count() - $overview->statusCounts('baseline')[SchoolHeadOverview::NOT_MEASURED]
                .' of '.$overview->records->count().' learners measured',
            'exportable' => true,
        ];

        $cards[] = [
            'key' => 'endline',
            'name' => 'Endline report',
            'summary' => 'Closing nutritional status and the rehabilitation rate.',
            'detail' => $outcome['measured'].' of '.$outcome['beneficiaries'].' beneficiaries measured'
                .($overview->cycle->isComplete() ? '' : ' · cycle still running'),
            'exportable' => true,
        ];

        foreach ($monthly as $month) {
            $cards[] = [
                'key' => $month['key'],
                'name' => $month['label'].' accomplishment',
                'summary' => 'Days fed, meals served, turnout by grade level.',
                'detail' => $month['days_fed'].' feeding '.($month['days_fed'] === 1 ? 'day' : 'days')
                    .' · '.number_format($month['meals_served']).' meals served'
                    .($month['turnout'] !== null ? ' · '.$this->percent($month['turnout']).'% turnout' : ''),
                'exportable' => true,
            ];
        }

        return array_map(function (array $card) use ($reviews): array {
            $review = $reviews[$card['key']] ?? null;

            return $card + [
                'status' => $review?->status ?? ReportReview::STATUS_PENDING,
                'status_label' => ReportReview::statusLabel($review?->status),
                'badge' => ReportReview::statusBadge($review?->status),
                'locked' => (bool) $review?->isLocked(),
                'remarks' => trim((string) $review?->remarks),
                'reviewed_by' => trim((string) $review?->reviewed_by_name),
                'reviewed_at' => $review?->reviewed_at?->format('j F Y, g:i A'),
            ];
        }, $cards);
    }

    /**
     * @return array<string, ReportReview>
     */
    private function reviews(?int $institutionId, string $schoolYear): array
    {
        if (! SchemaCache::hasTable('report_reviews')) {
            return [];
        }

        return ReportReview::query()
            ->where('institution_id', $institutionId)
            ->where('school_year', $schoolYear)
            ->get()
            ->keyBy('report_key')
            ->all();
    }

    /**
     * The report keys that exist for this school year — what a decision or an
     * export is allowed to name.
     *
     * @return list<string>
     */
    private function reportKeys(SchoolHeadOverview $overview): array
    {
        return array_merge(
            self::FIXED_REPORTS,
            array_column($this->monthlyReports($overview), 'key'),
        );
    }

    private function reportLabel(string $key): string
    {
        if ($key === 'packet') {
            return 'Division submission packet';
        }

        if (str_starts_with($key, 'monthly:')) {
            return Carbon::parse(substr($key, strlen('monthly:')).'-01')->format('F Y').' accomplishment';
        }

        return ucfirst($key);
    }

    /**
     * The school's rehabilitation target, when one has been set.
     *
     * Left null by default on purpose: a target nobody set is a number this
     * application invented, and a rate compared against an invented target is
     * worse than a rate on its own.
     */
    private function target(): ?float
    {
        $target = config('feeding.rehabilitation_target_percent');

        return is_numeric($target) ? (float) $target : null;
    }

    /**
     * One nutritional-assessment sheet: the counts by grade and section, then
     * the learners behind them.
     */
    private function writeNutritionSheet(XlsxWriter $writer, SchoolHeadOverview $overview, string $schoolName, string $phase): void
    {
        $label = $phase === 'baseline' ? 'BASELINE' : 'ENDLINE';

        $writer->addRow(Row::fromValues([$label.' NUTRITIONAL ASSESSMENT']));
        $writer->addRow(Row::fromValues([$schoolName]));
        $writer->addRow(Row::fromValues(['S.Y. '.$overview->schoolYear]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(array_merge(
            ['GRADE LEVEL'],
            array_map('strtoupper', SchoolHeadOverview::NUTRITION_SCALE),
            ['NOT MEASURED', 'TOTAL'],
        )));

        foreach ($overview->gradeBreakdown($phase) as $row) {
            $writer->addRow(Row::fromValues(array_merge(
                [$row['label']],
                array_map(fn (string $status): int => $row['counts'][$status], SchoolHeadOverview::NUTRITION_SCALE),
                [$row['counts'][SchoolHeadOverview::NOT_MEASURED], $row['total']],
            )));
        }

        $totals = $overview->statusCounts($phase);
        $writer->addRow(Row::fromValues(array_merge(
            ['TOTAL'],
            array_map(fn (string $status): int => $totals[$status], SchoolHeadOverview::NUTRITION_SCALE),
            [$totals[SchoolHeadOverview::NOT_MEASURED], $overview->records->count()],
        )));

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['NO.', 'LRN', 'NAME', 'GRADE & SECTION', 'SEX', 'STATUS']));

        $number = 0;
        foreach ($overview->records as $record) {
            $number++;
            $status = SchoolHeadOverview::phaseStatus($record, $phase);

            $writer->addRow(Row::fromValues([
                $number,
                (string) $record->student_id,
                (string) $record->student_name,
                trim((string) $record->section),
                FeedingBeneficiarySummary::sexOf($record) ?: '—',
                // Never Normal by default: an unmeasured learner is named as one.
                $status !== '' ? $status : SchoolHeadOverview::NOT_MEASURED,
            ]));
        }

        if ($phase === 'endline') {
            $outcome = $overview->outcome();
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(['OUTCOME']));
            $writer->addRow(Row::fromValues(['Beneficiaries', $outcome['beneficiaries']]));
            $writer->addRow(Row::fromValues(['Measured at endline', $outcome['measured']]));
            $writer->addRow(Row::fromValues(['Rehabilitated (Normal at endline)', $outcome['rehabilitated']]));
            $writer->addRow(Row::fromValues([
                'Rehabilitation rate',
                $outcome['rate'] !== null ? $this->percent($outcome['rate']).'%' : '—',
            ]));
            $writer->addRow(Row::fromValues(['Improved but not rehabilitated', $outcome['improved'] - $outcome['rehabilitated'] > 0 ? $outcome['improved'] - $outcome['rehabilitated'] : 0]));
            $writer->addRow(Row::fromValues(['Still undernourished', $outcome['still_undernourished']]));
        }
    }

    /** The monthly accomplishment sheet: one block per month, newest first. */
    private function writeAccomplishmentSheet(XlsxWriter $writer, SchoolHeadOverview $overview, string $schoolName, ?string $onlyMonth = null): void
    {
        $writer->addRow(Row::fromValues(['MONTHLY ACCOMPLISHMENT REPORT']));
        $writer->addRow(Row::fromValues([$schoolName]));
        $writer->addRow(Row::fromValues(['S.Y. '.$overview->schoolYear]));
        $writer->addRow(Row::fromValues([
            'Feeding day '.$overview->cycle->day().' of '.FeedingProgramCycle::DURATION_DAYS,
            $overview->daysCompleted().' feeding days recorded',
        ]));
        $writer->addRow(Row::fromValues([]));

        foreach ($this->monthlyReports($overview) as $month) {
            if ($onlyMonth !== null && $month['month'] !== $onlyMonth) {
                continue;
            }

            $writer->addRow(Row::fromValues([strtoupper($month['label'])]));
            $writer->addRow(Row::fromValues(['Days fed', $month['days_fed']]));
            $writer->addRow(Row::fromValues(['Beneficiaries', $month['beneficiaries']]));
            $writer->addRow(Row::fromValues(['Meals served', $month['meals_served']]));
            $writer->addRow(Row::fromValues([
                'Average turnout',
                $month['turnout'] !== null ? $this->percent($month['turnout']).'%' : '—',
            ]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(['GRADE LEVEL', 'PRESENT', 'CONFIRMED MARKS', 'TURNOUT']));

            foreach ($month['grades'] as $grade) {
                $writer->addRow(Row::fromValues([
                    $grade['label'],
                    $grade['present'],
                    $grade['confirmed'],
                    $grade['rate'] !== null ? $this->percent($grade['rate']).'%' : '—',
                ]));
            }

            $writer->addRow(Row::fromValues([]));
        }
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
