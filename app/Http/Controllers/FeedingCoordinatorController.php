<?php

namespace App\Http\Controllers;

use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use App\Support\BmiAssessmentReport;
use App\Support\BmiReportInsight;
use App\Support\ChangeStamp;
use App\Support\FeedingAtRiskRule;
use App\Support\FeedingAttendanceFlag;
use App\Support\FeedingAttendanceMark;
use App\Support\FeedingAttendanceWindow;
use App\Support\FeedingBeneficiarySummary;
use App\Support\FeedingNutritionProgress;
use App\Support\FeedingProgramCycle;
use App\Support\FeedingReportNarrative;
use App\Support\FeedingWeighIns;
use App\Support\NarrativeReportPdf;
use App\Support\RequestMemo;
use App\Support\SbfpFormWorkbook;
use App\Support\SchemaCache;
use App\Support\SchoolCalendar;
use App\Support\SchoolLetterhead;
use App\Support\SchoolPulse;
use App\Support\SchoolSignatories;
use App\Support\SchoolYear;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FeedingCoordinatorController extends Controller
{
    /**
     * Who may open the SBFP forms page. The School Nurse and the Clinic Teacher
     * read it as their Forms & Templates tab — every form but the narrative
     * report, which is the coordinator's own account of the programme — each
     * in their own rail, from this one controller, so no two desks can print
     * different figures on the same form.
     */
    public const FORM_READERS = ['feeding_coor', 'school_nurse', 'clinic_teacher'];

    /** The readers who get the clinic's copy of that page. */
    private const CLINIC_FORM_READERS = ['school_nurse', 'clinic_teacher'];

    public function sbfpForms(Request $request): View|RedirectResponse
    {
        if (! $this->readsForms($request)) {
            return redirect()->route('login')
                ->with('error', 'Only the Feeding Coordinator, the School Nurse and the Clinic Teacher can open the SBFP forms.');
        }

        $forClinic = $this->readsAsClinic($request);
        $institutionId = $request->session()->get('active_institution_id');

        // Stamped before the roster is read, so an enrolment landing between
        // the two is caught by the next pulse rather than missed by both.
        $rosterStamp = $this->formsStamp($request);
        [$records, $studentsByGrade, $sectionsByGrade] = $this->sbfpRoster($institutionId);

        // The narrative below reports the programme, and the programme only
        // feeds covered grades — so it reads the same narrowed set every other
        // form on this page does.
        $unfilteredRecords = $records;

        $letterhead = SchoolLetterhead::for($institutionId, (string) $request->session()->get('active_school_name', ''));

        // The grid counted for each population the report offers, each once for
        // the whole school and once per section.
        $bmiReports = $this->bmiReports($records);
        $bmiValueSets = $bmiReports['all']['values'];

        // The class advisers' blank CLASS MASTERLIST is theirs alone (it is
        // downloaded from the adviser's own Enroll Student panel), so the
        // clinic's copy of this page no longer offers it.
        return view('feedingcor-dashboard.sbfp-forms', [
            'forClinic' => $forClinic,
            'forClinicTeacher' => $request->session()->get('active_role') === 'clinic_teacher',
            'studentsByGrade' => $studentsByGrade,
            'gradeOptions' => array_keys($studentsByGrade),
            'sectionsByGrade' => $sectionsByGrade,
            // Where the master lists' live refresh starts counting from: a
            // learner an adviser enrols, or one the coordinator enrols, after
            // this paint moves it.
            'rosterStamp' => $rosterStamp,
            'bmiValues' => $bmiValueSets[''],
            // The same grid, counted again over each section on its own. It is
            // precomputed rather than fetched per choice because every field it
            // reads is encrypted at rest: the counting has to happen in PHP
            // whatever triggers it, and doing it once here means the section a
            // coordinator picks is drawn from the same reading as the grade —
            // one computation, so a section grid and its grade grid can never
            // disagree about the same children.
            'bmiValueSets' => $bmiValueSets,
            // What each of those grids shows, in a few sentences under its chart:
            // written from the same value sets, so the words and the bars cannot
            // describe different children, and keyed the same way so the Grade
            // and Section filter swaps the insight with the figures.
            'bmiInsights' => $bmiReports['all']['insights'],
            // Both of those again for each population, for the page's filter:
            // what the first paint above shows is All Learners.
            'bmiReports' => $bmiReports,
            'schoolYear' => StudentHealthRecord::currentSchoolYear(),
            'nurseName' => $this->resolveSchoolNurseName($institutionId, (string) $request->session()->get('active_school_name', '')),
            // The BMI report counted over the beneficiaries alone is the
            // coordinator's, attested by the clinic and noted by the head.
            'coordinatorName' => SchoolSignatories::feedingCoordinator($institutionId, (string) $request->session()->get('active_school_name', '')),
            'headName' => SchoolSignatories::notedBy($institutionId, (string) $request->session()->get('active_school_name', '')),
            // The DepEd heading every form on this page opens under.
            'letterhead' => $letterhead,
            'seals' => SchoolLetterhead::seals(),
            // The narrative report's own paragraphs, derived from the same
            // figures the grids above are drawn from rather than re-typed
            // beside them. It fills the form as a starting draft the
            // coordinator edits; a saved draft is never overwritten by it,
            // because hand-written work outranks a re-fill.
            //
            // Built from the WHOLE year's roll, not the grade-filtered set:
            // this is the school's report on its programme, and a narrative
            // that silently described one grade would be read as describing
            // all of them.
            //
            // Not drafted at all for the clinic's readers, whose page carries
            // no narrative report.
            'narrative' => $forClinic ? [] : FeedingReportNarrative::build(
                $unfilteredRecords,
                (string) ($letterhead['school'] ?? $request->session()->get('active_school_name', '')),
                StudentHealthRecord::currentSchoolYear(),
                FeedingProgramCycle::forInstitution($institutionId),
                FeedingAtRiskRule::forInstitution($institutionId),
            ),
        ]);
    }

    private function readsForms(Request $request): bool
    {
        return in_array((string) $request->session()->get('active_role', ''), self::FORM_READERS, true);
    }

    private function readsAsClinic(Request $request): bool
    {
        return in_array((string) $request->session()->get('active_role', ''), self::CLINIC_FORM_READERS, true);
    }

    /**
     * The stamp the forms page's live roster compares against — the one its
     * reader's pulse serves. The coordinator polls their own pulse; the clinic's
     * readers poll the school-wide one their screens already read
     * (`workspace.pulse`), because the coordinator's lives under
     * /dashboard/feedingcor, where a demo session would be re-seeded as the
     * coordinator. Two kinds of stamp must never be compared with each other,
     * or the roster is re-read on every tick.
     */
    private function formsStamp(Request $request): string
    {
        $institutionId = $request->session()->get('active_institution_id');

        return $this->readsAsClinic($request)
            ? SchoolPulse::stamp($institutionId ? (int) $institutionId : null)
            : $this->metricsStamp($institutionId);
    }

    /**
     * The roster behind the SBFP Forms page, read once and grouped by grade:
     * the page paints from it and the master lists' live refresh re-reads it,
     * so the form a coordinator has open and the one a reload would draw can
     * never name different children.
     *
     * @return array{0: Collection<int, StudentHealthRecord>, 1: array<string, list<array<string, mixed>>>, 2: array<string, list<string>>}
     */
    private function sbfpRoster(?int $institutionId): array
    {
        $records = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $query = StudentHealthRecord::query();
            if ($institutionId) {
                $query->where('institution_id', $institutionId);
            }
            $records = $query->forCurrentSchoolYear()->get();
        }

        // Every form on this page is an SBFP form, and SBFP is Junior High only.
        // The whole page is therefore narrowed once, here, to the grades the
        // programme covers — read from FeedingBeneficiarySummary::GRADE_LEVELS,
        // never re-typed. Without it the Grade Level dropdown offered whatever
        // grades happened to be on the roll (Grade 11, Grade 12, an elementary
        // grade, "Unassigned") and would auto-fill a masterlist of qualified
        // recipients for learners the programme cannot feed. The BMI grids drop
        // them already; this makes the two agree rather than relying on it.
        $records = $records->filter(
            fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::coversGrade($record)
        )->values();

        // Group adviser-entered students by grade level so each SBFP form is
        // filled with one grade only — Grade 8 is never mixed with Grade 9.
        // Names and statuses are encrypted at rest, so the grouping and sorting
        // happen in PHP after fetch (the plain "section" column holds the grade).
        $studentsByGrade = [];
        $sectionsByGrade = [];
        foreach ($records as $record) {
            [$grade, $section] = $this->splitSection((string) $record->section);
            $status = $this->normalizeStatus((string) $record->nutritional_status);

            $studentsByGrade[$grade][] = [
                'name' => (string) $record->student_name,
                'grade' => $grade,
                'section' => $section,
                'status' => $status,
                'bmi' => $record->bmi_value !== null ? (string) $record->bmi_value : '',
                'lists' => $this->masterlistsFor($record),
                // Who holds the class, for the two master lists' Adviser column.
                'adviser' => SchoolSignatories::classAdviser($institutionId, $grade, $section),
            ];

            if ($section !== '') {
                $sectionsByGrade[$grade][$section] = true;
            }
        }

        uksort($studentsByGrade, fn (string $a, string $b): int => strnatcasecmp($a, $b));
        foreach ($studentsByGrade as $grade => $rows) {
            usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            $studentsByGrade[$grade] = $rows;
        }

        // Each grade's own sections, so the Section control offers what the
        // grade actually runs rather than every section in the school.
        uksort($sectionsByGrade, fn (string $a, string $b): int => strnatcasecmp($a, $b));
        foreach ($sectionsByGrade as $grade => $sections) {
            $names = array_keys($sections);
            usort($names, 'strnatcasecmp');
            $sectionsByGrade[$grade] = $names;
        }

        return [$records, $studentsByGrade, $sectionsByGrade];
    }

    /**
     * Which of the SBFP Forms page's two master lists a learner is written
     * on, keyed by the page's template ids. Decided here, once, so the page
     * only draws what it is told and the rule can be tested without a browser.
     *
     * Both name a learner only once the Feeding Coordinator has enrolled them:
     * isBeneficiary(), qualified AND enrolled AND not removed, the same test
     * every other coordinator screen counts with. A class adviser's enrolment
     * puts a learner on the roster, never on either list.
     *
     * @return list<string>
     */
    private function masterlistsFor(StudentHealthRecord $record): array
    {
        return FeedingBeneficiarySummary::isBeneficiary($record)
            ? ['feeding-beneficiaries', 'feeding-masterlist']
            : [];
    }

    /**
     * The SBFP Forms roster for the live refresh, re-read when the reader's
     * pulse moves. A learner the coordinator enrols reaches both master lists
     * and the BMI report's Only Beneficiaries figures this way, without a
     * reload — the same grouping, the same masterlistsFor() and the same
     * bmiReports() the first paint used.
     */
    public function sbfpFormsRoster(Request $request): JsonResponse
    {
        if (! $this->readsForms($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $institutionId = $request->session()->get('active_institution_id');
        $stamp = $this->formsStamp($request);
        [$records, $studentsByGrade, $sectionsByGrade] = $this->sbfpRoster($institutionId);

        return response()->json([
            'stamp' => $stamp,
            'studentsByGrade' => (object) $studentsByGrade,
            'sectionsByGrade' => (object) $sectionsByGrade,
            'bmiReports' => $this->bmiReports($records),
        ]);
    }

    /** The page's forms that download as a workbook. The narrative is a PDF. */
    public const WORKBOOK_TEMPLATES = ['bmi-report', 'feeding-beneficiaries', 'feeding-masterlist'];

    /**
     * Download Excel: the form on screen, as a workbook laid out as the form.
     *
     * The page posts what it is showing — the template, its four filters and
     * the words typed around the table (address, school year, signatories) —
     * and nothing else. Every figure and every learner is re-read here from
     * the same roster and the same BmiAssessmentReport the page was painted
     * from, scoped to the reader's own school, so a number off the wire
     * decides nothing. A filter naming a grade or section the roll does not
     * hold reads as the whole school, exactly as the page's own controls
     * would. POST rather than GET because staff names have no business in a
     * URL, where every proxy and log would keep them.
     */
    public function downloadForm(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->readsForms($request)) {
            return redirect()->route('login')
                ->with('error', 'Only the Feeding Coordinator, the School Nurse and the Clinic Teacher can download the SBFP forms.');
        }

        $template = (string) $request->input('template', '');
        if (! in_array($template, self::WORKBOOK_TEMPLATES, true)) {
            return back()->with('error', 'Choose a form to download.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $schoolName = (string) $request->session()->get('active_school_name', '');
        [$records, $studentsByGrade, $sectionsByGrade] = $this->sbfpRoster($institutionId);

        $grade = trim((string) $request->input('grade', ''));
        if (! array_key_exists($grade, $studentsByGrade)) {
            $grade = '';
        }
        $section = trim((string) $request->input('section', ''));
        if ($grade === '' || ! in_array($section, $sectionsByGrade[$grade] ?? [], true)) {
            $section = '';
        }

        $fields = $this->typedFields($request);
        $letterhead = SchoolLetterhead::for($institutionId, $schoolName);
        $schoolYear = StudentHealthRecord::currentSchoolYear();

        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path.
        $reserved = tempnam(sys_get_temp_dir(), 'sbfp-form-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        if ($template === 'bmi-report') {
            $phase = in_array($request->input('phase'), ['baseline', 'endline', 'both'], true)
                ? (string) $request->input('phase')
                : 'baseline';
            $population = $request->input('population') === 'beneficiaries' ? 'beneficiaries' : 'all';

            $writer = SbfpFormWorkbook::writer(landscape: true);
            $writer->openToFile($path);
            $this->writeBmiReport($writer, $records, $phase, $population, $grade, $section, $fields, $letterhead, $schoolYear, $institutionId, $schoolName);
            SbfpFormWorkbook::close($writer, $path);

            $name = 'BMI Report '.['baseline' => 'Baseline', 'endline' => 'Final', 'both' => 'Baseline and Final'][$phase];
        } else {
            $beneficiaryList = $template === 'feeding-beneficiaries';
            $prefix = $beneficiaryList ? 'mlb' : 'ml';
            $name = $beneficiaryList ? 'Master List of Beneficiaries' : 'Masterlist of Qualified Recipients';
            $typed = $this->typedReader($fields);

            $writer = SbfpFormWorkbook::writer(landscape: false);
            $writer->openToFile($path);
            SbfpFormWorkbook::masterlist(
                $writer,
                sheetName: $beneficiaryList ? 'Beneficiaries' : 'Masterlist',
                letterhead: ['address' => $typed($prefix.'_school_address', $letterhead['address'])] + $letterhead,
                title: $beneficiaryList
                    ? FeedingMasterlistExportController::BENEFICIARY_LIST_TITLE
                    : FeedingMasterlistExportController::LISTS['beneficiaries'][0],
                schoolYear: $typed($prefix.'_school_year', $schoolYear),
                scope: ($grade !== '' || $section !== '') ? $this->formScopeLabel($grade, $section) : '',
                rows: $this->masterlistRows($studentsByGrade, $template, $grade, $section),
                signatories: [
                    ['label' => 'Prepared by:', 'name' => $typed($prefix.'_prepared_name', ''), 'role' => 'Feeding Coordinator'],
                    ['label' => 'Noted by:', 'name' => $typed($prefix.'_noted_name', ''), 'role' => $typed($prefix.'_noted_role', '') ?: SchoolSignatories::HEAD_DESIGNATION],
                ],
            );
            SbfpFormWorkbook::close($writer, $path);
        }

        return response()
            ->download($path, SbfpFormWorkbook::filename($name, $schoolYear))
            ->deleteFileAfterSend();
    }

    /**
     * The BMI report as the page shows it: one sheet per weighing on screen,
     * the grids the Grade control leaves visible, counted over the population
     * and section the page counted, with the insight and signatories it shows.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $letterhead
     */
    private function writeBmiReport(
        Writer $writer,
        Collection $records,
        string $phase,
        string $population,
        string $grade,
        string $section,
        array $fields,
        array $letterhead,
        string $schoolYear,
        ?int $institutionId,
        string $schoolName,
    ): void {
        $report = $this->bmiReports($records)[$population];
        $setKey = ($grade !== '' && $section !== '' && isset($report['values'][$grade.' / '.$section]))
            ? $grade.' / '.$section
            : '';
        $values = $report['values'][$setKey] ?? [];

        // A chosen grade shows that grade's grid alone (Overall hidden too);
        // All Grade Level shows every grade and the Overall grid.
        $gradeKey = $grade !== '' ? BmiAssessmentReport::gradeKey($grade) : null;
        $gridKeys = $gradeKey !== null ? [$gradeKey] : array_merge(BmiAssessmentReport::gradeKeys(), ['overall']);
        $insightSet = $report['insights'][$setKey] ?? $report['insights'][''] ?? [];

        $beneficiaries = $population === 'beneficiaries';
        $populationLabel = $beneficiaries ? 'Only Beneficiaries' : '';
        $where = $this->formScopeLabel($grade, $section);
        $scope = $beneficiaries
            ? $populationLabel.' · '.$where
            : (($grade !== '' || $section !== '') ? $where : '');
        $insightWhere = $grade === '' ? 'All Grade Levels' : ($section !== '' ? $grade.' · '.$section : $grade);

        $nurse = $this->resolveSchoolNurseName($institutionId, $schoolName);
        $typed = $this->typedReader($fields);
        $phases = $phase === 'both' ? ['baseline', 'endline'] : [$phase];

        foreach ($phases as $index => $weighing) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $prefix = BmiAssessmentReport::prefixFor($weighing);
            // Who signs follows who the report counts, as on the page.
            $signatories = $beneficiaries
                ? [
                    ['label' => 'Prepared by:', 'name' => $typed($prefix.'_ben_prepared_name', SchoolSignatories::feedingCoordinator($institutionId, $schoolName)), 'role' => 'Feeding Coordinator'],
                    ['label' => 'Attested by:', 'name' => $typed($prefix.'_ben_attested_name', $nurse), 'role' => 'School Clinic Nurse/Teacher'],
                    ['label' => 'Noted by:', 'name' => $typed($prefix.'_ben_noted_name', SchoolSignatories::notedBy($institutionId, $schoolName)), 'role' => SchoolSignatories::HEAD_DESIGNATION],
                ]
                : [
                    ['label' => 'Prepared by:', 'name' => $typed($prefix.'_prepared_name', $nurse), 'role' => $typed($prefix.'_prepared_role', '') ?: 'School Clinic Nurse / Teacher'],
                    ['label' => 'Attested by:', 'name' => $typed($prefix.'_attested_name', ''), 'role' => $typed($prefix.'_attested_role', '') ?: 'MAPEH Department Head'],
                    ['label' => 'Noted by:', 'name' => $typed($prefix.'_noted_name', ''), 'role' => $typed($prefix.'_noted_role', '') ?: SchoolSignatories::HEAD_DESIGNATION],
                ];

            SbfpFormWorkbook::bmiReport(
                $writer,
                sheetName: $weighing === 'baseline' ? 'Baseline BMI' : 'Final BMI',
                letterhead: ['address' => $typed($prefix.'_school_address', $letterhead['address'])] + $letterhead,
                phase: $weighing,
                values: $values,
                gridKeys: $gridKeys,
                schoolYear: $typed($prefix.'_school_year', $schoolYear),
                scope: $scope,
                insight: $insightSet[$weighing][$gradeKey ?? 'overall'] ?? [],
                insightScope: $populationLabel !== '' ? $populationLabel.' · '.$insightWhere : $insightWhere,
                signatories: $signatories,
            );
        }
    }

    /**
     * The learners a master list names in the scope the page shows: the
     * server's own `lists` decision, every grade in grade order when none is
     * chosen, each grade's learners in name order — the page's learnersFor().
     *
     * @param  array<string, list<array<string, mixed>>>  $studentsByGrade
     * @return list<array{name: string, grade: string, section: string}>
     */
    private function masterlistRows(array $studentsByGrade, string $template, string $grade, string $section): array
    {
        $grades = $grade !== '' ? [$grade] : array_keys($studentsByGrade);

        $rows = [];
        foreach ($grades as $gradeLabel) {
            foreach ($studentsByGrade[$gradeLabel] ?? [] as $student) {
                if (! in_array($template, $student['lists'] ?? [], true)) {
                    continue;
                }
                if ($section !== '' && $student['section'] !== $section) {
                    continue;
                }
                $rows[] = [
                    'name' => (string) $student['name'],
                    'grade' => (string) $student['grade'],
                    'section' => (string) $student['section'],
                    'adviser' => (string) ($student['adviser'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /** "Grade 7 · All Sections" — the page's scope line, before CSS upper-cases it. */
    private function formScopeLabel(string $grade, string $section): string
    {
        return ($grade !== '' ? $grade : 'All Grade Levels').' · '.($section !== '' ? $section : 'All Sections');
    }

    /**
     * The words typed on the form around its tables — the school address and
     * year, the names and designations on the signature lines, the narrative's
     * paragraphs — as posted. Only the form's own field names are kept, each
     * trimmed and capped, and none of them is ever stored: they are written
     * into the file and gone.
     *
     * @return array<string, string>
     */
    private function typedFields(Request $request): array
    {
        $posted = $request->input('fields', []);
        if (! is_array($posted)) {
            return [];
        }

        $fields = [];
        foreach ($posted as $key => $value) {
            if (count($fields) >= 60 || ! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            if (! preg_match('/^(bmib|bmif|ml|mlb|narr)_[a-z_]{1,40}$/', $key)) {
                continue;
            }

            // A narrative section is a few paragraphs; everything else is a line.
            $limit = str_starts_with($key, 'narr_') && ! in_array($key, ['narr_funder', 'narr_prepared_name', 'narr_noted_name', 'narr_noted_role', 'narr_school_address'], true)
                ? 20000
                : 200;
            $fields[$key] = mb_substr(trim((string) $value), 0, $limit);
        }

        return $fields;
    }

    /**
     * Reads a typed field the way the page shows it: a field the page sent is
     * what was on the form, even left blank (a blank line to sign on); one it
     * did not send at all falls back to what the page would have prefilled.
     *
     * @param  array<string, string>  $fields
     * @return \Closure(string, string): string
     */
    private function typedReader(array $fields): \Closure
    {
        return static fn (string $key, string $default): string => array_key_exists($key, $fields) ? $fields[$key] : $default;
    }

    /**
     * Download PDF: the coordinator's narrative report, as the form on screen.
     *
     * The paragraphs are the coordinator's own — drafted from the figures,
     * then edited in the browser — so they arrive with the request and are
     * written straight into the document, never stored. Coordinator only: the
     * clinic's copy of the page carries no narrative report.
     */
    public function downloadNarrative(Request $request): Response|RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can download the narrative report.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $letterhead = SchoolLetterhead::for($institutionId, (string) $request->session()->get('active_school_name', ''));
        $typed = $this->typedReader($this->typedFields($request));
        $schoolYear = StudentHealthRecord::currentSchoolYear();

        $pdf = NarrativeReportPdf::render([
            'letterhead' => ['address' => $typed('narr_school_address', $letterhead['address'])] + $letterhead,
            'funder' => $typed('narr_funder', ''),
            'sections' => array_map(
                static fn (string $key): string => $typed('narr_'.$key, ''),
                NarrativeReportPdf::SECTIONS
            ),
            'preparedName' => $typed('narr_prepared_name', ''),
            'notedName' => $typed('narr_noted_name', ''),
            'notedRole' => $typed('narr_noted_role', '') ?: SchoolSignatories::HEAD_DESIGNATION,
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.SbfpFormWorkbook::filename('SBFP Narrative Report', $schoolYear, 'pdf').'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The BMI report for each population its Learners control offers: the grid
     * and the insight under it, computed here so the page only swaps between
     * them. Every field behind them is encrypted, so the counting has to happen
     * in PHP whichever population is on screen.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array{all: array{values: array<string, array<string, int|string>>, insights: array<string, mixed>}, beneficiaries: array{values: array<string, array<string, int|string>>, insights: array<string, mixed>}}
     */
    private function bmiReports(Collection $records): array
    {
        $all = $this->bmiValueSetsBySection($records);
        $beneficiaries = $this->bmiValueSetsBySection($records, onlyBeneficiaries: true);

        return [
            'all' => ['values' => $all, 'insights' => BmiReportInsight::forValueSets($all)],
            'beneficiaries' => [
                'values' => $beneficiaries,
                'insights' => BmiReportInsight::forValueSets($beneficiaries, BmiReportInsight::BENEFICIARY),
            ],
        ];
    }

    /**
     * The BMI grid computed once per section, keyed by the section string the
     * roster stores ("Grade 7 / Matiyaga"), with '' holding the whole school.
     *
     * The sections are read off the whole roll even when only beneficiaries are
     * counted. A section with none still gets its own empty grid: the page reads
     * the whole school when a section has no set, so leaving it out would print
     * every beneficiary in the school under that one section's name.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array<string, array<string, int|string>>
     */
    private function bmiValueSetsBySection(Collection $records, bool $onlyBeneficiaries = false): array
    {
        $counted = fn (Collection $group): Collection => $onlyBeneficiaries
            ? $group->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
            : $group;

        $sets = ['' => BmiAssessmentReport::values($counted($records))];

        foreach ($records->groupBy(fn (StudentHealthRecord $record): string => trim((string) $record->section)) as $key => $group) {
            // A learner with no section on file has no section grid to sit in.
            if ((string) $key === '') {
                continue;
            }

            $sets[(string) $key] = BmiAssessmentReport::values($counted($group));
        }

        return $sets;
    }

    /**
     * The nurse whose name pre-fills the form's Prepared-by line. Read through
     * SchoolSignatories so the printed form and the School Head's exported one
     * are signed by the same person.
     */
    private function resolveSchoolNurseName(?int $institutionId, string $schoolName): string
    {
        return SchoolSignatories::preparedBy($institutionId, $schoolName);
    }

    /**
     * Splits the plain "Grade X / Section" string into [grade, section].
     * Rows with no section land under "Unassigned" so they stay selectable.
     */
    private function splitSection(string $section): array
    {
        $parts = explode(' / ', $section, 2);
        $grade = trim($parts[0]);
        $sectionName = trim($parts[1] ?? '');

        return [$grade !== '' ? $grade : 'Unassigned', $sectionName];
    }

    /**
     * This lives in FeedingBeneficiarySummary, which the Beneficiaries tab
     * reads too. One definition, or the two tabs would eventually disagree
     * about who the programme feeds.
     */
    private function normalizeStatus(string $status): string
    {
        return FeedingBeneficiarySummary::normalize($status);
    }

    public function dashboard(Request $request): View|RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can open this dashboard.');
        }

        $institutionId = $request->session()->get('active_institution_id');

        return view(
            'feedingcor-dashboard.feed-dashboard',
            $this->buildDashboardMetrics($institutionId, $this->readFilters($request))
                + ['stamp' => $this->metricsStamp($institutionId)]
                // "Record Today's Attendance" opens the dialog here rather than
                // sending the coordinator to another tab to press a second
                // button. It is built on the first paint only, never on the
                // 20-second pulse: it holds marks nobody has saved yet, so
                // re-rendering it under the coordinator would be worse than
                // being a few seconds stale.
                + $this->buildRecordSession($institutionId)
        );
    }

    /**
     * Today's Record Attendance dialog, for the Dashboard.
     *
     * The same payload the Attendance tab's `build()` hands the shared partial,
     * for the same dialog and the same endpoint — the Dashboard renders it so
     * "Record Today's Attendance" records today's attendance, instead of
     * landing on a tab where the coordinator has to find the button again.
     *
     * Three rules it inherits rather than re-decides:
     *
     * - **The roll is the whole enrolled roll, unfiltered.** Recording through
     *   the page's grade/section/gender filter would leave the learners the
     *   filter was hiding unmarked.
     * - **Today's session is open all day, recorded or not**
     *   (FeedingAttendanceWindow, on the school's clock). Once it has been
     *   recorded the dialog opens on the marks on file and saving changes them;
     *   when the day ends it closes, and `storeRecordedAttendance` refuses the
     *   write regardless, because a read-only screen whose endpoint still
     *   accepts writes is a suggestion. An *unconfirmed* scanned mark records
     *   nothing: nobody has read it, and recording on site is exactly how it
     *   gets decided.
     * - **A weekend is not a feeding day**, and neither is a date outside the
     *   running cycle.
     *
     * @return array<string, mixed>
     */
    private function buildRecordSession(?int $institutionId): array
    {
        // The school's date, not the server's: before 8 AM in Davao the server
        // is still on yesterday.
        $today = FeedingAttendanceWindow::today();
        $cycle = FeedingProgramCycle::forInstitution($institutionId);

        // The enrolled roll for the year being recorded — always the current
        // one, whatever year the page's filter is reading, because the mark
        // being written is today's.
        $beneficiaries = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $beneficiaries = StudentHealthRecord::rosterFor($institutionId)
                ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
                // student_name is encrypted at rest, so the sort is in PHP.
                ->sortBy(fn (StudentHealthRecord $record): string => strtolower((string) $record->student_name))
                ->values();
        }

        $marks = $this->marksForSession($beneficiaries, $today);

        $rows = $beneficiaries->map(function (StudentHealthRecord $record) use ($marks): array {
            [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);
            $mark = $marks->get($record->id);

            return [
                'id' => $record->id,
                'name' => (string) $record->student_name,
                // Both spellings, because the shared partial reads both: the
                // full "Grade 7" into the row's search key, the bare number
                // into the column.
                'grade' => $grade,
                'grade_number' => preg_replace('/^grade\s*/i', '', $grade),
                'section' => $section,
                'status' => $mark === null ? 'unmarked' : FeedingAttendanceMark::state($mark),
                // The reason recorded with a mark, for a closed session the
                // dialog reports rather than writes.
                'remarks' => trim((string) ($mark->remarks ?? '')),
            ];
        })->values();

        // A mark a human has already confirmed makes today's save an edit. An
        // unconfirmed scanned one deliberately does not.
        $recorded = $rows->contains(
            fn (array $row): bool => in_array($row['status'], FeedingAttendanceMark::RECORDABLE, true)
        );

        $start = $cycle->startDateIso();
        $end = $cycle->endDateIso() ?? $today;
        $withinWindow = $start === null || ($today >= $start && $today <= $end);

        $isFeedingDay = FeedingProgramCycle::isFeedingDay($today, $institutionId);
        $canRecord = $isFeedingDay && $withinWindow && $beneficiaries->isNotEmpty();

        return [
            'recordSelectedDate' => $today,
            'recordSelectedDateLabel' => Carbon::parse($today)->format('F j, Y'),
            'recordRows' => $rows->all(),
            'recordBeneficiaryCount' => $beneficiaries->count(),
            'canRecordToday' => $canRecord,
            // Already recorded today: the button and the dialog say "Edit", and
            // the marks on file come back selected.
            'recordEditing' => $canRecord && $recorded,
            // Why the day is closed, in the words the coordinator needs. The
            // button is drawn either way and the dialog opens either way: on a
            // closed day it opens on the session as a record rather than as a
            // form, which is a different answer from a control that quietly
            // disappears out from under the reader.
            'recordBlockedReason' => match (true) {
                $canRecord => '',
                $beneficiaries->isEmpty() => 'No beneficiary is enrolled for this school year yet.',
                ! $isFeedingDay => (string) SchoolCalendar::reason($today, $institutionId),
                default => 'Today is outside the running feeding programme.',
            },
        ];
    }

    /**
     * The live panels, re-read on demand. The first paint and every refresh run
     * through buildDashboardMetrics and render the same Blade partials, so the
     * screen a coordinator watches can never drift from a reloaded one.
     */
    public function metrics(Request $request): JsonResponse
    {
        if (! $this->isCoordinator($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $institutionId = $request->session()->get('active_institution_id');
        // The page forwards its own query string, so a refresh re-renders the
        // filtered view the coordinator is looking at, not the unfiltered one.
        $metrics = $this->buildDashboardMetrics($institutionId, $this->readFilters($request));

        return response()->json([
            'stamp' => $this->metricsStamp($institutionId),
            'generatedAt' => $metrics['generatedAt'],
            'html' => [
                'cards' => view('feedingcor-dashboard.partials.kpi-cards', $metrics)->render(),
                'attendance' => view('feedingcor-dashboard.partials.attendance-monitoring', $metrics)->render(),
                'nutrition' => view('feedingcor-dashboard.partials.nutrition-status', $metrics)->render(),
                'risk' => view('feedingcor-dashboard.partials.attendance-risk', $metrics)->render(),
                'progress' => view('feedingcor-dashboard.partials.nutrition-progress', $metrics)->render(),
            ],
        ]);
    }

    /**
     * The Beneficiaries tab's headline cards, re-read on demand.
     *
     * It renders the same Blade partial the first paint used and reads the same
     * FeedingBeneficiarySummary, so a live card can never drift from a reloaded
     * one — and it forwards the tab's own grade/section filter, so a refresh
     * redraws the filtered view rather than replacing it with the whole school.
     */
    public function beneficiaryCards(Request $request): JsonResponse
    {
        if (! $this->isCoordinator($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $institutionId = $request->session()->get('active_institution_id');

        // Only the scope filters reach the cards — the same three the page
        // scopes them by, so a live refresh redraws the view on screen.
        $summary = FeedingBeneficiarySummary::forInstitution($institutionId, [
            'school_year' => trim((string) $request->query('school_year', '')) ?: null,
            'grade' => trim((string) $request->query('grade_level', '')),
            'section' => trim((string) $request->query('section', '')),
        ]);

        // The tabs read the very figures the cards do, so "All beneficiaries"
        // and "For Follow-up" can never disagree with the cards above them.
        $segmentCounts = [
            'all' => $summary['beneficiaries'],
            'pending' => $summary['pending'],
            'at_risk' => $summary['at_risk'],
        ];

        return response()->json([
            'stamp' => $this->metricsStamp($institutionId),
            'html' => [
                'cards' => view('feedingcor-dashboard.partials.beneficiary-cards', [
                    'beneficiarySummary' => $summary,
                ])->render(),
                'tabs' => view('feedingcor-dashboard.partials.beneficiary-tabs', [
                    'segmentCounts' => $segmentCounts,
                    'segmentView' => in_array($request->query('view'), ['all', 'pending', 'at_risk'], true)
                        ? (string) $request->query('view')
                        : 'all',
                ])->render(),
            ],
        ]);
    }

    /**
     * Change-detection only: a hash of row counts and last-touched timestamps.
     * It carries no personal information, so the dashboard can poll it on a
     * timer and pay for the (much heavier) rebuild only when it moves.
     */
    public function pulse(Request $request): JsonResponse
    {
        if (! $this->isCoordinator($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['stamp' => $this->metricsStamp($request->session()->get('active_institution_id'))]);
    }

    /**
     * The filters the dashboard reads off the query string. Only the school
     * year touches the database; grade, section, sex and attendance status are
     * applied in PHP, because the columns they read are encrypted at rest.
     *
     * @return array{school_year: string, grade: string, section: string, sex: string, attendance: string}
     */
    private function readFilters(Request $request): array
    {
        $clean = fn (string $key): string => trim((string) $request->query($key, ''));

        return [
            'school_year' => $clean('school_year') ?: StudentHealthRecord::currentSchoolYear(),
            'grade' => $clean('grade'),
            'section' => $clean('section'),
            // Sex is scope, like grade and section: it narrows who the figures
            // describe rather than which of them are listed. An unrecognised
            // value is dropped rather than emptying the page.
            'sex' => in_array($clean('sex'), FeedingBeneficiarySummary::SEX_OPTIONS, true) ? $clean('sex') : '',
            'attendance' => in_array($clean('attendance'), ['present', 'absent', 'excused', 'unmarked'], true)
                ? $clean('attendance')
                : '',
            // Which population the Nutritional Status panel reports on. It moves
            // that panel alone — the cards above it are the programme's figures
            // and must not change because somebody widened one breakdown.
            'population' => in_array(
                $clean('population'),
                array_column(self::populationOptions(), 'value'),
                true
            ) ? $clean('population') : self::POPULATION_BENEFICIARIES,
        ];
    }

    /**
     * @param  array{school_year: string, grade: string, section: string, sex: string, attendance: string}|null  $filters
     * @return array<string, mixed>
     */
    private function buildDashboardMetrics(?int $institutionId, ?array $filters = null): array
    {
        $filters ??= [
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'grade' => '',
            'section' => '',
            'sex' => '',
            'attendance' => '',
            'population' => self::POPULATION_BENEFICIARIES,
        ];
        $filters['population'] ??= self::POPULATION_BENEFICIARIES;

        $students = collect();
        $hasRecords = SchemaCache::hasTable('student_health_records');

        // school_year is a plain lookup column, so the year list and the year
        // filter are the only parts of this that SQL may decide. The list is
        // the years on record and the current one (SchoolYear::options), and a
        // year off the query string it does not offer — one nobody has a
        // record in, a future one included — reads the current year instead.
        $schoolYears = collect(SchoolYear::options($hasRecords
            ? StudentHealthRecord::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->distinct()
                ->pluck('school_year')
            : []));
        $filters['school_year'] = SchoolYear::pick($filters['school_year'], $schoolYears->all());

        if ($hasRecords) {
            // Shared with the Record Attendance dialog below, which wants this
            // same roll: on the usual path (the filter naming the current year)
            // the two are one read rather than two.
            $students = StudentHealthRecord::rosterFor($institutionId, $filters['school_year']);
        }

        // The feeding cycle the header counts down comes from the school's first
        // recorded session, so this page and the Feeding Program page never
        // disagree about which day it is.
        $cycle = FeedingProgramCycle::forInstitution($institutionId);
        $programDay = $cycle->day();

        // nutritional_status is encrypted at rest and the grade lives inside
        // the section string, so both of these are decided in PHP after fetch.
        //
        // Qualifying and being enrolled are two different facts: the adviser's
        // measurement decides the first, the coordinator's decision the second.
        // Only enrolled learners are beneficiaries; those who qualify today and
        // hold no place are waiting.
        $allBeneficiaries = $students
            ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
            ->values();
        $awaitingEnrollment = $students
            ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isEligible($record)
                && ! FeedingBeneficiarySummary::isEnrolled($record))
            ->count();

        // Grade, section and sex scope every panel; the two status filters
        // narrow the attendance roll alone (see buildTodayAttendance), so the
        // headline stays "of everyone expected today" rather than "of what is
        // on screen". Sex is read through FeedingBeneficiarySummary so this
        // page and the Beneficiaries tab agree on who is male and who female.
        $beneficiaries = FeedingBeneficiarySummary::scopeToSex(
            $allBeneficiaries->filter(function (StudentHealthRecord $record) use ($filters): bool {
                [$grade, $section] = $this->splitSection((string) $record->section);

                return ($filters['grade'] === '' || $grade === $filters['grade'])
                    && ($filters['section'] === '' || $section === $filters['section']);
            })->values(),
            (string) ($filters['sex'] ?? '')
        );

        // "All Students" for the Nutritional Status panel: every learner in a
        // grade the programme covers, whatever their status and whether or not
        // anyone enrolled them — which is where a Normal or Obese learner is
        // counted, since they are never beneficiaries but are still the school's
        // children. Scoped by the same grade/section/sex the panels use, so the
        // two populations answer the same question about the same class.
        $allStudentsInScope = FeedingBeneficiarySummary::scopeToSex(
            $students->filter(function (StudentHealthRecord $record) use ($filters): bool {
                if (! FeedingBeneficiarySummary::coversGrade($record)) {
                    return false;
                }

                [$grade, $section] = $this->splitSection((string) $record->section);

                return ($filters['grade'] === '' || $grade === $filters['grade'])
                    && ($filters['section'] === '' || $section === $filters['section']);
            })->values(),
            (string) ($filters['sex'] ?? '')
        );

        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        $beneficiaryStats = $this->buildBeneficiaryStats($beneficiaries, $rule);

        // Progress weigh-ins (testers' report #33): beneficiaries no better
        // than at baseline at their latest weighing, to act on before the
        // programme ends. Read once, and only while the school records them.
        $weighInsOn = FeedingWeighIns::enabled($institutionId ? (int) $institutionId : null);
        $weighIns = $weighInsOn ? FeedingWeighIns::forRecords($beneficiaries) : collect();
        $notImproving = $weighInsOn
            ? $beneficiaries
                ->filter(fn (StudentHealthRecord $record): bool => FeedingWeighIns::progress($record, $weighIns->get($record->id, collect())) === FeedingWeighIns::NOT_IMPROVING)
                ->map(fn (StudentHealthRecord $record): array => [
                    'id' => $record->id,
                    'name' => (string) $record->student_name,
                    'class' => implode(' ', array_filter($this->splitSection((string) $record->section))),
                    'url' => route('feedingcor-program.beneficiary', $record->id),
                ])
                ->sortBy(fn (array $row): string => strtolower($row['name']))
                ->values()
                ->all()
            : [];

        return [
            'weighInsOn' => $weighInsOn,
            'notImproving' => $notImproving,
            'filters' => $filters,
            'filterOptions' => $this->buildFilterOptions($allBeneficiaries, $schoolYears, $filters),
            'dashboardStats' => [
                'total_students' => $students->count(),
                'program_day' => $programDay,
                'beneficiaries' => $beneficiaryStats['beneficiaries'],
                'beneficiaries_grade_range' => $beneficiaryStats['grade_range'],
                'attendance_rate' => $beneficiaryStats['attendance_rate'],
                'attendance_sessions' => $beneficiaryStats['confirmed_sessions'],
                'at_risk' => $beneficiaryStats['at_risk'],
                'at_risk_rule' => $beneficiaryStats['at_risk_rule'],
                // Qualified but not yet enrolled — the modal's waiting list,
                // counted school-wide rather than within the grade filter so
                // the card and the modal never report different numbers.
                'awaiting_enrollment' => $awaitingEnrollment,
            ],
            'programCycle' => [
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'day' => $programDay,
                'duration' => $cycle->durationDays(),
                'days_remaining' => $cycle->daysRemaining(),
                'percent' => $cycle->percent(),
                'started' => $cycle->hasStarted(),
                'start_date' => $cycle->startDateIso(),
                // The date of the last feeding day, counted in school days,
                // so the header can say when the cycle ends.
                'end_date' => $cycle->endDateIso(),
                // The closed weekdays, so the bar's own midnight recount takes
                // a holiday off exactly as countFeedingDays() does.
                'closed_dates' => array_keys(SchoolCalendar::pickerDates($institutionId)),
            ],
            'nutritionStatus' => $this->buildNutritionStatus(
                $filters['population'] === self::POPULATION_ALL_STUDENTS
                    ? $allStudentsInScope
                    : $beneficiaries,
                (string) $filters['population']
            ),
            'todayAttendance' => $this->buildTodayAttendance($beneficiaries, $filters),
            'attendanceRisk' => $this->buildAttendanceRisk($beneficiaries, $rule),
            'nutritionProgress' => FeedingNutritionProgress::build(
                $beneficiaries,
                fn (StudentHealthRecord $record): string => $this->panelStatus($record),
                fn (StudentHealthRecord $record): string => $this->endlineStatus($record),
            ),
            'generatedAt' => now()->format('g:i A'),
        ];
    }

    /**
     * Options for the five coordinated filters. Grades and sections are read
     * off the plain "section" column of the learners actually on file, and the
     * section list narrows to the chosen grade — picking Grade 7 must not leave
     * Grade 8's sections selectable.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  Collection<int, string>  $schoolYears
     * @param  array<string, string>  $filters
     * @return array<string, list<array<string, string>>|list<string>>
     */
    private function buildFilterOptions(Collection $beneficiaries, Collection $schoolYears, array $filters): array
    {
        $pairs = $beneficiaries
            ->map(fn (StudentHealthRecord $record): array => $this->splitSection((string) $record->section))
            ->values();

        $grades = $pairs->pluck(0)->filter()->unique()->sort(SORT_NATURAL)->values();
        $sections = $pairs
            ->filter(fn (array $pair): bool => $filters['grade'] === '' || $pair[0] === $filters['grade'])
            ->pluck(1)
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL)
            ->values();

        return [
            'school_years' => $schoolYears->all(),
            'grades' => $grades->all(),
            'sections' => $sections->all(),
            // The two answers the app records — never a list built from the
            // data, which would offer an option only if someone had already
            // been filed under it.
            'sexes' => FeedingBeneficiarySummary::SEX_OPTIONS,
            'populations' => self::populationOptions(),
            'attendance' => [
                ['value' => 'present', 'label' => 'Present'],
                ['value' => 'absent', 'label' => 'Absent'],
                ['value' => 'excused', 'label' => 'Excused'],
                ['value' => 'unmarked', 'label' => FeedingAttendanceMark::NOT_RECORDED],
            ],
        ];
    }

    /**
     * The nutritional-status scale the dashboard reports on.
     *
     * Underweight and Overweight are deliberately absent: "Underweight" is this
     * app's non-standard label for a learner the DepEd sheet counts as Wasted,
     * so panelStatus() folds it there exactly as the BMI reports do, and no
     * Overweight learner is ever a feeding beneficiary. Severely Wasted and
     * Wasted decide eligibility, so they lead and carry the loudest badges.
     *
     * @return list<array{label: string, badge: string, eligible: bool}>
     */
    private function nutritionScale(): array
    {
        return [
            ['label' => 'Severely Wasted', 'badge' => 'badge-critical', 'eligible' => true],
            ['label' => 'Wasted', 'badge' => 'badge-risk', 'eligible' => true],
            ['label' => 'Normal', 'badge' => 'badge-normal', 'eligible' => false],
            ['label' => 'Obese', 'badge' => 'badge-monitor', 'eligible' => false],
            // Only ever non-zero for All Students: a beneficiary qualified on a
            // measurement, so one cannot be unmeasured. It exists because a
            // learner nobody weighed must not be reported as Normal — "412
            // Normal" that quietly includes 60 children no one has looked at is
            // the one reading this panel must never produce.
            ['label' => self::STATUS_NOT_MEASURED, 'badge' => 'badge-neutral', 'eligible' => false],
        ];
    }

    /**
     * The two populations the Nutritional Status panel can report.
     *
     * Beneficiaries is the programme (qualified AND enrolled). All Students is
     * every learner in a covered grade, whatever their status — which is where a
     * Normal or Obese learner shows up, since they are never beneficiaries but
     * are still the school's children and still counted.
     */
    public const POPULATION_BENEFICIARIES = 'beneficiaries';

    public const POPULATION_ALL_STUDENTS = 'all_students';

    /** A learner nobody has weighed. Not a status — the absence of one. */
    public const STATUS_NOT_MEASURED = 'Not measured';

    /** @return list<array{value: string, label: string}> */
    public static function populationOptions(): array
    {
        return [
            ['value' => self::POPULATION_BENEFICIARIES, 'label' => 'Beneficiaries'],
            ['value' => self::POPULATION_ALL_STUDENTS, 'label' => 'All Students'],
        ];
    }

    /**
     * The chosen population broken down by nutritional status, at both
     * weighings.
     *
     * The counts always sum to the total above them, so the panel and the
     * headline card can never tell two different stories. Rows are listed even
     * where the count is zero: a coordinator reading "Obese 0" learns something,
     * a missing row only looks like a bug.
     *
     * Two readings, side by side and never merged. **Baseline** is what the
     * learner was measured at; **Endline** is the closing measurement, and it
     * counts only learners who have actually had one — an unmeasured learner is
     * not "unchanged", so the endline column is deliberately allowed to sum to
     * less than the total, and says how many are still to be measured rather
     * than filing them under a status nobody recorded.
     *
     * @param  Collection<int, StudentHealthRecord>  $population
     * @return array<string, mixed>
     */
    private function buildNutritionStatus(Collection $population, string $populationKey): array
    {
        $baseline = [];
        $endline = [];
        $endlineMeasured = 0;

        foreach ($population as $record) {
            $status = $this->panelStatusOrUnmeasured($record);
            $baseline[$status] = ($baseline[$status] ?? 0) + 1;

            $closing = $this->endlineStatus($record);
            if ($closing !== '') {
                $endline[$closing] = ($endline[$closing] ?? 0) + 1;
                $endlineMeasured++;
            }
        }

        $label = collect(self::populationOptions())->firstWhere('value', $populationKey)['label']
            ?? 'Beneficiaries';

        return [
            'total' => $population->count(),
            'population' => $populationKey,
            'population_label' => $label,
            'total_label' => $populationKey === self::POPULATION_ALL_STUDENTS
                ? 'Total Students'
                : 'Total Beneficiaries',
            'endline_measured' => $endlineMeasured,
            'endline_pending' => $population->count() - $endlineMeasured,
            'rows' => array_map(
                fn (array $row): array => $row + [
                    'count' => (int) ($baseline[$row['label']] ?? 0),
                    'endline' => (int) ($endline[$row['label']] ?? 0),
                ],
                $this->nutritionScale()
            ),
        ];
    }

    /**
     * A learner's baseline status, or "Not measured" when there is no reading.
     *
     * `FeedingBeneficiarySummary::statusOf()` folds an absent reading into
     * Normal so its four cards sum to the beneficiary total, which is right for
     * the programme — every beneficiary was measured. It is wrong for All
     * Students, where reporting the unweighed as Normal turns children nobody
     * has looked at into good news.
     */
    private function panelStatusOrUnmeasured(StudentHealthRecord $record): string
    {
        $reading = trim((string) ($record->baseline_nutritional_status ?: $record->nutritional_status));

        return $reading === '' ? self::STATUS_NOT_MEASURED : $this->panelStatus($record);
    }

    /**
     * One learner's status as the dashboard reports it.
     *
     * Baseline is what the programme enrolled them on; a learner with no
     * baseline on file is counted under their current status instead. The
     * app's "Underweight" is folded into Wasted so the breakdown always sums
     * to the beneficiary total — see nutritionScale().
     */
    private function panelStatus(StudentHealthRecord $record): string
    {
        // Every status lands on exactly one row of nutritionScale(), including
        // ones the scale does not name — a learner who fell through would make
        // the breakdown stop summing to the beneficiary total. The folding is
        // shared with the Beneficiaries tab so both report the same statuses.
        return FeedingBeneficiarySummary::statusOf($record);
    }

    /**
     * Today's feeding session, learner by learner.
     *
     * A learner is Present or Absent once the session is recorded, and carries
     * the coordinator's remark for why they were away. Two states remain that
     * are neither: a scanned mark no human has read, and a learner today's
     * sheet has not covered at all. Both render as "—" rather than an absence,
     * because reporting them as absent would claim an absence nobody witnessed.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    private function buildTodayAttendance(Collection $beneficiaries, array $filters): array
    {
        // The same "today" the Record Attendance dialog beside it writes to.
        $today = FeedingAttendanceWindow::today();
        $marks = $this->marksForSession($beneficiaries, $today);

        $counts = ['present' => 0, 'absent' => 0, 'excused' => 0, 'unconfirmed' => 0, 'unrecorded' => 0];

        // student_name is encrypted at rest, so sorting happens in PHP.
        $rows = $beneficiaries
            ->map(function (StudentHealthRecord $record) use ($marks, &$counts): array {
                $mark = $marks->get($record->id);
                [$grade, $section] = $this->splitSection((string) $record->section);

                // "unrecorded" rather than the mark class's "not_marked": this
                // panel has said unrecorded since it shipped, and the two words
                // mean the same thing to the same reader.
                $status = $mark === null
                    ? 'unrecorded'
                    : FeedingAttendanceMark::state($mark);
                $counts[$status]++;

                return [
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'section' => $section,
                    'status' => $status,
                    'remarks' => trim((string) ($mark->remarks ?? '')),
                ];
            })
            ->sortBy(fn (array $row) => strtolower($row['name']))
            ->values();

        $expected = $beneficiaries->count();

        // The headline counts everyone expected today; the attendance filter
        // narrows only which of them the table draws.
        $visible = $rows
            ->when($filters['attendance'] !== '', fn ($rows) => $rows->filter(
                fn (array $row): bool => $filters['attendance'] === 'unmarked'
                    ? in_array($row['status'], ['unconfirmed', 'unrecorded'], true)
                    : $row['status'] === $filters['attendance']
            ))
            ->values()
            ->all();

        return [
            'date_label' => now()->format('M d, Y'),
            'expected' => $expected,
            'present' => $counts['present'],
            'absent' => $counts['absent'],
            // The buffer, reported apart from the plain absences: the school
            // accepted these, and the at-risk rule never counts them.
            'excused' => $counts['excused'],
            'unconfirmed' => $counts['unconfirmed'],
            'unrecorded' => $counts['unrecorded'],
            // Share of the expected headcount confirmed present today. Zero of
            // them is still a count, so the figure reads the same before and
            // after the session is recorded; the chips say which it is.
            'percent' => $expected > 0
                ? round(($counts['present'] / $expected) * 100, 1)
                : 0.0,
            'recorded' => $counts['unrecorded'] < $expected,
            'filtered' => $filters['attendance'] !== '',
            'rows' => $visible,
        ];
    }

    /**
     * A fingerprint of the tables the dashboard reads — row counts and
     * last-touched timestamps only, never a column holding personal data, so
     * the polling endpoint stays free of it.
     */
    /**
     * One session's marks for a roll of learners, keyed by record id.
     *
     * The dashboard asks this twice - once to build the Record Attendance
     * dialog over the whole enrolled roll, once for the Attendance Monitoring
     * panel over the filtered one - and with no filter set those are the same
     * question. Memoized on the session and the roll, so the identical ask is
     * one read and a different roll is still its own.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return Collection<int, FeedingAttendance>
     */
    private function marksForSession(Collection $beneficiaries, string $sessionDate): Collection
    {
        if ($beneficiaries->isEmpty() || ! SchemaCache::hasTable('feeding_attendances')) {
            return collect();
        }

        $ids = $beneficiaries->pluck('id')->filter()->sort()->values();

        return RequestMemo::remember(
            'feeding:session-marks:'.$sessionDate.':'.md5($ids->implode(',')),
            function () use ($ids, $sessionDate): Collection {
                $columns = ['student_health_record_id', 'is_present'];

                foreach (['needs_review', 'is_excused', 'remarks'] as $column) {
                    if (SchemaCache::hasColumn('feeding_attendances', $column)) {
                        $columns[] = $column;
                    }
                }

                return FeedingAttendance::query()
                    ->whereIn('student_health_record_id', $ids)
                    ->whereDate('session_date', $sessionDate)
                    ->get($columns)
                    ->keyBy('student_health_record_id');
            },
        );
    }

    private function metricsStamp(?int $institutionId): string
    {
        // feeding_attendances inherits its school scope from the parent record,
        // so only the owning table filters. A neighbouring school's write can
        // cost one needless refetch - never a missed change, and nothing of
        // theirs is ever read. Both counts come back in one round trip.
        return ChangeStamp::forTables(
            // A confirmation or a resolved follow-up moves a flag without
            // touching a mark, so both are watched too.
            ['student_health_records', 'feeding_attendances', 'feeding_attendance_confirmations', 'feeding_follow_ups'],
            $institutionId,
            // The school's date, so the panels roll over at the school's
            // midnight — when today's session closes.
            [FeedingAttendanceWindow::today()],
        );
    }

    private function isCoordinator(Request $request): bool
    {
        return $request->session()->get('active_role') === 'feeding_coor';
    }

    /**
     * The headline figures for the enrolled roll: how many there are, how well
     * they are showing up, and who the attendance rule has flagged.
     *
     * Attendance is counted over confirmed marks only. A scanned mark nobody
     * has reviewed is NULL and votes neither way, so it can neither depress the
     * rate nor flag a learner — the same rule FeedingAtRiskRule applies. Never
     * aggregate this in SQL: a SUM(CASE WHEN is_present ...) folds NULL into
     * "absent" and quietly breaks that invariant.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array{beneficiaries: int, jhs: int, shs: int, attendance_rate: ?int, confirmed_sessions: int, at_risk: int, at_risk_rule: string}
     */
    private function buildBeneficiaryStats(Collection $beneficiaries, FeedingAtRiskRule $rule): array
    {
        $marksByRecord = $this->marksByRecord($beneficiaries);
        // Flagged, never "threshold reached": a learner counts at risk only
        // once the coordinator has confirmed their record.
        $flags = FeedingAttendanceFlag::forRecords($beneficiaries, $rule);

        $confirmedSessions = 0;
        $presentSessions = 0;
        $atRisk = 0;

        foreach ($beneficiaries as $record) {
            // A learner no session has covered yet contributes nothing and is
            // flagged by nothing — the rule already reads no marks as no
            // evidence, so there is no special case to write here.
            $marks = $marksByRecord->get($record->id, []);
            $confirmed = array_filter($marks, static fn ($mark) => $mark !== null);
            $confirmedSessions += count($confirmed);
            $presentSessions += $rule->presentCount($marks);

            // Read live rather than off the stored is_at_risk column, so a
            // confirmation shows on the dashboard at once.
            if ($flags[$record->id]['flagged'] ?? false) {
                $atRisk++;
            }
        }

        return [
            'beneficiaries' => $beneficiaries->count(),
            // The grades the roll actually spans, from the programme's own
            // list. There is no JHS/SHS split to report any more: Senior High
            // is out of scope, so the old "SHS 0" said nothing.
            'grade_range' => self::gradeRangeLabel(),
            // Null, not zero: no confirmed session is not a 0% turnout.
            'attendance_rate' => $confirmedSessions > 0
                ? (int) round(($presentSessions / $confirmedSessions) * 100)
                : null,
            'confirmed_sessions' => $confirmedSessions,
            'at_risk' => $atRisk,
            'at_risk_rule' => $rule->describe(),
        ];
    }

    /**
     * Every beneficiary's session marks in date order, NULL where a scanned
     * mark is still unconfirmed.
     *
     * Fetched, never aggregated in SQL: a `SUM(CASE WHEN is_present ...)` folds
     * NULL into "absent", which is the one thing the attendance rule must never
     * do. One query serves the cards, the at-risk panel and the roll.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return Collection<int, list<bool|null>>
     */
    private function marksByRecord(Collection $beneficiaries): Collection
    {
        return FeedingBeneficiarySummary::marksByRecord($beneficiaries);
    }

    /**
     * The learners the attendance flow wants the coordinator to look at —
     * flagged for follow-up, awaiting confirmation, or under an attendance
     * notice — the decision waiting on them first.
     *
     * Every standing comes from FeedingAttendanceFlag, the reading the
     * For Follow-up Students tab works from, so the two never list different
     * learners. Days present is counted over **confirmed** sessions only — the
     * same denominator the rule uses.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array<string, mixed>
     */
    private function buildAttendanceRisk(Collection $beneficiaries, FeedingAtRiskRule $rule): array
    {
        $marksByRecord = $this->marksByRecord($beneficiaries);
        $flags = FeedingAttendanceFlag::forRecords($beneficiaries, $rule);

        $rows = $beneficiaries
            ->map(function (StudentHealthRecord $record) use ($marksByRecord, $flags, $rule): ?array {
                $flag = $flags[$record->id] ?? null;

                if ($flag === null || ! in_array($flag['state'], FeedingAttendanceFlag::ATTENTION, true)) {
                    return null;
                }

                $marks = $marksByRecord->get($record->id, []);
                [$grade, $section] = $this->splitSection((string) $record->section);
                $confirmed = count(array_filter($marks, static fn ($mark) => $mark !== null));

                return [
                    'id' => $record->id,
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'section' => $section,
                    'rate' => $rule->attendanceRate($marks),
                    'present' => $rule->presentCount($marks),
                    'sessions' => $confirmed,
                    'run' => $flag['run'],
                    'state' => $flag['state'],
                    'state_label' => $flag['label'],
                    'state_badge' => $flag['badge'],
                    'priority' => $flag['priority'],
                ];
            })
            ->filter()
            ->sortBy(fn (array $row): array => [
                FeedingAttendanceFlag::priorityRank($row['priority']),
                FeedingAttendanceFlag::stateRank($row['state']),
                -$row['run'],
                strtolower($row['name']),
            ])
            ->values()
            ->all();

        $count = fn (string $state): int => count(array_filter($rows, static fn (array $row): bool => $row['state'] === $state));

        return [
            'threshold_label' => $rule->thresholdLabel(),
            'notice_label' => $rule->noticeLabel(),
            'rule' => $rule->describe(),
            'mode' => $rule->mode(),
            'count' => $count(FeedingAttendanceFlag::FLAGGED),
            'awaiting' => $count(FeedingAttendanceFlag::AWAITING_CONFIRMATION),
            'notices' => $count(FeedingAttendanceFlag::NOTICE),
            'rows' => $rows,
        ];
    }

    /**
     * A learner's endline status on the same scale as panelStatus(), or '' when
     * no endline measurement has been taken yet. An unmeasured learner is not
     * "unchanged" — they are simply not part of the improvement figure.
     */
    private function endlineStatus(StudentHealthRecord $record): string
    {
        $status = trim((string) $record->endline_nutritional_status);

        if ($status === '') {
            return '';
        }

        $normalized = $this->normalizeStatus($status);

        return match ($normalized) {
            // Same folding as panelStatus(): the app's "Underweight" is the
            // DepEd sheet's Wasted, and anything above Normal shares one rung.
            'Underweight' => 'Wasted',
            'Overweight' => FeedingNutritionProgress::ABOVE_NORMAL,
            default => $normalized,
        };
    }

    /** "Grades 7–10" — what the programme covers, said once. */
    public static function gradeRangeLabel(): string
    {
        $grades = FeedingBeneficiarySummary::GRADE_LEVELS;

        return 'Grades '.min($grades).'–'.max($grades);
    }

    private function resolveStatus(string $status): string
    {
        $normalized = strtolower($status);
        if (str_contains($normalized, 'severe')) {
            return 'severe';
        }
        if (str_contains($normalized, 'wast') || str_contains($normalized, 'underweight')) {
            return 'wasted';
        }
        if (str_contains($normalized, 'over')) {
            return 'over';
        }

        return 'normal';
    }
}
