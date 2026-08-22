<?php

namespace App\Http\Controllers;

use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingProgramCycle;
use App\Support\SchemaCache;
use App\Support\SchoolLetterhead;
use App\Support\SchoolSignatories;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
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
 *   FeedingAtRiskRule::forInstitution(), never a constant in this file;
 * - and a rate is not a classification until the school's minimum observation
 *   period has passed. Until then a learner reads **Early Monitoring**: their
 *   rate is computed and shown, but nothing flags them. One of four recorded
 *   sessions is 25% and would fail every threshold, yet four sessions is not a
 *   programme problem — it is too little history to put a child on a follow-up
 *   list over.
 *
 * Three different figures live on this page and are labelled as three different
 * figures, never merged: **this session's** turnout, the programme's
 * **cumulative** turnout over confirmed sessions, and the **programme day**
 * within the cycle. A feeding day the school ran but never recorded is a
 * missing record, not a day of absences, so it moves none of them.
 *
 * Saving goes through FeedingProgramController::storeRecordedAttendance — the
 * one audited write path for a recorded session — rather than a second one
 * here.
 */
class FeedingAttendanceController extends Controller
{
    /** The four readings of attendance this tab offers. */
    private const VIEWS = ['sheet', 'history', 'beneficiary', 'calendar'];

    /**
     * What the Attendance control can ask, beyond "All".
     *
     * Exactly the two things a confirmed mark can be. A learner nobody wrote
     * down and a scanned mark nobody has read are both printed on their rows,
     * but neither is an answer to "who came today".
     */
    private const MARK_FILTERS = ['present', 'absent'];

    /** What the Standing control can ask — a verdict across the programme. */
    private const STANDING_FILTERS = ['at_risk', 'early_monitoring'];

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
     * **It is written as the form, not as a dump of the table.** The point of
     * the export is that it can be signed and handed in; a coordinator who has
     * to merge the title, rule the grid, widen the name column and retype the
     * signature block has not been given a form, they have been given data and
     * an evening's work. So the workbook carries the whole document: the DepEd
     * letterhead centred across the sheet, the school and its address, the
     * ruled `NO. | NAME | GRADE | SECTION | dates…` grid with the dates set
     * upright so a hundred-and-twenty-day sheet still fits a page, the tally
     * row, and the Prepared by / Noted by block signed by the school's own
     * staff. It opens on A4 landscape with the head row frozen and the columns
     * already sized.
     *
     * The two seals cannot travel: a spreadsheet written by OpenSpout carries
     * no images. The letterhead text is what makes this the same document as
     * the printed sheet, and the printed sheet is where the seals are.
     *
     * A mark is written exactly as the sheet reads it — a tick for served, `A`
     * for a confirmed absence, and an empty cell where nobody recorded the
     * learner. A blank cell is never an absence.
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
        $institutionId = $request->session()->get('active_institution_id');
        $letterhead = SchoolLetterhead::for($institutionId, (string) $data['schoolName']);

        $dates = $data['sessionDates'];
        $rows = $data['exportRows'];
        // NO. | NAME | GRADE | SECTION, then one column per feeding day.
        $width = 4 + count($dates);
        $lastColumn = $width - 1;

        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path — otherwise every export
        // leaves an empty temp file behind.
        $reserved = tempnam(sys_get_temp_dir(), 'sbfp-attendance-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $options = new XlsxOptions(
            pageSetup: new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, fitToWidth: 1),
        );
        // The columns the coordinator would otherwise drag out by hand.
        $options->setColumnWidth(6, 1);      // NO.
        $options->setColumnWidth(34, 2);     // NAME
        $options->setColumnWidth(9, 3);      // GRADE
        $options->setColumnWidth(16, 4);     // SECTION
        if ($dates !== []) {
            $options->setColumnWidth(5.5, ...range(5, $width));
        }

        // The heading block spans the whole grid, exactly as the printed form
        // centres it over the table.
        $headingRows = 8;
        for ($row = 1; $row <= $headingRows; $row++) {
            $options->mergeCells(0, $row, $lastColumn, $row);
        }

        $writer = new XlsxWriter($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Attendance Sheet');

        $centred = (new Style)->withCellAlignment(CellAlignment::CENTER);
        $republic = $centred->withFontSize(11);
        $department = $centred->withFontBold(true)->withFontSize(12);
        $division = $centred->withFontSize(10);
        $school = $centred->withFontBold(true)->withFontSize(12);
        $address = $centred->withFontItalic(true)->withFontSize(10);
        $title = $centred->withFontBold(true)->withFontSize(12);

        foreach (SchoolLetterhead::lines($letterhead) as $index => $line) {
            $writer->addRow($this->sheetLine([$line], match ($index) {
                0 => $republic,
                1 => $department,
                default => $division,
            }, $width));
        }

        $writer->addRow($this->sheetLine([strtoupper($letterhead['school'])], $school, $width));
        // Blank when the school has no address on file: a line to write on,
        // never another school's street.
        $writer->addRow($this->sheetLine([$letterhead['address']], $address, $width));
        $writer->addRow($this->sheetLine(
            ['LISTS OF IDENTIFIED SEVERELY WASTED AND WASTED STUDENTS WHO ARE QUALIFIED FOR FEEDING PROGRAM'],
            $title,
            $width
        ));
        $writer->addRow($this->sheetLine(
            ['S.Y. '.$data['schoolYear'].'  —  ATTENDANCE SHEET  —  FEEDING DAY '.$data['programDay'].' OF '.$data['programDuration']],
            $centred->withFontBold(true),
            $width
        ));
        $writer->addRow($this->sheetLine([''], null, $width));

        $ruled = (new Style)->withBorder($this->hairline());
        $head = $ruled->withFontBold(true)
            ->withCellAlignment(CellAlignment::CENTER)
            ->withCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->withShouldWrapText(true)
            ->withBackgroundColor('F3F4F6');
        // Dates run upright so 120 feeding days still fit the width of a page.
        $dateHead = $head->withTextRotation(90)->withFontSize(9);
        $markCell = $ruled->withCellAlignment(CellAlignment::CENTER);

        $writer->addRow(new Row(array_merge(
            array_map(
                static fn (string $label): Cell => Cell::fromValue($label, $head),
                ['NO.', 'NAME', 'GRADE', 'SECTION']
            ),
            array_map(
                static fn (string $date): Cell => Cell::fromValue(
                    strtoupper(Carbon::parse($date)->format('M j')),
                    $dateHead
                ),
                $dates
            )
        )));

        foreach ($rows as $index => $row) {
            $writer->addRow(new Row(array_merge(
                [
                    Cell::fromValue($index + 1, $markCell),
                    Cell::fromValue($row['name'], $ruled),
                    Cell::fromValue($row['grade'], $markCell),
                    Cell::fromValue($row['section'], $ruled),
                ],
                array_map(
                    static fn (string $date): Cell => Cell::fromValue(match ($row['marks'][$date] ?? 'unmarked') {
                        // The template's own marks: a tick for served, A for a
                        // confirmed absence, and nothing at all where no one
                        // recorded the learner — a blank cell is not an absence.
                        'present' => '✓',
                        'absent' => 'A',
                        default => '',
                    }, $markCell),
                    $dates
                )
            )));
        }

        // The tally the printed sheet carries under the grid: how many were
        // served each day, over how many the day was recorded for.
        $writer->addRow(new Row(array_merge(
            [
                Cell::fromValue('', $head),
                Cell::fromValue('TOTAL SERVED', $head),
                Cell::fromValue('', $head),
                Cell::fromValue('', $head),
            ],
            array_map(
                static fn (string $date): Cell => Cell::fromValue(
                    count(array_filter(
                        $rows,
                        static fn (array $row): bool => ($row['marks'][$date] ?? '') === 'present'
                    )),
                    $head
                ),
                $dates
            )
        )));

        $writer->addRow($this->sheetLine([''], null, $width));
        $this->writeSignatureBlock($writer, $institutionId, (string) $data['schoolName'], $width);

        $writer->close();

        $filename = 'SBFP-Attendance-'.str_replace('/', '-', $data['schoolYear']).'-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * One heading line, padded out to the width of the grid so the merge it
     * sits in has a cell to occupy in every column.
     *
     * @param  list<string>  $values
     */
    private function sheetLine(array $values, ?Style $style, int $width): Row
    {
        $values = array_pad($values, $width, '');

        return new Row(array_map(
            static fn ($value): Cell => Cell::fromValue($value, $style),
            $values
        ));
    }

    /**
     * The Prepared by / Noted by block the form ends in, signed by the school's
     * own staff through SchoolSignatories — so this sheet and the School Head's
     * exported report carry the same names rather than two guesses. A name the
     * app does not hold prints as a blank line to sign.
     */
    private function writeSignatureBlock(XlsxWriter $writer, ?int $institutionId, string $schoolName, int $width): void
    {
        $label = (new Style)->withFontBold(true);
        $name = (new Style)->withFontBold(true)->withBorder(new Border(
            new BorderPart(BorderName::BOTTOM, width: BorderWidth::THIN),
        ));
        $role = (new Style)->withFontItalic(true)->withFontSize(10);

        $prepared = SchoolSignatories::preparedBy($institutionId, $schoolName);
        $noted = SchoolSignatories::notedBy($institutionId, $schoolName);

        $writer->addRow($this->sheetLine(['Prepared by:', '', 'Noted by:'], $label, $width));
        $writer->addRow($this->sheetLine([''], null, $width));
        $writer->addRow($this->sheetLine([strtoupper($prepared), '', strtoupper($noted)], $name, $width));
        $writer->addRow($this->sheetLine(['Feeding Coordinator', '', 'School Head'], $role, $width));
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
        $allBeneficiaries = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $allBeneficiaries = StudentHealthRecord::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->forCurrentSchoolYear($schoolYear)
                ->get()
                ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
                ->sortBy(fn (StudentHealthRecord $record): string => strtolower((string) $record->student_name))
                ->values();
        }

        // One query for every mark the whole roll carries; everything below is
        // derived from it in PHP, so no two panels can read different data.
        $allMarks = $this->marksFor($allBeneficiaries);
        $allByDate = $allMarks->groupBy('date')->map(fn (Collection $rows) => $rows->keyBy('record_id'));

        $today = now()->toDateString();
        $selectedDate = $this->resolveDate(
            $request,
            $allMarks->pluck('date')->unique()->sort()->values()->all(),
            $cycle,
            $today
        );
        $window = $this->programWindow($cycle, $today);

        // The whole roll's sheet for this session. Two things are read off it
        // and off nothing else: the filter options (narrowing to Grade 7 must
        // never delete Grade 8 from the dropdown that would get back to it) and
        // whether the session is closed.
        $allRows = $this->sheetRows($allBeneficiaries, $allByDate->get($selectedDate, collect()));
        $filterOptions = $this->filterOptions($allRows);
        $filters = $this->readFilters($request, $allRows);

        // ── Scope, then narrow — the split the Dashboard already uses ────────
        // Grade, section and gender are SCOPE: they move every panel together,
        // so the cards, the sheet, the history, the per-beneficiary roll and the
        // calendar all report the same population. Attendance status is a
        // NARROWING filter: it thins the list being read and leaves the scope
        // alone. Before this the two views below read the whole school whatever
        // the toolbar said, so a coordinator filtered to one section still saw
        // every other section's turnout in the history.
        $scopedIds = $allRows
            ->filter(fn (array $row): bool => $this->inScope($row, $filters))
            ->pluck('id')
            ->flip();

        $beneficiaries = $allBeneficiaries->filter(
            fn (StudentHealthRecord $record): bool => $scopedIds->has($record->id)
        )->values();

        $marks = $allMarks->filter(
            fn (array $row): bool => $scopedIds->has($row['record_id'])
        )->values();

        $sessionDates = $marks
            ->pluck('date')
            ->unique()
            ->sort()
            ->values()
            ->all();

        // date => [record id => mark row]
        $byDate = $marks->groupBy('date')->map(fn (Collection $rows) => $rows->keyBy('record_id'));
        $byRecord = $marks->groupBy('record_id');

        $rows = $allRows->filter(fn (array $row): bool => $scopedIds->has($row['id']))->values();
        $filteredRows = $this->applyFilters($rows, $filters);

        // Whether this session is closed to recording.
        //
        // Deliberately read off the UNFILTERED rows: a session's standing is a
        // fact about the day, and narrowing to one section must never reopen it.
        // Once a human has confirmed a mark for this date the session is a
        // record — the sheet reports it, the modal will not reopen it, and a
        // genuine mistake (or a learner the sheet skipped) is put right on the
        // learner's own beneficiary record, where the change is attributed and
        // audited. An UNCONFIRMED scanned mark does not close a session: nobody
        // has read it, and recording on site is exactly how it gets decided.
        $sessionLocked = $allRows->contains('locked', true);
        $isFeedingDay = FeedingProgramCycle::isFeedingDay($selectedDate);
        $withinWindow = $selectedDate <= $window['end']
            && ($window['start'] === null || $selectedDate >= $window['start']);

        $standings = $this->standings($beneficiaries, $byRecord, $rule, count($sessionDates));
        $atRiskCount = collect($standings)->where('at_risk', true)->count();
        // Learners the threshold has not started classifying yet. They are
        // deliberately not added to the at-risk count — that is the whole point
        // of the observation window — and are reported as their own figure.
        $observingCount = collect($standings)->where('status', FeedingAtRiskRule::STATUS_EARLY_MONITORING)->count();

        $cumulative = $this->cumulative($marks);
        $todayTally = $this->tally($rows);

        return [
            'view' => in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'sheet',
            // Links are built against the tab's own route, never the current
            // one: these partials are re-rendered by the metrics endpoint too,
            // and a link built from request()->fullUrl() there would point a
            // date or a tab at the JSON endpoint.
            'pageUrl' => function (array $overrides = []) use ($request): string {
                // Filtering AFTER the merge is what lets an override clear a
                // parameter: passing 'status' => '' drops it from the URL
                // rather than writing an empty one, so the at-risk notice can
                // land on the roll without carrying the mark filter that was
                // on screen.
                $query = array_filter(
                    array_merge($request->query(), $overrides),
                    static fn ($value): bool => $value !== '' && is_scalar($value)
                );

                return route('dashboard.feedingcor-attendance', $query);
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
            // The whole enrolled roll, so a scoped page can still say what it is
            // a slice of.
            'rollCount' => $allBeneficiaries->count(),
            'isScoped' => $filters['grade'] !== '' || $filters['section'] !== '' || $filters['sex'] !== '',
            'rows' => $filteredRows,
            'unfilteredRows' => $rows,
            'tally' => $todayTally,
            // The programme's own turnout, kept apart from the session's: same
            // word, different question, and merging them is how a coordinator
            // reads today's 100% as the programme's 100%.
            'cumulative' => $cumulative,
            'filters' => $filters,
            // Built from the whole roll, never from the scoped rows: options
            // computed off a filtered list delete the very choice that would
            // widen it again.
            'filterOptions' => $filterOptions,
            'sessionRecorded' => $allByDate->has($selectedDate),
            // The sheet is a record, never a form: it reports what was recorded
            // and offers no control at all. Recording happens in the modal, and
            // only for a session still open.
            'sessionLocked' => $sessionLocked,
            'isFeedingDay' => $isFeedingDay,
            'canRecord' => ! $sessionLocked
                && $isFeedingDay
                && $withinWindow
                && $allBeneficiaries->isNotEmpty(),
            // Why recording is unavailable, in the words the coordinator needs.
            'recordBlockedReason' => match (true) {
                $allBeneficiaries->isEmpty() => 'No beneficiary is enrolled for this school year yet.',
                ! $isFeedingDay => Carbon::parse($selectedDate)->format('l, F j, Y').' is a weekend. There are no feeding sessions on Saturdays or Sundays.',
                ! $withinWindow => 'That date is outside the running feeding programme.',
                $sessionLocked => 'Attendance for '.Carbon::parse($selectedDate)->format('F j, Y').' has already been recorded. Correct a mark on the learner’s beneficiary record.',
                default => '',
            },
            // The modal's roll: every beneficiary, unfiltered, so a coordinator
            // cannot record half a session because a filter was left on.
            'recordRows' => $allRows,
            'openMarkCount' => $filteredRows->where('locked', false)->count(),
            'lockedMarkCount' => $filteredRows->where('locked', true)->count(),
            'atRisk' => [
                'count' => $atRiskCount,
                'threshold' => $rule->thresholdPercent(),
                'thresholdLabel' => rtrim(rtrim(number_format($rule->thresholdPercent(), 1), '0'), '.'),
                'rule' => $rule->describe(),
                // The observation window: how many learners it is still holding
                // back, and what it is set to for this school.
                'observing' => $observingCount,
                'minimumObservationDays' => $rule->minimumObservationDays(),
                'observationRule' => $rule->describeObservation(),
            ],
            'history' => $this->history($beneficiaries, $byDate, $sessionDates, $filters),
            'beneficiaryRows' => $this->beneficiaryRows(
                $beneficiaries,
                $standings,
                $byDate->get($selectedDate, collect()),
                $filters
            ),
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

            $status = $mark['status'] ?? 'unmarked';

            return [
                'id' => $record->id,
                'name' => (string) $record->student_name,
                'grade' => $grade,
                'grade_number' => preg_replace('/^grade\s*/i', '', $grade),
                'section' => $section,
                // Carried on the row so the sex filter and the search can read
                // it without going back to the encrypted record.
                'sex' => FeedingBeneficiarySummary::sexOf($record),
                'status' => $status,
                'remarks' => $mark['remarks'] ?? '',
                // A mark a human has already recorded for this learner on this
                // day is settled: the sheet shows it and offers no way to
                // change it, here or on the server. An UNCONFIRMED scanned mark
                // is deliberately not locked — nobody has read it yet, and
                // recording on site is exactly how it gets decided.
                'locked' => in_array($status, ['present', 'absent'], true),
            ];
        })->values();
    }

    /**
     * Where each beneficiary stands with the school's rule, from one reading of
     * their marks — so the rate printed beside a learner is exactly the one
     * that decided their flag, and the badge beside it is the one state the
     * rule put them in.
     *
     * `not_marked` is the school's feeding days minus the days this learner was
     * marked on at all. It is reported as its own figure and never folded into
     * absences: a session no sheet covered them for is a missing record, and
     * the difference between "did not come" and "nobody wrote it down" is the
     * difference between a follow-up and a filing job.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $byRecord
     * @param  int  $sessionCount  feeding days the school recorded at all
     * @return array<int, array{present: int, absent: int, confirmed: int, unconfirmed: int, not_marked: int, rate: ?float, at_risk: bool, status: string, sessions_needed: int}>
     */
    private function standings(Collection $beneficiaries, Collection $byRecord, FeedingAtRiskRule $rule, int $sessionCount): array
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
            $confirmed = $rule->confirmedCount($marks);

            $standings[$record->id] = [
                'present' => $present,
                'absent' => $confirmed - $present,
                'confirmed' => $confirmed,
                'unconfirmed' => count($marks) - $confirmed,
                'not_marked' => max(0, $sessionCount - count($marks)),
                'rate' => $rule->attendanceRate($marks),
                'at_risk' => $rule->isAtRisk($marks),
                'status' => $rule->status($marks),
                'sessions_needed' => $rule->sessionsUntilClassification($marks),
            ];
        }

        return $standings;
    }

    /**
     * One row per feeding day: what the school recorded that session.
     *
     * Every figure here is already scoped — $beneficiaries and $byDate arrive
     * narrowed to the grade, section and gender the toolbar is set to — so a
     * coordinator reading one section's history reads that section's turnout,
     * not the school's.
     *
     * The attendance filter then narrows the list itself: choosing Present
     * keeps only the sessions somebody was actually present at, and choosing
     * Absent only the ones that carried an absence. The row still prints the
     * whole tally, because hiding a session's other half would turn a filtered
     * list into a wrong one.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $byDate
     * @param  list<string>  $sessionDates
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function history(Collection $beneficiaries, Collection $byDate, array $sessionDates, array $filters): array
    {
        $expected = $beneficiaries->count();
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
                // Feeding days nobody wrote this population down on. Its own
                // figure, never added to the absences.
                'unmarked' => max(0, $expected - $rows->count()),
                'recorded' => $rows->count(),
                'expected' => $expected,
                'rate' => $confirmed > 0 ? round(($present / $confirmed) * 100, 1) : null,
                'complete' => $expected > 0 && $rows->count() >= $expected,
            ];
        }

        // Newest first: the last session is the one a coordinator opens this
        // view to check.
        $history = array_reverse($history);

        // A cumulative standing is a verdict on a learner, not on a day, so it
        // says nothing about which sessions to keep and leaves this list alone.
        if ($filters['status'] === '') {
            return $history;
        }

        return array_values(array_filter(
            $history,
            fn (array $session): bool => $session[$filters['status']] > 0
        ));
    }

    /**
     * Attendance by beneficiary — the cumulative view the threshold is applied
     * to. Deliberately carries no baseline, BMI or endline column: a learner's
     * health profile is the Beneficiaries tab's responsibility, not this one's.
     *
     * Grade, section and gender have already scoped $beneficiaries. What is
     * left for this method is the attendance filter, and there it means one
     * learner's mark on the session the toolbar is set to: choosing Present
     * leaves the learners marked present that day and takes the absent ones
     * off, and the other way round. Those four states are exclusive, so the
     * roll a filter produces is exactly the roll it names — and the "Session"
     * column prints the mark that put each row there, so the list explains
     * itself rather than being narrowed by something invisible.
     *
     * The figures beside it stay cumulative: they are what the school's
     * threshold judged, and a filter must not change the evidence it read.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  array<int, array<string, mixed>>  $standings
     * @param  Collection<int, array<string, mixed>>  $marksForDate  keyed by record id
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function beneficiaryRows(Collection $beneficiaries, array $standings, Collection $marksForDate, array $filters): array
    {
        $blank = [
            'present' => 0, 'absent' => 0, 'confirmed' => 0, 'unconfirmed' => 0, 'not_marked' => 0,
            'rate' => null, 'at_risk' => false, 'status' => FeedingAtRiskRule::STATUS_EARLY_MONITORING,
            'sessions_needed' => 0,
        ];

        return $beneficiaries
            ->map(function (StudentHealthRecord $record) use ($standings, $marksForDate, $blank): array {
                [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);
                $standing = $standings[$record->id] ?? $blank;

                return [
                    'id' => $record->id,
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'grade_number' => preg_replace('/^grade\s*/i', '', $grade),
                    'section' => $section,
                    'sex' => FeedingBeneficiarySummary::sexOf($record),
                    // This learner's mark on the selected session — the one the
                    // attendance filter narrows on, printed so the row says why
                    // it is here. A learner no sheet covered is "unmarked",
                    // never an absence.
                    'session_status' => $marksForDate->get($record->id)['status'] ?? 'unmarked',
                    'present' => $standing['present'],
                    'absent' => $standing['absent'],
                    'confirmed' => $standing['confirmed'],
                    // Feeding days no sheet covered this learner on. Its own
                    // column, never added to absences.
                    'not_marked' => $standing['not_marked'],
                    'rate' => $standing['rate'],
                    'at_risk' => $standing['at_risk'],
                    'status' => $standing['status'],
                    'sessions_needed' => $standing['sessions_needed'],
                ];
            })
            // A session's marks are exclusive, so filtering to one of them
            // leaves exactly that roll — Present takes the absent rows off, and
            // Absent takes the present ones off.
            ->when(
                $filters['status'] !== '',
                fn (Collection $rows) => $rows->where('session_status', $filters['status'])
            )
            // "View At-Risk Beneficiaries" lands here with the flag already on.
            ->when($filters['standing'] === 'at_risk', fn (Collection $rows) => $rows->where('at_risk', true))
            // The learners the observation window is still holding back — who a
            // coordinator asking "why is nobody flagged yet?" is looking for.
            ->when(
                $filters['standing'] === 'early_monitoring',
                fn (Collection $rows) => $rows->where('status', FeedingAtRiskRule::STATUS_EARLY_MONITORING)
            )
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

        // 120 *feeding* days, not 120 calendar days: weekends are not sessions,
        // so a calendar window closed the programme about seven weeks early.
        $last = $cycle->endDateIso() ?? $today;

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

        // Step over the weekend rather than onto it: Saturday and Sunday are not
        // feeding days, so "previous day" from a Monday is the Friday before.
        $cursor = Carbon::parse($selectedDate);

        do {
            $cursor->addDays($direction);
            $candidate = $cursor->toDateString();

            if ($candidate > $window['end']) {
                return null;
            }

            if ($window['start'] !== null && $candidate < $window['start']) {
                return null;
            }
        } while (! FeedingProgramCycle::isFeedingDay($cursor));

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
     * from today's, and labelled as such on screen.
     *
     * `sessions` is the count of feeding days the school actually recorded, not
     * the programme day: a coordinator reading 25% needs to know it was taken
     * over four sheets, not over the twenty days the cycle has been running.
     *
     * @param  Collection<int, array<string, mixed>>  $marks
     * @return array{present: int, absent: int, unconfirmed: int, confirmed: int, sessions: int, rate: ?float}
     */
    private function cumulative(Collection $marks): array
    {
        $present = $marks->where('status', 'present')->count();
        $absent = $marks->where('status', 'absent')->count();
        $confirmed = $present + $absent;

        return [
            'present' => $present,
            'absent' => $absent,
            'unconfirmed' => $marks->where('status', 'unconfirmed')->count(),
            'confirmed' => $confirmed,
            'sessions' => $marks->pluck('date')->unique()->count(),
            // Null, not zero: no confirmed session is not a turnout of nothing.
            'rate' => $confirmed > 0 ? round(($present / $confirmed) * 100, 1) : null,
        ];
    }

    /**
     * Grade, section, gender, the session's mark and the cumulative standing.
     * Every one reads an encrypted or derived value, so all of them are applied
     * in PHP after fetch.
     *
     * **The mark and the standing are two questions, so they are two controls.**
     * "Attendance" answers what happened on this session — All, Present, Absent
     * — and those three are the only answers it has, because they are the only
     * three a mark can be. Whether a learner is at risk across the programme is
     * a verdict on twelve sheets rather than a mark on one, so it lives on its
     * own `standing` control on the roll it applies to. Folding the two into a
     * single dropdown put "Absent" and "At risk (cumulative)" in one list as
     * though they answered the same question.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{grade: string, section: string, sex: string, status: string, standing: string}
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

        // Sex is scope alongside grade and section: it moves the sheet and the
        // per-beneficiary roll together. Read off the encrypted details blob,
        // so it is applied in PHP like the other two.
        $sex = trim((string) $request->query('sex', ''));
        if (! in_array($sex, FeedingBeneficiarySummary::SEX_OPTIONS, true)) {
            $sex = '';
        }

        // The three answers a mark has. "Unmarked" and "unconfirmed" are states
        // a row can be in, and both are printed on the row, but neither is a
        // filter: a coordinator asking this control a question is asking who
        // came and who did not.
        $status = trim((string) $request->query('status', ''));
        if (! in_array($status, self::MARK_FILTERS, true)) {
            $status = '';
        }

        $standing = trim((string) $request->query('standing', ''));
        if (! in_array($standing, self::STANDING_FILTERS, true)) {
            $standing = '';
        }

        return ['grade' => $grade, 'section' => $section, 'sex' => $sex, 'status' => $status, 'standing' => $standing];
    }

    /**
     * Whether a row is inside the tab's scope — grade, section and gender.
     *
     * Scope is the one thing every panel shares, so it lives in one predicate
     * that all of them are built from, rather than being re-typed per view
     * where two copies could quietly drift apart.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $filters
     */
    private function inScope(array $row, array $filters): bool
    {
        return ($filters['grade'] === '' || $row['grade'] === $filters['grade'])
            && ($filters['section'] === '' || $row['section'] === $filters['section'])
            && ($filters['sex'] === '' || $row['sex'] === $filters['sex']);
    }

    /**
     * The sheet's own narrowing. Scope has already been applied to $rows, so
     * what is left is the attendance status.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{grade: string, section: string, sex: string, status: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            // A standing is a verdict across the programme, not a mark on this
            // session, so it narrows the per-beneficiary roll rather than the
            // sheet.
            ->when(
                $filters['status'] !== '',
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
