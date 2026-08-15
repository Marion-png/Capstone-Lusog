<?php

namespace App\Http\Controllers;

use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingProgramCycle;
use App\Support\SchemaCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Feeding Coordinator's Attendance tab: who was fed, on which day.
 *
 * It answers one question — who → when → present or absent → cumulative rate →
 * at risk — and deliberately answers no others. Baseline and endline
 * measurements, BMI, consent and medical documents belong to the Beneficiaries
 * tab and the learner's health profile; putting them here would make two tabs
 * responsible for the same data and let them drift apart.
 *
 * Every figure is derived at read time from `feeding_attendances` and the
 * school's own at-risk rule. Nothing here is stored or hand-entered:
 *
 * - a mark nobody has confirmed (NULL / needs_review) is neither present nor
 *   absent, and is excluded from every rate — see FeedingAtRiskRule;
 * - a beneficiary no sheet has covered for a session is **unmarked**, never
 *   absent, because nobody observed them missing;
 * - the at-risk threshold comes from the school's own setting via
 *   FeedingAtRiskRule::forInstitution(), never a constant in this file.
 *
 * Saving goes through FeedingProgramController::storeRecordedAttendance — the
 * one audited write path for a recorded session — rather than a second one
 * here.
 */
class FeedingAttendanceController extends Controller
{
    /** The four readings of attendance this tab offers. */
    private const VIEWS = ['sheet', 'history', 'beneficiary', 'calendar'];

    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can open attendance.');
        }

        return view('feedingcor-dashboard.attendance', $this->build($request));
    }

    /**
     * The panels a change can move, re-rendered from the same Blade partials
     * the first paint used, so a live view and a reloaded one cannot drift.
     *
     * The sheet itself is deliberately **not** returned: it holds marks the
     * coordinator has not saved yet, and replacing it under their hands would
     * throw away work in progress.
     */
    public function metrics(Request $request): JsonResponse
    {
        if (! $this->isCoordinator($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $this->build($request);

        return response()->json([
            'generatedAt' => now()->toIso8601String(),
            'html' => [
                'cards' => view('feedingcor-dashboard.partials.attendance-cards', $data)->render(),
                'notices' => view('feedingcor-dashboard.partials.attendance-notices', $data)->render(),
                'history' => view('feedingcor-dashboard.partials.attendance-history', $data)->render(),
                'beneficiary' => view('feedingcor-dashboard.partials.attendance-by-beneficiary', $data)->render(),
                'calendar' => view('feedingcor-dashboard.partials.attendance-calendar', $data)->render(),
            ],
        ]);
    }

    /**
     * The school's own attendance sheet as XLSX: one row per beneficiary, one
     * column per feeding date, in the DepEd "identified severely wasted and
     * wasted students" layout the school already prints.
     *
     * It carries learner names, so it is a read of personal data like any other
     * — the route sits under dashboard/* and is audited by AuditSensitiveAccess.
     */
    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can export attendance.');
        }

        $data = $this->build($request);

        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path — otherwise every export
        // leaves an empty temp file behind.
        $reserved = tempnam(sys_get_temp_dir(), 'sbfp-attendance-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $dates = $data['sessionDates'];

        $writer->addRow(Row::fromValues(['LISTS OF IDENTIFIED SEVERELY WASTED AND WASTED STUDENTS']));
        $writer->addRow(Row::fromValues(['WHO ARE QUALIFIED FOR FEEDING PROGRAM']));
        $writer->addRow(Row::fromValues([(string) $data['schoolName']]));
        $writer->addRow(Row::fromValues(['S.Y. '.$data['schoolYear']]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(array_merge(
            ['NO.', 'NAME', 'GRADE', 'SECTION'],
            array_map(fn (string $date): string => strtoupper(Carbon::parse($date)->format('F j, Y')), $dates)
        )));

        foreach ($data['exportRows'] as $index => $row) {
            $writer->addRow(Row::fromValues(array_merge(
                [$index + 1, $row['name'], $row['grade'], $row['section']],
                array_map(function (string $date) use ($row): string {
                    // The template's own marks: a tick for served, A for a
                    // confirmed absence, and nothing at all where no one
                    // recorded the learner — a blank cell is not an absence.
                    return match ($row['marks'][$date] ?? 'unmarked') {
                        'present' => '✓',
                        'absent' => 'A',
                        default => '',
                    };
                }, $dates)
            )));
        }

        $writer->close();

        $filename = 'SBFP-Attendance-'.str_replace('/', '-', $data['schoolYear']).'-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Everything the tab shows, from one reading of one set of marks.
     *
     * Building it in one place is what keeps the cards, the sheet, the history,
     * the per-beneficiary roll and the calendar in agreement: they are five
     * views of the same rows, not five queries that might disagree.
     *
     * @return array<string, mixed>
     */
    private function build(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');
        $schoolYear = StudentHealthRecord::currentSchoolYear();
        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        $cycle = FeedingProgramCycle::forInstitution($institutionId);

        // The enrolled roll, decided by the one class that decides it. Sorting
        // happens in PHP because student_name is encrypted at rest.
        $beneficiaries = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $beneficiaries = StudentHealthRecord::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->forCurrentSchoolYear($schoolYear)
                ->get()
                ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
                ->sortBy(fn (StudentHealthRecord $record): string => strtolower((string) $record->student_name))
                ->values();
        }

        // One query for every mark these learners carry; everything below is
        // derived from it in PHP, so no two panels can read different data.
        $marks = $this->marksFor($beneficiaries);

        $sessionDates = $marks
            ->pluck('date')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $today = now()->toDateString();
        $selectedDate = $this->resolveDate($request, $sessionDates, $cycle, $today);
        $window = $this->programWindow($cycle, $today);

        // date => [record id => mark row]
        $byDate = $marks->groupBy('date')->map(fn (Collection $rows) => $rows->keyBy('record_id'));
        $byRecord = $marks->groupBy('record_id');

        $rows = $this->sheetRows($beneficiaries, $byDate->get($selectedDate, collect()));
        $filters = $this->readFilters($request, $rows);
        $filteredRows = $this->applyFilters($rows, $filters);

        $standings = $this->standings($beneficiaries, $byRecord, $rule);
        $atRiskCount = collect($standings)->where('at_risk', true)->count();

        $cumulative = $this->cumulativeRate($marks);
        $todayTally = $this->tally($rows);

        return [
            'view' => in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'sheet',
            // Links are built against the tab's own route, never the current
            // one: these partials are re-rendered by the metrics endpoint too,
            // and a link built from request()->fullUrl() there would point a
            // date or a tab at the JSON endpoint.
            'pageUrl' => function (array $overrides = []) use ($request): string {
                $query = array_filter(
                    $request->query(),
                    static fn ($value, $name): bool => $value !== '' && is_scalar($value),
                    ARRAY_FILTER_USE_BOTH
                );

                return route('dashboard.feedingcor-attendance', array_merge($query, $overrides));
            },
            'schoolYear' => $schoolYear,
            'schoolName' => $request->session()->get('active_school_name', 'School'),
            'today' => $today,
            'todayLabel' => Carbon::parse($today)->format('F j, Y'),
            'selectedDate' => $selectedDate,
            'selectedDateLabel' => Carbon::parse($selectedDate)->format('F j, Y'),
            'isToday' => $selectedDate === $today,
            // Which feeding day of the cycle the header counts, shared with the
            // Dashboard and the Feeding Program page so all three agree.
            'programDay' => $cycle->day(),
            'programDuration' => FeedingProgramCycle::DURATION_DAYS,
            'window' => $window,
            'sessionDates' => $sessionDates,
            'previousDate' => $this->stepDate($selectedDate, $sessionDates, $window, -1),
            'nextDate' => $this->stepDate($selectedDate, $sessionDates, $window, 1),
            'beneficiaryCount' => $beneficiaries->count(),
            'rows' => $filteredRows,
            'unfilteredRows' => $rows,
            'tally' => $todayTally,
            'cumulativeRate' => $cumulative,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($rows),
            'sessionRecorded' => $byDate->has($selectedDate),
            'atRisk' => [
                'count' => $atRiskCount,
                'threshold' => $rule->thresholdPercent(),
                'thresholdLabel' => rtrim(rtrim(number_format($rule->thresholdPercent(), 1), '0'), '.'),
                'rule' => $rule->describe(),
            ],
            'history' => $this->history($beneficiaries, $byDate, $sessionDates),
            'beneficiaryRows' => $this->beneficiaryRows($beneficiaries, $standings, $filters),
            'calendar' => $this->calendar($selectedDate, $byDate, $beneficiaries->count()),
            'exportRows' => $this->exportRows($beneficiaries, $byRecord),
        ];
    }

    /**
     * Every mark these beneficiaries carry, flattened to plain rows.
     *
     * Fetched, never aggregated in SQL: a SUM(CASE WHEN is_present …) folds an
     * unconfirmed NULL into "absent", which is the one thing attendance must
     * never do.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return Collection<int, array{record_id: int, date: string, status: string, remarks: string}>
     */
    private function marksFor(Collection $beneficiaries): Collection
    {
        if ($beneficiaries->isEmpty() || ! SchemaCache::hasTable('feeding_attendances')) {
            return collect();
        }

        $columns = ['student_health_record_id', 'session_date', 'is_present'];
        if (SchemaCache::hasColumn('feeding_attendances', 'needs_review')) {
            $columns[] = 'needs_review';
        }
        if (SchemaCache::hasColumn('feeding_attendances', 'remarks')) {
            $columns[] = 'remarks';
        }

        return FeedingAttendance::query()
            ->whereIn('student_health_record_id', $beneficiaries->pluck('id'))
            ->whereDate('session_date', '<=', now()->toDateString())
            ->orderBy('session_date')
            ->get($columns)
            ->map(fn (FeedingAttendance $row): array => [
                'record_id' => (int) $row->student_health_record_id,
                'date' => optional($row->session_date)->toDateString(),
                'status' => match (true) {
                    (bool) ($row->needs_review ?? false), $row->is_present === null => 'unconfirmed',
                    (bool) $row->is_present => 'present',
                    default => 'absent',
                },
                'remarks' => trim((string) ($row->remarks ?? '')),
            ])
            ->filter(fn (array $row): bool => $row['date'] !== null)
            ->values();
    }

    /**
     * The sheet for one session: every beneficiary, with the mark on file or
     * none at all. A learner nobody recorded is "unmarked" — the sheet never
     * pre-fills an absence.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  Collection<int, array<string, mixed>>  $marksForDate  keyed by record id
     * @return Collection<int, array<string, mixed>>
     */
    private function sheetRows(Collection $beneficiaries, Collection $marksForDate): Collection
    {
        return $beneficiaries->map(function (StudentHealthRecord $record) use ($marksForDate): array {
            [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);
            $mark = $marksForDate->get($record->id);

            return [
                'id' => $record->id,
                'name' => (string) $record->student_name,
                'grade' => $grade,
                'grade_number' => preg_replace('/^grade\s*/i', '', $grade),
                'section' => $section,
                'status' => $mark['status'] ?? 'unmarked',
                'remarks' => $mark['remarks'] ?? '',
            ];
        })->values();
    }

    /**
     * Where each beneficiary stands with the school's rule, from one reading of
     * their marks — so the rate printed beside a learner is exactly the one
     * that decided their flag.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $byRecord
     * @return array<int, array{present: int, absent: int, confirmed: int, rate: ?float, at_risk: bool}>
     */
    private function standings(Collection $beneficiaries, Collection $byRecord, FeedingAtRiskRule $rule): array
    {
        $standings = [];

        foreach ($beneficiaries as $record) {
            $rows = $byRecord->get($record->id, collect());
            // Unconfirmed marks become NULL, which the rule keeps out of both
            // the numerator and the denominator.
            $marks = $rows->map(fn (array $row) => match ($row['status']) {
                'present' => true,
                'absent' => false,
                default => null,
            })->all();

            $present = $rule->presentCount($marks);
            $confirmed = count(array_filter($marks, static fn ($mark) => $mark !== null));

            $standings[$record->id] = [
                'present' => $present,
                'absent' => $confirmed - $present,
                'confirmed' => $confirmed,
                'rate' => $rule->attendanceRate($marks),
                'at_risk' => $rule->isAtRisk($marks),
            ];
        }

        return $standings;
    }

    /**
     * One row per feeding day: what the school recorded that session.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $byDate
     * @param  list<string>  $sessionDates
     * @return list<array<string, mixed>>
     */
    private function history(Collection $beneficiaries, Collection $byDate, array $sessionDates): array
    {
        $history = [];

        foreach ($sessionDates as $index => $date) {
            $rows = $byDate->get($date, collect());
            $present = $rows->where('status', 'present')->count();
            $absent = $rows->where('status', 'absent')->count();
            $unconfirmed = $rows->where('status', 'unconfirmed')->count();
            $confirmed = $present + $absent;

            $history[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M j, Y'),
                // The session's place in the programme, counted from the first
                // recorded feeding day.
                'day' => $index + 1,
                'present' => $present,
                'absent' => $absent,
                'unconfirmed' => $unconfirmed,
                'recorded' => $rows->count(),
                'expected' => $beneficiaries->count(),
                'rate' => $confirmed > 0 ? round(($present / $confirmed) * 100, 1) : null,
                'complete' => $beneficiaries->count() > 0 && $rows->count() >= $beneficiaries->count(),
            ];
        }

        // Newest first: the last session is the one a coordinator opens this
        // view to check.
        return array_reverse($history);
    }

    /**
     * Attendance by beneficiary — the cumulative view the threshold is applied
     * to. Deliberately carries no baseline, BMI or endline column: a learner's
     * health profile is the Beneficiaries tab's responsibility, not this one's.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  array<int, array<string, mixed>>  $standings
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function beneficiaryRows(Collection $beneficiaries, array $standings, array $filters): array
    {
        return $beneficiaries
            ->map(function (StudentHealthRecord $record) use ($standings): array {
                [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);
                $standing = $standings[$record->id] ?? ['present' => 0, 'absent' => 0, 'confirmed' => 0, 'rate' => null, 'at_risk' => false];

                return [
                    'id' => $record->id,
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'grade_number' => preg_replace('/^grade\s*/i', '', $grade),
                    'section' => $section,
                    'present' => $standing['present'],
                    'absent' => $standing['absent'],
                    'rate' => $standing['rate'],
                    'at_risk' => $standing['at_risk'],
                ];
            })
            // Grade and section are the tab's scope, so they narrow this roll
            // exactly as they narrow the sheet.
            ->filter(fn (array $row): bool => ($filters['grade'] === '' || $row['grade'] === $filters['grade'])
                && ($filters['section'] === '' || $row['section'] === $filters['section']))
            // "View At-Risk Beneficiaries" lands here with the flag already on.
            ->when($filters['status'] === 'at_risk', fn (Collection $rows) => $rows->where('at_risk', true))
            ->values()
            ->all();
    }

    /**
     * The selected date's month as a Monday-first grid, with what was recorded
     * on each day. Weekends are drawn too — a session recorded on one must not
     * be invisible.
     *
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $byDate
     * @return array{label: string, weeks: list<list<?array<string, mixed>>>}
     */
    private function calendar(string $selectedDate, Collection $byDate, int $expected): array
    {
        $month = Carbon::parse($selectedDate)->startOfMonth();
        $cursor = $month->copy()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $today = now()->toDateString();

        $weeks = [];
        $week = [];

        while ($cursor <= $end) {
            $date = $cursor->toDateString();
            $rows = $byDate->get($date, collect());

            $week[] = [
                'date' => $date,
                'day' => $cursor->day,
                'in_month' => $cursor->month === $month->month,
                'is_weekend' => $cursor->isWeekend(),
                'is_today' => $date === $today,
                'is_selected' => $date === $selectedDate,
                'is_future' => $date > $today,
                'recorded' => $rows->count(),
                'state' => match (true) {
                    $rows->isEmpty() => 'none',
                    $expected > 0 && $rows->count() >= $expected => 'complete',
                    default => 'partial',
                },
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }

            $cursor->addDay();
        }

        if ($week !== []) {
            $weeks[] = $week;
        }

        return ['label' => $month->format('F Y'), 'weeks' => $weeks];
    }

    /**
     * One row per beneficiary with every session's mark, for the school's own
     * XLSX template.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $byRecord
     * @return list<array<string, mixed>>
     */
    private function exportRows(Collection $beneficiaries, Collection $byRecord): array
    {
        return $beneficiaries->map(function (StudentHealthRecord $record) use ($byRecord): array {
            [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);

            return [
                'name' => (string) $record->student_name,
                'grade' => preg_replace('/^grade\s*/i', '', $grade),
                'section' => $section,
                'marks' => $byRecord->get($record->id, collect())
                    ->mapWithKeys(fn (array $row): array => [$row['date'] => $row['status']])
                    ->all(),
            ];
        })->values()->all();
    }

    /**
     * The session the sheet opens on: the requested date when it is one the
     * programme allows, otherwise today.
     *
     * @param  list<string>  $sessionDates
     */
    private function resolveDate(Request $request, array $sessionDates, FeedingProgramCycle $cycle, string $today): string
    {
        $requested = trim((string) $request->query('date', ''));

        if ($requested === '') {
            return $today;
        }

        try {
            $date = Carbon::parse($requested)->toDateString();
        } catch (\Throwable) {
            return $today;
        }

        // A date the programme cannot cover is not an error worth a page of
        // its own — the sheet simply opens on today instead.
        $window = $this->programWindow($cycle, $today);

        if ($date > $window['end'] || ($window['start'] !== null && $date < $window['start'])) {
            return in_array($date, $sessionDates, true) ? $date : $today;
        }

        return $date;
    }

    /**
     * The dates this programme may carry attendance for: from its first
     * recorded session to 120 feeding days later, and never past today.
     *
     * A school with no session yet has no start — the first save sets it —
     * so only the "not in the future" half applies.
     *
     * @return array{start: ?string, end: string}
     */
    private function programWindow(FeedingProgramCycle $cycle, string $today): array
    {
        $start = $cycle->startDateIso();

        if ($start === null) {
            return ['start' => null, 'end' => $today];
        }

        $last = Carbon::parse($start)->addDays(FeedingProgramCycle::DURATION_DAYS - 1)->toDateString();

        return ['start' => $start, 'end' => min($last, $today)];
    }

    /**
     * The previous or next day the coordinator can move to: the neighbouring
     * recorded session if there is one, else the neighbouring calendar day
     * while it stays inside the programme window.
     *
     * @param  list<string>  $sessionDates
     * @param  array{start: ?string, end: string}  $window
     */
    private function stepDate(string $selectedDate, array $sessionDates, array $window, int $direction): ?string
    {
        $neighbours = $direction < 0
            ? array_values(array_filter($sessionDates, fn (string $date): bool => $date < $selectedDate))
            : array_values(array_filter($sessionDates, fn (string $date): bool => $date > $selectedDate));

        if ($neighbours !== []) {
            return $direction < 0 ? end($neighbours) : $neighbours[0];
        }

        $candidate = Carbon::parse($selectedDate)->addDays($direction)->toDateString();

        if ($candidate > $window['end']) {
            return null;
        }

        if ($window['start'] !== null && $candidate < $window['start']) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{present: int, absent: int, unconfirmed: int, unmarked: int, recorded: int, rate: ?float}
     */
    private function tally(Collection $rows): array
    {
        $present = $rows->where('status', 'present')->count();
        $absent = $rows->where('status', 'absent')->count();
        $unconfirmed = $rows->where('status', 'unconfirmed')->count();
        $confirmed = $present + $absent;

        return [
            'present' => $present,
            'absent' => $absent,
            'unconfirmed' => $unconfirmed,
            'unmarked' => $rows->count() - $present - $absent - $unconfirmed,
            'recorded' => $present + $absent + $unconfirmed,
            // Null, not zero: a session nobody has recorded is not 0% turnout.
            'rate' => $confirmed > 0 ? round(($present / $confirmed) * 100, 1) : null,
        ];
    }

    /**
     * The programme's turnout across every session so far — a different figure
     * from today's rate, and labelled as such on screen.
     *
     * @param  Collection<int, array<string, mixed>>  $marks
     */
    private function cumulativeRate(Collection $marks): ?float
    {
        $present = $marks->where('status', 'present')->count();
        $confirmed = $present + $marks->where('status', 'absent')->count();

        return $confirmed > 0 ? round(($present / $confirmed) * 100, 1) : null;
    }

    /**
     * Grade, section and attendance status. Every one reads an encrypted or
     * derived value, so all three are applied in PHP after fetch.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{grade: string, section: string, status: string}
     */
    private function readFilters(Request $request, Collection $rows): array
    {
        $options = $this->filterOptions($rows);

        $grade = trim((string) $request->query('grade', ''));
        if (! in_array($grade, $options['grades'], true)) {
            $grade = '';
        }

        $section = trim((string) $request->query('section', ''));
        if (! in_array($section, $options['sections'], true)) {
            $section = '';
        }

        $status = trim((string) $request->query('status', ''));
        if (! in_array($status, ['present', 'absent', 'unmarked', 'unconfirmed', 'at_risk'], true)) {
            $status = '';
        }

        return ['grade' => $grade, 'section' => $section, 'status' => $status];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{grade: string, section: string, status: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when($filters['grade'] !== '', fn (Collection $r) => $r->where('grade', $filters['grade']))
            ->when($filters['section'] !== '', fn (Collection $r) => $r->where('section', $filters['section']))
            // at_risk is a cumulative standing, not a mark on this session, so
            // it narrows the per-beneficiary roll rather than the sheet.
            ->when(
                in_array($filters['status'], ['present', 'absent', 'unmarked', 'unconfirmed'], true),
                fn (Collection $r) => $r->where('status', $filters['status'])
            )
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{grades: list<string>, sections: list<string>}
     */
    private function filterOptions(Collection $rows): array
    {
        return [
            'grades' => $rows->pluck('grade')->filter()->unique()->sort(SORT_NATURAL)->values()->all(),
            'sections' => $rows->pluck('section')->filter()->unique()->sort(SORT_NATURAL)->values()->all(),
        ];
    }

    private function isCoordinator(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'feeding_coor';
    }
}
