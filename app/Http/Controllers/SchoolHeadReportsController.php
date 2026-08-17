<?php

namespace App\Http\Controllers;

use App\Models\ReportReview;
use App\Models\StudentHealthRecord;
use App\Support\AuditTrail;
use App\Support\BmiAssessmentReport;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingProgramCycle;
use App\Support\SchemaCache;
use App\Support\SchoolHeadOverview;
use App\Support\SchoolSignatories;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
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
     * One report as a workbook — **the school's own DepEd form, not a dump of
     * the screen.**
     *
     * The nutritional assessment exports open on the same sheet the Feeding
     * Coordinator's SBFP Forms page prints: the school heading, the per-grade
     * BMI grids (Sex × BMI-for-age × height-for-age) and the OVERALL grid, then
     * the Prepared by / Attested by / Noted by block. The cells are computed by
     * `BmiAssessmentReport`, which is the one implementation both screens read,
     * so the printed form and the exported one cannot report different numbers
     * for the same school and year. Someone can print the download and hand it
     * in; the flat table this used to write always needed retyping onto the
     * real form first.
     *
     * Every figure on it is what the people who use the app actually entered —
     * the class adviser's weighings and the nurse's examinations, read at export
     * time, never a stored copy that could have gone stale. The signature block
     * carries the school's own staff (`SchoolSignatories`), and a report the
     * head has already decided on carries that decision and the reviewer's name
     * too, because that is a genuine entry by a specific person about this
     * document.
     *
     * The supporting detail the head used to get — the learner list, the
     * outcome figures — moves to its own sheet, so the first sheet stays the
     * form and nothing is lost.
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

        // The cells of the DepEd grid, computed from this school's own records.
        $bmiValues = BmiAssessmentReport::values($overview->records);
        $reviews = $this->reviews($institutionId, $schoolYear);

        // Who signs the sheet. Read from the school's accounts, so the printed
        // form and this one are signed by the same people; the head exporting it
        // is named from their own session when no head account is on file.
        $signatories = [
            'prepared' => SchoolSignatories::preparedBy($institutionId, $schoolName),
            'noted' => SchoolSignatories::notedBy($institutionId, $schoolName)
                ?: trim((string) $request->session()->get('active_name', '')),
        ];

        $reserved = tempnam(sys_get_temp_dir(), 'sh-report-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        if ($report === 'packet') {
            // One workbook, one sheet per report: the Division asks for the set
            // together, and three separate files is three chances to send the
            // wrong year.
            $this->writeAssessmentForm($writer, $overview, $schoolName, 'baseline', $bmiValues, $signatories, $reviews['baseline'] ?? null, 'Baseline BMI');
            $writer->addNewSheetAndMakeItCurrent();
            $this->writeAssessmentForm($writer, $overview, $schoolName, 'endline', $bmiValues, $signatories, $reviews['endline'] ?? null, 'Final BMI');
            $writer->addNewSheetAndMakeItCurrent();
            $this->writeAccomplishmentSheet($writer, $overview, $schoolName, null, $signatories);
            $writer->addNewSheetAndMakeItCurrent();
            $this->writeSupportingSheet($writer, $overview, 'endline');
        } elseif (str_starts_with($report, 'monthly')) {
            $this->writeAccomplishmentSheet(
                $writer,
                $overview,
                $schoolName,
                substr($report, strlen('monthly:')),
                $signatories,
                $reviews[$report] ?? null,
            );
        } else {
            $this->writeAssessmentForm(
                $writer,
                $overview,
                $schoolName,
                $report,
                $bmiValues,
                $signatories,
                $reviews[$report] ?? null,
                $report === 'baseline' ? 'Baseline BMI' : 'Final BMI',
            );
            $writer->addNewSheetAndMakeItCurrent();
            $this->writeSupportingSheet($writer, $overview, $report);
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
     * One nutritional-assessment sheet, written as the DepEd form.
     *
     * The anatomy is the SBFP Forms page's printed sheet, in order: the school
     * heading with the address line and the school year, one ruled BMI grid per
     * grade the programme covers, the OVERALL grid, and the signature block.
     * The grids come from BmiAssessmentReport, which the coordinator's page
     * reads too — one computation behind both, so the printed form and this one
     * cannot disagree.
     *
     * A MALE or FEMALE cell nobody counted is left blank, exactly as on paper;
     * the Total columns and the TOTAL row always print a figure, because a
     * total of nothing is nothing.
     *
     * @param  array<string, int|string>  $bmiValues
     * @param  array{prepared: string, noted: string}  $signatories
     */
    private function writeAssessmentForm(
        XlsxWriter $writer,
        SchoolHeadOverview $overview,
        string $schoolName,
        string $phase,
        array $bmiValues,
        array $signatories,
        ?ReportReview $review,
        string $sheetName,
    ): void {
        $writer->getCurrentSheet()->setName($sheetName);

        $prefix = BmiAssessmentReport::prefixFor($phase);
        $banner = $phase === 'baseline'
            ? 'Baseline Nutritional Assessment (BMI) Report'
            : 'Final Nutritional Assessment (BMI) Report';

        $title = (new Style)->withFontBold(true)->withFontSize(12);
        $heading = (new Style)->withFontBold(true)->withFontSize(11);
        $gridTitle = (new Style)->withFontBold(true);
        $ruled = (new Style)->withBorder($this->hairline());
        $ruledHead = (new Style)->withFontBold(true)->withBorder($this->hairline())
            ->withCellAlignment(CellAlignment::CENTER);

        $writer->addRow($this->line([$schoolName], $title));
        // Left blank deliberately: the school address is typed onto the form and
        // the app does not hold it. An invented line would be worse than a gap.
        $writer->addRow($this->line(['School address:']));
        $writer->addRow($this->line([$banner], $heading));
        $writer->addRow($this->line(['S.Y. '.$overview->schoolYear]));
        $writer->addRow($this->line(['']));

        $nsLabels = array_values(BmiAssessmentReport::NS_COLUMNS);
        $hfaLabels = array_values(BmiAssessmentReport::HFA_COLUMNS);
        $nsKeys = array_keys(BmiAssessmentReport::NS_COLUMNS);
        $hfaKeys = array_keys(BmiAssessmentReport::HFA_COLUMNS);

        // The printed grid heads two groups of columns; a workbook cannot merge
        // cells here, so each group name sits over the first column of its group
        // and the sub-heads carry the rest — the same two-row head, read the
        // same way down the sheet.
        $groupHead = array_merge(
            ['Sex', 'Nutritional Status'],
            array_fill(0, count($nsLabels), ''),
            ['Height for Age (HFA)'],
            array_fill(0, count($hfaLabels), ''),
        );
        $columnHead = array_merge([''], $nsLabels, ['Total'], $hfaLabels, ['Total']);

        foreach (array_merge(BmiAssessmentReport::gradeKeys(), ['overall']) as $gradeKey) {
            $writer->addRow($this->line([BmiAssessmentReport::gridTitle($gradeKey)], $gridTitle));
            $writer->addRow($this->line($groupHead, $ruledHead));
            $writer->addRow($this->line($columnHead, $ruledHead));

            foreach (BmiAssessmentReport::SEX_ROWS as $sexKey => $sexLabel) {
                $cell = fn (string $key) => $bmiValues[$prefix.'_'.$gradeKey.'_'.$sexKey.'_'.$key] ?? '';

                $writer->addRow($this->line(array_merge(
                    [$sexLabel],
                    array_map($cell, $nsKeys),
                    [$cell('nst')],
                    array_map($cell, $hfaKeys),
                    [$cell('hfat')],
                ), $ruled));
            }

            $writer->addRow($this->line(['']));
        }

        $this->writeSignatureBlock($writer, $signatories, $review);
    }

    /**
     * The form's foot: who prepared it, who attested it, who noted it — and,
     * when the head has already decided on the report, that decision with the
     * name of the person who made it.
     *
     * A name the app does not hold prints as a blank line to sign on. Putting a
     * plausible name on a document nobody signed would be worse than a gap.
     *
     * @param  array{prepared: string, noted: string}  $signatories
     */
    private function writeSignatureBlock(XlsxWriter $writer, array $signatories, ?ReportReview $review): void
    {
        $label = (new Style)->withFontBold(true);

        $writer->addRow($this->line(['']));
        $writer->addRow($this->line(['Prepared by:', '', 'Attested by:', '', 'Noted by:'], $label));
        $writer->addRow($this->line([$signatories['prepared'], '', '', '', $signatories['noted']]));
        $writer->addRow($this->line([
            'School Clinic Nurse / Teacher', '', 'MAPEH Department Head', '', 'Principal',
        ]));

        if ($review === null || $review->status === ReportReview::STATUS_PENDING) {
            return;
        }

        $writer->addRow($this->line(['']));
        $writer->addRow($this->line(['REVIEW'], $label));
        $writer->addRow($this->line(['Status', ReportReview::statusLabel($review->status)]));
        $writer->addRow($this->line(['Reviewed by', (string) $review->reviewed_by_name]));
        $writer->addRow($this->line(['Reviewed on', $review->reviewed_at?->format('j F Y') ?? '']));

        $remarks = trim((string) $review->remarks);
        if ($remarks !== '') {
            $writer->addRow($this->line(['Remarks', $remarks]));
        }

        if ($review->isLocked()) {
            $writer->addRow($this->line(['Locked by', (string) $review->locked_by_name]));
            $writer->addRow($this->line(['Locked on', $review->locked_at?->format('j F Y') ?? '']));
        }
    }

    /**
     * The learners behind the grid, on their own sheet.
     *
     * The form is the first sheet and stays the form; this is the working list
     * that used to sit under it — every learner with the status the grid counted
     * them under, so a figure on the form can be traced to the children in it.
     * A learner nobody measured is named as unmeasured, never as Normal.
     */
    private function writeSupportingSheet(XlsxWriter $writer, SchoolHeadOverview $overview, string $phase): void
    {
        $writer->getCurrentSheet()->setName('Supporting Data');

        $label = (new Style)->withFontBold(true);
        $ruledHead = (new Style)->withFontBold(true)->withBorder($this->hairline());
        $ruled = (new Style)->withBorder($this->hairline());

        $writer->addRow($this->line([strtoupper($phase).' — SUPPORTING DATA'], $label));
        $writer->addRow($this->line(['S.Y. '.$overview->schoolYear]));
        $writer->addRow($this->line(['']));

        $writer->addRow($this->line(['NO.', 'LRN', 'NAME', 'GRADE & SECTION', 'SEX', 'STATUS'], $ruledHead));

        $number = 0;
        foreach ($overview->records as $record) {
            $number++;
            $status = SchoolHeadOverview::phaseStatus($record, $phase);

            $writer->addRow($this->line([
                $number,
                (string) $record->student_id,
                (string) $record->student_name,
                trim((string) $record->section),
                FeedingBeneficiarySummary::sexOf($record) ?: '—',
                // Never Normal by default: an unmeasured learner is named as one.
                $status !== '' ? $status : SchoolHeadOverview::NOT_MEASURED,
            ], $ruled));
        }

        if ($phase === 'endline') {
            $outcome = $overview->outcome();
            $writer->addRow($this->line(['']));
            $writer->addRow($this->line(['OUTCOME'], $label));
            $writer->addRow($this->line(['Beneficiaries', $outcome['beneficiaries']]));
            $writer->addRow($this->line(['Measured at endline', $outcome['measured']]));
            $writer->addRow($this->line(['Rehabilitated (Normal at endline)', $outcome['rehabilitated']]));
            $writer->addRow($this->line([
                'Rehabilitation rate',
                $outcome['rate'] !== null ? $this->percent($outcome['rate']).'%' : '—',
            ]));
            $writer->addRow($this->line([
                'Improved but not rehabilitated',
                max(0, $outcome['improved'] - $outcome['rehabilitated']),
            ]));
            $writer->addRow($this->line(['Still undernourished', $outcome['still_undernourished']]));
        }
    }

    /**
     * A row whose cells all carry one style. This OpenSpout takes a row's style
     * through its cells rather than as a second argument.
     *
     * @param  list<mixed>  $values
     */
    private function line(array $values, ?Style $style = null): Row
    {
        return new Row(array_values(array_map(
            static fn ($value): Cell => Cell::fromValue($value, $style),
            $values
        )));
    }

    /**
     * A hairline box, as on the printed sheet: the DepEd form is a ruled grid,
     * and an unruled block of figures is not the same document.
     */
    private function hairline(): Border
    {
        return new Border(
            new BorderPart(BorderName::TOP, width: BorderWidth::THIN),
            new BorderPart(BorderName::BOTTOM, width: BorderWidth::THIN),
            new BorderPart(BorderName::LEFT, width: BorderWidth::THIN),
            new BorderPart(BorderName::RIGHT, width: BorderWidth::THIN),
        );
    }

    /** The monthly accomplishment sheet: one block per month, newest first. */
    private function writeAccomplishmentSheet(
        XlsxWriter $writer,
        SchoolHeadOverview $overview,
        string $schoolName,
        ?string $onlyMonth = null,
        array $signatories = ['prepared' => '', 'noted' => ''],
        ?ReportReview $review = null,
    ): void {
        $writer->getCurrentSheet()->setName('Accomplishment');

        $title = (new Style)->withFontBold(true)->withFontSize(12);
        $heading = (new Style)->withFontBold(true)->withFontSize(11);
        $label = (new Style)->withFontBold(true);
        $ruled = (new Style)->withBorder($this->hairline());
        $ruledHead = (new Style)->withFontBold(true)->withBorder($this->hairline());

        // The same heading block the assessment sheets carry, so every report a
        // head exports reads as one school's set of forms.
        $writer->addRow($this->line([$schoolName], $title));
        $writer->addRow($this->line(['School address:']));
        $writer->addRow($this->line(['Monthly Accomplishment Report'], $heading));
        $writer->addRow($this->line(['S.Y. '.$overview->schoolYear]));
        $writer->addRow($this->line([
            'Feeding day '.$overview->cycle->day().' of '.FeedingProgramCycle::DURATION_DAYS,
            $overview->daysCompleted().' feeding days recorded',
        ]));
        $writer->addRow($this->line(['']));

        foreach ($this->monthlyReports($overview) as $month) {
            if ($onlyMonth !== null && $month['month'] !== $onlyMonth) {
                continue;
            }

            $writer->addRow($this->line([strtoupper($month['label'])], $label));
            $writer->addRow($this->line(['Days fed', $month['days_fed']]));
            $writer->addRow($this->line(['Beneficiaries', $month['beneficiaries']]));
            $writer->addRow($this->line(['Meals served', $month['meals_served']]));
            $writer->addRow($this->line([
                'Average turnout',
                $month['turnout'] !== null ? $this->percent($month['turnout']).'%' : '—',
            ]));
            $writer->addRow($this->line(['']));
            $writer->addRow($this->line(['GRADE LEVEL', 'PRESENT', 'CONFIRMED MARKS', 'TURNOUT'], $ruledHead));

            foreach ($month['grades'] as $grade) {
                $writer->addRow($this->line([
                    $grade['label'],
                    $grade['present'],
                    $grade['confirmed'],
                    $grade['rate'] !== null ? $this->percent($grade['rate']).'%' : '—',
                ], $ruled));
            }

            $writer->addRow($this->line(['']));
        }

        $this->writeSignatureBlock($writer, $signatories, $review);
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
