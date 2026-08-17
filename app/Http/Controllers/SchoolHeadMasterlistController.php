<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingBeneficiarySummary;
use App\Support\SchoolHeadOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The School Head's Masterlist — every learner on the roll, read-only.
 *
 * This is the one screen in the role that shows individual children, so two
 * things hold everywhere in it. First, nothing on the page writes: a learner's
 * panel has no edit control at all, because measurements belong to the class
 * adviser and enrolment to the Feeding Coordinator. Second, a learner nobody
 * measured reads "Not measured" and never Normal — a head scanning a roster
 * must be able to tell a healthy child from an unweighed one.
 *
 * Eight filters, in the two kinds the coordinator's tabs already use: school
 * year, grade and section are **scope** (they move the count and the table
 * together), while sex, baseline status, latest status, beneficiary standing
 * and attendance standing **narrow the list**. Every one but the school year is
 * applied in PHP, because every one of those columns is encrypted at rest.
 */
class SchoolHeadMasterlistController extends Controller
{
    /** Beneficiary standings the list can be narrowed to. */
    private const STANDINGS = ['beneficiary', 'pending', 'not_eligible'];

    /** Attendance standings, which are the at-risk rule's own three states plus "no session yet". */
    private const ATTENDANCE_STANDINGS = [
        FeedingAtRiskRule::STATUS_AT_RISK,
        FeedingAtRiskRule::STATUS_EARLY_MONITORING,
        FeedingAtRiskRule::STATUS_ON_TRACK,
        'no_sessions',
    ];

    /** Statuses a filter may offer: what the app's classifier actually emits, plus the honest fourth answer. */
    private const STATUS_OPTIONS = ['Severely Wasted', 'Wasted', 'Normal', 'Overweight', 'Obese', 'not_measured'];

    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can open the masterlist.');
        }

        return view('schoolhead-dashboard.masterlist', $this->build($request));
    }

    /**
     * The masterlist as the filters leave it, as a real workbook.
     *
     * XLSX rather than CSV on purpose: which program opens a .csv is a setting
     * on the reader's machine that no web app can reach, while an .xlsx is a
     * spreadsheet by format and opens in Excel wherever it lands.
     *
     * It carries learner names, so it is a read of personal data like any
     * other: the route sits under dashboard/* and is audited.
     */
    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can export the masterlist.');
        }

        $data = $this->build($request);

        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path — otherwise every export
        // leaves an empty temp file behind.
        $reserved = tempnam(sys_get_temp_dir(), 'sh-masterlist-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues(['LEARNER MASTERLIST']));
        $writer->addRow(Row::fromValues([(string) $data['schoolName']]));
        $writer->addRow(Row::fromValues(['S.Y. '.$data['schoolYear']]));
        $writer->addRow(Row::fromValues(['Generated '.now()->format('Y-m-d')]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues([
            'NO.', 'LRN', 'NAME', 'GRADE & SECTION', 'SEX', 'AGE',
            'WEIGHT (KG)', 'HEIGHT (CM)', 'BMI',
            'BASELINE STATUS', 'LATEST STATUS', 'CHANGE',
            'BENEFICIARY', 'ATTENDANCE', 'ATTENDANCE STANDING',
        ]));

        $number = 0;
        foreach ($data['rows'] as $row) {
            $number++;
            $writer->addRow(Row::fromValues([
                $number,
                $row['lrn'],
                $row['name'],
                $row['section'],
                $row['sex'] !== '' ? $row['sex'] : '—',
                $row['age'] !== '' ? $row['age'] : '—',
                $row['weight'] !== '' ? $row['weight'] : '—',
                $row['height'] !== '' ? $row['height'] : '—',
                $row['bmi'] !== '' ? $row['bmi'] : '—',
                $row['baseline'] !== '' ? $row['baseline'] : SchoolHeadOverview::NOT_MEASURED,
                $row['latest'] !== '' ? $row['latest'] : SchoolHeadOverview::NOT_MEASURED,
                SchoolHeadOverview::movementLabel($row['movement']),
                $row['standing_label'],
                // An em dash, never 0%: no confirmed session is not a turnout of nothing.
                $row['rate'] !== null ? $this->percent($row['rate']).'%' : '—',
                $row['attendance_label'],
            ]));
        }

        $writer->close();

        $filename = 'Masterlist-'.str_replace('/', '-', $data['schoolYear']).'-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Everything the tab shows, from one reading of one roster.
     *
     * @return array<string, mixed>
     */
    private function build(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');
        $years = SchoolHeadOverview::schoolYears($institutionId);

        $schoolYear = trim((string) $request->query('school_year', ''));
        if (! in_array($schoolYear, $years, true)) {
            $schoolYear = $years[0] ?? StudentHealthRecord::currentSchoolYear();
        }

        $overview = SchoolHeadOverview::for($institutionId, $schoolYear);
        $options = $this->filterOptions($overview->records);
        $filters = $this->readFilters($request, $options, $schoolYear);

        // Scope first: it decides which learners the count on screen describes.
        $scoped = FeedingBeneficiarySummary::scopeToSection($overview->records, [
            'grade' => $filters['grade'],
            'section' => $filters['section'],
        ]);

        $standings = $overview->standings();

        $rows = $scoped
            ->map(fn (StudentHealthRecord $record): array => $this->buildRow($record, $standings, $overview))
            ->values();

        $total = $rows->count();
        $rows = $this->applyFilters($rows, $filters)->values();

        return [
            'schoolName' => $request->session()->get('active_school_name', 'School'),
            'schoolYear' => $schoolYear,
            'schoolYears' => $years,
            'todayLabel' => now()->format('j F Y'),
            'rows' => $rows,
            'shown' => $rows->count(),
            'total' => $total,
            'filters' => $filters,
            'filterOptions' => $options,
            'statusOptions' => self::STATUS_OPTIONS,
            'sexOptions' => FeedingBeneficiarySummary::SEX_OPTIONS,
            'rule' => $overview->rule->describe(),
        ];
    }

    /**
     * One learner's row, and the read-only history behind it.
     *
     * @param  array<int, array<string, mixed>>  $standings
     * @return array<string, mixed>
     */
    private function buildRow(StudentHealthRecord $record, array $standings, SchoolHeadOverview $overview): array
    {
        $details = is_array($record->student_details) ? $record->student_details : [];
        $baseline = SchoolHeadOverview::phaseStatus($record, 'baseline');
        $latest = SchoolHeadOverview::phaseStatus($record, 'latest');
        $standing = $standings[$record->id] ?? null;

        $isBeneficiary = FeedingBeneficiarySummary::isBeneficiary($record);
        $isPending = ! $isBeneficiary && FeedingBeneficiarySummary::isEligible($record);

        // "no_sessions" is not one of the rule's three states — it is the
        // absence of evidence the rule needs — so it is decided here rather
        // than being read as a quieter kind of Early Monitoring.
        $attendanceStanding = match (true) {
            $standing === null => '',
            $standing['confirmed'] === 0 => 'no_sessions',
            default => $standing['status'],
        };

        return [
            'id' => $record->id,
            'lrn' => (string) $record->student_id,
            'name' => (string) $record->student_name,
            'section' => trim((string) $record->section) !== '' ? trim((string) $record->section) : 'Unassigned',
            'grade' => FeedingBeneficiarySummary::gradeNumber((string) $record->section),
            'sex' => FeedingBeneficiarySummary::sexOf($record),
            'age' => $this->firstFilled([$record->endline_age, $details['age'] ?? null, $record->baseline_age]),
            'weight' => $this->firstFilled([$record->endline_weight_kg, $details['weight_kg'] ?? null, $record->weight, $record->baseline_weight_kg]),
            'height' => $this->firstFilled([$record->endline_height_cm, $details['height_cm'] ?? null, $record->baseline_height_cm]),
            'bmi' => $this->firstFilled([$record->endline_bmi_value, $record->bmi_value, $record->baseline_bmi_value]),
            'baseline' => $baseline,
            'latest' => $latest,
            'movement' => SchoolHeadOverview::movement($baseline, $latest),
            'standing' => match (true) {
                $isBeneficiary => 'beneficiary',
                $isPending => 'pending',
                default => 'not_eligible',
            },
            'standing_label' => match (true) {
                $isBeneficiary => 'Enrolled beneficiary',
                $isPending => 'Qualified, awaiting enrolment',
                default => 'Not a beneficiary',
            },
            'rate' => $standing['rate'] ?? null,
            'present' => $standing['present'] ?? 0,
            'absent' => $standing['absent'] ?? 0,
            'confirmed' => $standing['confirmed'] ?? 0,
            'not_marked' => $standing['not_marked'] ?? 0,
            'attendance' => $attendanceStanding,
            'attendance_label' => match ($attendanceStanding) {
                '' => 'Not a beneficiary',
                'no_sessions' => 'No confirmed session',
                default => FeedingAtRiskRule::statusLabel($attendanceStanding),
            },
            'at_risk' => (bool) ($standing['at_risk'] ?? false),
            'history' => $this->buildHistory($record, $details),
            'sparkline' => $this->buildSparkline($record),
            'enrolled_on' => $record->feeding_enrolled_at?->format('j F Y'),
            'marks' => count($overview->marksFor($record->id)),
        ];
    }

    /**
     * The learner's measurement history: every reading on file, in the order
     * they were taken. A reading nobody took is simply absent from the list —
     * never a row of zeroes.
     *
     * @param  array<string, mixed>  $details
     * @return list<array<string, string>>
     */
    private function buildHistory(StudentHealthRecord $record, array $details): array
    {
        $history = [];

        $baselineStatus = SchoolHeadOverview::phaseStatus($record, 'baseline');
        if ($baselineStatus !== '' || $this->filled($record->baseline_weight_kg)) {
            $history[] = [
                'phase' => 'Baseline weighing',
                'date' => $record->baseline_recorded_at?->format('j F Y') ?? '—',
                'weight' => $this->firstFilled([$record->baseline_weight_kg]),
                'height' => $this->firstFilled([$record->baseline_height_cm]),
                'bmi' => $this->firstFilled([$record->baseline_bmi_value]),
                'status' => $baselineStatus !== '' ? $baselineStatus : SchoolHeadOverview::NOT_MEASURED,
            ];
        }

        // What the record currently carries — the adviser's entry reading, kept
        // separate from the baseline because a re-encoded record can move it.
        $currentStatus = SchoolHeadOverview::toScale((string) $record->nutritional_status);
        if ($currentStatus !== '' || $this->filled($record->weight)) {
            $history[] = [
                'phase' => 'Current record',
                'date' => $record->updated_at?->format('j F Y') ?? '—',
                'weight' => $this->firstFilled([$details['weight_kg'] ?? null, $record->weight]),
                'height' => $this->firstFilled([$details['height_cm'] ?? null]),
                'bmi' => $this->firstFilled([$record->bmi_value]),
                'status' => $currentStatus !== '' ? $currentStatus : SchoolHeadOverview::NOT_MEASURED,
            ];
        }

        $endlineStatus = SchoolHeadOverview::phaseStatus($record, 'endline');
        if ($endlineStatus !== '' || $this->filled($record->endline_weight_kg)) {
            $history[] = [
                'phase' => 'Endline weighing',
                'date' => $record->endline_recorded_at?->format('j F Y') ?? '—',
                'weight' => $this->firstFilled([$record->endline_weight_kg]),
                'height' => $this->firstFilled([$record->endline_height_cm]),
                'bmi' => $this->firstFilled([$record->endline_bmi_value]),
                'status' => $endlineStatus !== '' ? $endlineStatus : SchoolHeadOverview::NOT_MEASURED,
            ];
        }

        return $history;
    }

    /**
     * BMI over the readings on file, as points for a small line.
     *
     * Two points are the minimum a line can say anything with, so a learner
     * with one reading gets none and the panel prints the figure instead.
     *
     * @return array{points: list<array{label: string, value: float, x: float, y: float}>, min: float, max: float}|null
     */
    private function buildSparkline(StudentHealthRecord $record): ?array
    {
        $readings = [];

        foreach ([
            ['Baseline', $record->baseline_bmi_value],
            ['Current', $record->bmi_value],
            ['Endline', $record->endline_bmi_value],
        ] as [$label, $value]) {
            if ($this->filled($value) && is_numeric((string) $value)) {
                $readings[] = ['label' => $label, 'value' => round((float) $value, 1)];
            }
        }

        if (count($readings) < 2) {
            return null;
        }

        $values = array_column($readings, 'value');
        $min = min($values);
        $max = max($values);
        // A flat line still needs a band to sit in, or every point lands on the
        // same pixel and the chart reads as a single dot.
        $span = ($max - $min) > 0.01 ? ($max - $min) : 1.0;
        $last = count($readings) - 1;

        $points = [];
        foreach ($readings as $index => $reading) {
            $points[] = $reading + [
                'x' => $last > 0 ? round(($index / $last) * 100, 2) : 0.0,
                // SVG y grows downward, so a higher BMI sits nearer the top.
                'y' => round(100 - (($reading['value'] - $min) / $span) * 100, 2),
            ];
        }

        return ['points' => $points, 'min' => $min, 'max' => $max];
    }

    /**
     * @param  array<string, list<string>>  $options
     * @return array<string, string>
     */
    private function readFilters(Request $request, array $options, string $schoolYear): array
    {
        $grade = trim((string) $request->query('grade', ''));
        if (! in_array($grade, $options['grades'], true)) {
            $grade = '';
        }

        $section = trim((string) $request->query('section', ''));
        if (! in_array($section, $options['sections'], true)) {
            $section = '';
        }

        $sex = trim((string) $request->query('sex', ''));
        if (! in_array($sex, FeedingBeneficiarySummary::SEX_OPTIONS, true)) {
            $sex = '';
        }

        $baseline = trim((string) $request->query('baseline', ''));
        if (! in_array($baseline, self::STATUS_OPTIONS, true)) {
            $baseline = '';
        }

        $latest = trim((string) $request->query('latest', ''));
        if (! in_array($latest, self::STATUS_OPTIONS, true)) {
            $latest = '';
        }

        $standing = trim((string) $request->query('standing', ''));
        if (! in_array($standing, self::STANDINGS, true)) {
            $standing = '';
        }

        $attendance = trim((string) $request->query('attendance', ''));
        if (! in_array($attendance, self::ATTENDANCE_STANDINGS, true)) {
            $attendance = '';
        }

        return [
            'school_year' => $schoolYear,
            'grade' => $grade,
            'section' => $section,
            'sex' => $sex,
            'baseline' => $baseline,
            'latest' => $latest,
            'standing' => $standing,
            'attendance' => $attendance,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when($filters['sex'] !== '', fn (Collection $r) => $r->where('sex', $filters['sex']))
            ->when($filters['baseline'] !== '', fn (Collection $r) => $r->filter(
                fn (array $row): bool => $this->matchesStatus($row['baseline'], $filters['baseline'])
            ))
            ->when($filters['latest'] !== '', fn (Collection $r) => $r->filter(
                fn (array $row): bool => $this->matchesStatus($row['latest'], $filters['latest'])
            ))
            ->when($filters['standing'] !== '', fn (Collection $r) => $r->where('standing', $filters['standing']))
            ->when($filters['attendance'] !== '', fn (Collection $r) => $r->where('attendance', $filters['attendance']));
    }

    /** "not_measured" is a real answer, not the absence of a filter. */
    private function matchesStatus(string $status, string $wanted): bool
    {
        return $wanted === 'not_measured' ? $status === '' : $status === $wanted;
    }

    /**
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array{grades: list<string>, sections: list<string>}
     */
    private function filterOptions(Collection $records): array
    {
        $split = $records->map(
            fn (StudentHealthRecord $record): array => FeedingBeneficiarySummary::splitSection((string) $record->section)
        );

        return [
            'grades' => $split->pluck(0)->filter()->unique()->sort(SORT_NATURAL)->values()->all(),
            'sections' => $split->pluck(1)->filter()->unique()->sort(SORT_NATURAL)->values()->all(),
        ];
    }

    /**
     * The first value on this list that somebody actually recorded, as a
     * display string. Everything here is encrypted at rest and comes back as
     * text, so "0" is kept and only blanks are skipped.
     *
     * @param  list<mixed>  $candidates
     */
    private function firstFilled(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($this->filled($candidate)) {
                return trim((string) $candidate);
            }
        }

        return '';
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
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
