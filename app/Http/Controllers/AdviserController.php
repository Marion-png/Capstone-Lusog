<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\AdviserClassScope;
use App\Support\AuditTrail;
use App\Support\BmiClassifier;
use App\Support\ClassMasterlistTemplate;
use App\Support\MasterlistSheetScanner;
use App\Support\PostureGait;
use App\Support\SchemaCache;
use App\Support\SchoolLetterhead;
use App\Support\StudentImportSheet;
use App\Support\StudentRosterSync;
use App\Support\StudentVitalSigns;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AdviserController extends Controller
{
    /**
     * Sheet 2 (Systems Review) checkbox answers on the enrolment form. Kept as
     * an explicit whitelist so only known keys reach the encrypted
     * student_details JSON column.
     */
    private const SYSTEMS_REVIEW_FLAGS = [
        'skin_normal', 'skin_lesions', 'skin_pallor',
        'heent_normal', 'heent_abnormal',
        'resp_clear', 'resp_cough',
        'cardio_regular', 'cardio_irregular',
        'abdo_soft', 'abdo_pain',
        'neuro_alert', 'neuro_reflexes', 'neuro_abnormal',
        'dental_good', 'dental_fair', 'dental_poor', 'dental_caries', 'dental_gum', 'dental_referral',
        'immun_complete', 'immun_incomplete', 'immun_not_available',
    ];

    /**
     * Sheet 1 sections B (Medical History), C (Family History) and
     * D (General Appearance). Whitelisted for the same reason as Sheet 2.
     */
    private const HEALTH_HISTORY_FLAGS = [
        'med_asthma', 'med_diabetes', 'med_seizure', 'med_infections',
        'med_heart', 'med_tuberculosis', 'med_hospitalization', 'med_allergies',
        'fam_hypertension', 'fam_diabetes', 'fam_heart', 'fam_cancer', 'fam_mental',
    ];

    /** Free-text and single-choice answers on Sheet 1 sections B-D. */
    private const HEALTH_HISTORY_TEXT = [
        'allergies_detail', 'hospitalization_detail',
        'current_medications', 'other_conditions', 'genetic_disorders',
        'consciousness', 'consciousness_other',
        'posture', 'posture_detail', 'hygiene',
    ];

    /** Free-text and date answers on Sheet 2. */
    private const SYSTEMS_REVIEW_TEXT = [
        'right_eye', 'left_eye', 'immun_date',
        'notes', 'summary', 'recommendations',
        'examiner_name', 'examiner_date',
    ];

    public function create(): View
    {
        return view('adviser.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge($this->normaliseEnrolmentInput($request->all()));

        $validated = $request->validate($this->enrolmentRules());
        $this->applyAssignedClass($request, $validated);

        $existingRecord = $this->existingRecordFor($request, (string) $validated['lrn']);

        // An LRN already on file for this school and year, but in another
        // class, is not this adviser's to write.
        //
        // The lookup above is keyed on LRN alone, and the grade and section
        // posted with the form were just overwritten with this adviser's own —
        // so without this check, submitting a colleague's learner overwrote
        // their record and moved the learner into the submitting adviser's
        // class. Every read is scoped to one class (AdviserClassScope); the
        // write has to be too, or the scope only holds until somebody types an
        // LRN.
        //
        // Enrolling a new learner is unaffected: there is no existing row to
        // belong to anyone. A learner genuinely moving between sections has no
        // path here and never did — that needs a transfer the two advisers and
        // the record can be held accountable for, not a silent overwrite.
        if ($existingRecord !== null && ! AdviserClassScope::coversRecord($request, $existingRecord)) {
            return back()
                ->withInput()
                ->with('error', 'LRN '.$validated['lrn'].' is already enrolled in another class at this school. Ask the School Head or System Admin to transfer the learner.');
        }

        $records = $request->session()->get('school_health_card_records', []);

        // systems_review is deliberately not read off this request: Sheet 2 is
        // the nurse's, and enrolLearner() keeps whatever is on file.
        $this->enrolLearner(
            $request,
            $validated,
            (array) $request->input('health_history', []),
            $existingRecord,
            $records
        );

        $request->session()->put('school_health_card_records', $records);

        return redirect()
            ->route('dashboard.class-adviser')
            ->with('success', $existingRecord !== null ? 'Student record updated.' : 'Student enrolled.');
    }

    /**
     * Enrol a class from a spreadsheet — one CSV or XLSX, one row per learner.
     *
     * The alternative to typing Sheet 1 forty times, and deliberately not a
     * second way of writing a learner: every row is shaped like the form's own
     * post (App\Support\StudentImportSheet), validated by the form's own rules,
     * and written by the same enrolLearner() the form uses — same scope check,
     * same classification, same persistence. A row that fails is reported by
     * its line number and skipped; the rows that pass are enrolled, so one bad
     * phone number does not hold up the other thirty-nine.
     *
     * The existing rows for every LRN on the sheet are fetched in one query
     * rather than one per row: against a hosted database a class-sized import
     * would otherwise spend most of its time waiting on round trips.
     */
    public function import(Request $request, MasterlistSheetScanner $scanner): RedirectResponse
    {
        // One picker takes the masterlist in whichever form the adviser has it:
        // the workbook, or a photograph of the paper one. The photo formats are
        // accepted only where the reader is configured, so an upload can never
        // be taken and then refused for want of a key.
        $canScan = MasterlistSheetScanner::isConfigured();
        $formats = array_merge(
            ['csv', 'txt', 'xlsx'],
            $canScan ? MasterlistSheetScanner::ACCEPTED_EXTENSIONS : []
        );

        $request->validate([
            'students_file' => [
                'required',
                'file',
                'mimes:'.implode(',', $formats),
                'max:'.max(5120, MasterlistSheetScanner::maxUploadKb()),
            ],
        ]);

        $file = $request->file('students_file');

        // A picture is read by the scanner; everything else is a spreadsheet.
        // Both end at the same enrolRows() write, so this decides how the class
        // list is *read* and nothing about how a learner is written.
        if ($canScan && MasterlistSheetScanner::handles($file)) {
            return $this->importFromPicture($request, $scanner, $file);
        }

        $sheet = StudentImportSheet::read($file);

        if ($sheet['missing'] !== []) {
            $labels = ['lrn' => 'LRN', 'last_name' => 'Last Name', 'first_name' => 'First Name'];

            return redirect()
                ->route('dashboard.class-adviser', ['tab' => 'form'])
                ->with('error', 'The spreadsheet has no '.implode(', ', array_map(fn ($k) => $labels[$k] ?? $k, $sheet['missing'])).' column. Download the template and keep its headings.');
        }

        if ($sheet['rows'] === []) {
            return redirect()
                ->route('dashboard.class-adviser', ['tab' => 'form'])
                ->with('error', 'The spreadsheet has no learner rows under its heading.');
        }

        if (count($sheet['rows']) > StudentImportSheet::MAX_ROWS) {
            return redirect()
                ->route('dashboard.class-adviser', ['tab' => 'form'])
                ->with('error', 'A single import is limited to '.StudentImportSheet::MAX_ROWS.' learners.');
        }

        $result = $this->enrolRows($request, $sheet['rows'], 'Imported a class list');

        return redirect()
            ->route('dashboard.class-adviser', ['tab' => $result['errors'] === [] ? 'saved' : 'form'])
            ->with('import_report', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'errors' => $result['errors'],
                'total' => $sheet['total'],
            ])
            ->with('success', $result['created'] + $result['updated'] > 0
                ? $result['created'].' '.Str::plural('learner', $result['created']).' enrolled'.($result['updated'] > 0 ? ', '.$result['updated'].' updated' : '').' from the spreadsheet.'
                : null);
    }

    /**
     * Read a photographed or scanned CLASS MASTERLIST and enrol what is on it.
     *
     * The same document the spreadsheet import takes, taken as a picture: the
     * form already on the adviser's desk, photographed or scanned, rather than
     * a workbook somebody has to build first. App\Support\MasterlistSheetScanner
     * sends it to Gemini and hands back rows shaped exactly like
     * StudentImportSheet's, so they go through enrolRows() — the same rules, the
     * same class scoping, the same enrolLearner() write. A camera is a second
     * way to read a class list, never a second way to write a learner.
     *
     * Reached from import() and nowhere else: the adviser has one file picker
     * and one submit, and which reader runs is decided from the file rather
     * than from which of two buttons was pressed.
     *
     * **A document that is not this form enrols nobody.** The check is taken
     * before a single row is written (ClassMasterlistTemplate::mismatches(),
     * against the headings the model reports rather than its opinion of them),
     * and a refusal names what was missing and returns the adviser to the panel
     * with the roster exactly as it was. There is no partial state to undo,
     * because nothing was begun.
     *
     * Name, LRN and sex are what a masterlist carries, so they are what Sheet 1
     * is filled with. Everything else on the card — birth date, guardian,
     * address, contact, height, weight — stays empty and is counted by
     * StudentDataCompleteness as still to be entered, exactly as it is for the
     * spreadsheet import.
     */
    private function importFromPicture(Request $request, MasterlistSheetScanner $scanner, UploadedFile $file): RedirectResponse
    {
        try {
            $scan = $scanner->scan($file);
        } catch (Throwable $e) {
            // Deliberately no partial write: a failed read leaves the roster
            // exactly as it was.
            return back()->with('error', 'Could not read the masterlist. '.$e->getMessage());
        }

        if (! $scan['matches_template']) {
            $seen = trim((string) $scan['document_seen']);

            return back()->with('error', trim(
                'The file you uploaded is not a '.ClassMasterlistTemplate::TITLE.', so nothing was enrolled.'
                .($seen !== '' ? ' It reads as: '.$seen.'.' : '')
                .' It is missing '.$this->readableList($scan['mismatches']).'.'
                .' Download the masterlist above and upload that form.'
            ));
        }

        if ($scan['rows'] === [] && $scan['errors'] === []) {
            return back()->with('error', 'No learners could be read from that masterlist, so nothing was enrolled.'
                .($scan['note'] !== '' ? ' '.$scan['note'] : ''));
        }

        $result = $this->enrolRows($request, $scan['rows'], 'Enrolled a class from a scanned masterlist');

        // A learner the scan could not read is reported beside the rows that
        // failed validation: both are lines the adviser has to look at, and one
        // list of them is easier to work through than two.
        $errors = array_merge($scan['errors'], $result['errors']);
        usort($errors, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return redirect()
            ->route('dashboard.class-adviser', ['tab' => $errors === [] ? 'saved' : 'form'])
            ->with('import_report', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'errors' => $errors,
                'total' => $scan['total'],
            ])
            ->with('success', $result['created'] + $result['updated'] > 0
                ? $result['created'].' '.Str::plural('learner', $result['created']).' enrolled'.($result['updated'] > 0 ? ', '.$result['updated'].' updated' : '').' from the scanned masterlist.'
                : null);
    }

    /**
     * The one place a read class list becomes enrolled learners.
     *
     * Shared by the spreadsheet import and the scanned masterlist, so the two
     * cannot drift: same rules, same class scoping, same refusal of an LRN
     * belonging to another class, same enrolLearner() write, same audit entry.
     * Adding a third way to read a class list means calling this, never copying
     * it.
     *
     * The existing rows for every LRN on the sheet are fetched in one query
     * rather than one per row: against a hosted database a class-sized import
     * would otherwise spend most of its time waiting on round trips.
     *
     * @param  list<array{line: int, data: array<string, mixed>}>  $rows
     * @return array{created: int, updated: int, errors: list<array{line: int, message: string}>}
     */
    private function enrolRows(Request $request, array $rows, string $auditSummary): array
    {
        $existingByLrn = collect();
        if (SchemaCache::hasTable('student_health_records')) {
            $lrns = collect($rows)
                ->map(fn (array $row): string => trim((string) ($row['data']['lrn'] ?? '')))
                ->filter()
                ->unique()
                ->values();

            $existingByLrn = StudentHealthRecord::query()
                ->where('institution_id', $request->session()->get('active_institution_id'))
                ->where('school_year', StudentHealthRecord::currentSchoolYear())
                ->whereIn('student_id', $lrns->all())
                ->get()
                ->keyBy(fn (StudentHealthRecord $record): string => (string) $record->student_id);
        }

        $records = $request->session()->get('school_health_card_records', []);
        $created = 0;
        $updated = 0;
        $errors = [];
        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $input = $this->normaliseEnrolmentInput($row['data']);

            $validator = Validator::make($input, $this->importRules());
            if ($validator->fails()) {
                $errors[] = ['line' => $line, 'message' => $validator->errors()->first()];

                continue;
            }

            $validated = $validator->validated();
            $this->applyAssignedClass($request, $validated);
            $lrn = (string) $validated['lrn'];

            if (isset($seen[$lrn])) {
                $errors[] = ['line' => $line, 'message' => 'LRN '.$lrn.' appears more than once on the sheet; the first row was used.'];

                continue;
            }
            $seen[$lrn] = true;

            $existingRecord = $existingByLrn->get($lrn);

            if ($existingRecord !== null && ! AdviserClassScope::coversRecord($request, $existingRecord)) {
                $errors[] = ['line' => $line, 'message' => 'LRN '.$lrn.' is already enrolled in another class at this school.'];

                continue;
            }

            $this->enrolLearner($request, $validated, [], $existingRecord, $records);

            if ($existingRecord !== null) {
                $updated++;
            } else {
                $created++;
            }
        }

        $request->session()->put('school_health_card_records', $records);

        AuditTrail::record(
            'imported',
            'StudentHealthRecord',
            null,
            $auditSummary.': '.$created.' enrolled, '.$updated.' updated, '.count($errors).' skipped',
            ['created' => $created, 'updated' => $updated, 'skipped' => count($errors)]
        );

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /** "a, b and c" — a refusal reads as a sentence, not as a list. */
    private function readableList(array $items): string
    {
        $items = array_values(array_filter(array_map('strval', $items)));

        if (count($items) <= 1) {
            return (string) ($items[0] ?? 'nothing this form is recognised by');
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * The blank sheet an adviser fills in: their own class masterlist.
     *
     * It used to be a flat twelve-column CSV of this application's invention —
     * LRN, names, birth date, birthplace, guardian, address, contact, height,
     * weight — a sheet no school keeps, so the one document every adviser
     * already has was not what the button handed them, and the feature only
     * worked for a list somebody had already retyped by hand. The template is
     * now the DepEd CLASS MASTERLIST itself (App\Support\ClassMasterlistTemplate),
     * headed for this adviser's own school, class and school year, so the file
     * that downloads and the file the class already holds are one document.
     *
     * The wider set of columns has not gone anywhere: StudentImportSheet still
     * recognises every one of them, so a sheet exported from another system
     * uploads exactly as before. What changed is which sheet is offered.
     *
     * It is .xlsx rather than .csv for the reason every other export here is —
     * a .csv is a text file and which program opens one is a setting on the
     * reader's own machine, while an .xlsx is a spreadsheet by format. A ruled
     * form with a merged heading does not survive comma-separated text at all.
     */
    public function importTemplate(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! class_exists(XlsxWriter::class)) {
            return back()->with('error', 'The spreadsheet library is not installed on this server, so the template cannot be downloaded.');
        }

        $session = $request->session();

        $template = ClassMasterlistTemplate::build(
            SchoolLetterhead::for(
                $session->get('active_institution_id'),
                (string) $session->get('active_school_name', '')
            ),
            ClassMasterlistTemplate::classLabel(
                (string) $session->get('assigned_grade_level', ''),
                (string) $session->get('assigned_section', '')
            ),
            StudentHealthRecord::currentSchoolYear(),
            (string) $session->get('active_name', '')
        );

        return response()
            ->download($this->writeMasterlist($template), $template['file_name'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * The template model, written out as a workbook.
     *
     * Column widths and merges are declared up front because OpenSpout takes
     * them as options rather than as calls on the sheet — the heading block and
     * the stacked header are merged cells, and a merge the writer never hears
     * about leaves the caption sitting in one narrow column.
     *
     * @param  array{rows: list<array{cells: list<string>, register: string}>, merges: list<array{0: int, 1: int, 2: int, 3: int}>, widths: list<int>, file_name: string}  $template
     */
    private function writeMasterlist(array $template): string
    {
        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path — otherwise every download
        // leaves an empty temp file behind.
        $reserved = tempnam(sys_get_temp_dir(), 'class-masterlist-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $options = new XlsxOptions;

        foreach ($template['widths'] as $column => $width) {
            $options->setColumnWidth((float) $width, $column + 1);
        }

        foreach ($template['merges'] as [$left, $top, $right, $bottom]) {
            $options->mergeCells($left, $top, $right, $bottom);
        }

        $writer = new XlsxWriter($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(ClassMasterlistTemplate::SHEET_NAME);

        $styles = $this->masterlistStyles();

        try {
            foreach ($template['rows'] as $row) {
                $style = $styles[$row['register']] ?? $styles[ClassMasterlistTemplate::R_PLAIN];

                $writer->addRow(new Row(array_map(
                    static fn (string $value): Cell => Cell::fromValue($value, $style),
                    $row['cells']
                )));
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * The registers the form is printed in.
     *
     * A hairline box, as on the printed sheet: the DepEd masterlist is a ruled
     * grid, and an unruled block of blank rows is not the same document — an
     * adviser cannot see where one learner's line ends and the next begins.
     *
     * @return array<string, Style>
     */
    private function masterlistStyles(): array
    {
        $box = static fn (): Border => new Border(
            new BorderPart(BorderName::TOP, width: BorderWidth::THIN),
            new BorderPart(BorderName::BOTTOM, width: BorderWidth::THIN),
            new BorderPart(BorderName::LEFT, width: BorderWidth::THIN),
            new BorderPart(BorderName::RIGHT, width: BorderWidth::THIN),
        );

        $centred = (new Style)->withCellAlignment(CellAlignment::CENTER);

        return [
            ClassMasterlistTemplate::R_SCHOOL => $centred->withFontBold(true)->withFontSize(12),
            ClassMasterlistTemplate::R_HEADING => $centred->withFontBold(true)->withFontSize(10),
            ClassMasterlistTemplate::R_CLASS => $centred->withFontBold(true)->withFontSize(10),
            ClassMasterlistTemplate::R_HEAD => $centred->withFontBold(true)->withFontSize(10)->withBorder($box()),
            ClassMasterlistTemplate::R_BAND => (new Style)->withFontBold(true)->withFontSize(10)->withBorder($box()),
            ClassMasterlistTemplate::R_LINE => (new Style)->withFontSize(10)->withBorder($box()),
            ClassMasterlistTemplate::R_PLAIN => new Style,
        ];
    }

    /**
     * The enrolment form's own rules, shared by the form and the import.
     *
     * @return array<string, list<string>>
     */
    private function enrolmentRules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'lrn' => ['required', 'string', 'max:50'],
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_day' => ['required', 'integer', 'between:1,31'],
            'birth_year' => ['required', 'integer', 'between:1900,2100'],
            'birthplace' => ['required', 'string', 'max:255'],
            'parent_guardian' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            // School-level identifiers are not collected on the School Health
            // Card form — only the legacy /adviser/create form still posts them.
            'region' => ['nullable', 'string', 'max:255'],
            'division' => ['nullable', 'string', 'max:255'],
            'telephone_no' => ['required', 'string', 'max:50'],
            'gender' => ['nullable', 'string', 'max:20'],
            'height_cm' => ['required', 'numeric', 'min:30', 'max:250'],
            'weight_kg' => ['required', 'numeric', 'min:0.1', 'max:250'],
            'grade_level' => ['required', 'string', 'max:50'],
            'section' => ['required', 'string', 'max:100'],
            'systems_review' => ['nullable', 'array'],
            // A 2MB image is roughly 2.8M characters once base64-encoded.
            'systems_review.examiner_signature' => ['nullable', 'string', 'max:2900000'],
            'health_history' => ['nullable', 'array'],
            // Temperature, pulse and blood pressure are deliberately absent.
            // They are the school nurse's fields — see App\Support\StudentVitalSigns
            // and StudentVitalSignsController. Whatever a form posts for them
            // is ignored and the stored reading is carried across instead.
        ];
    }

    /**
     * The rules a spreadsheet row is judged by.
     *
     * The form's rules with the School Health Card half relaxed, and nothing
     * else. A class masterlist is a roster — NO, the learner's name, their
     * LRN, a remarks column — and it is the document a school actually holds
     * at the start of a year. Requiring a birthplace, a guardian, an address,
     * a contact number, a height and a weight of it meant the one sheet every
     * adviser has could never be uploaded, so the feature only worked for a
     * sheet somebody had already retyped by hand.
     *
     * What is relaxed is **completeness, and only completeness**. Everything
     * that makes the import safe is untouched and shared with the form: the
     * row is still written by enrolLearner(), still scoped by
     * applyAssignedClass() to this adviser's own class whatever the sheet's
     * title row says, still refused when its LRN belongs to another class at
     * the school, and every value still has to pass the same type and length
     * rules. A spreadsheet is still not a way around the rules — it is a way
     * to start a learner's record before the measuring has happened.
     *
     * A half-filled learner is not a silent state. StudentDataCompleteness
     * already names each of these fields, and the adviser's own dashboard
     * already counts the learners still missing them, so an imported roster
     * shows up as the data entry it is.
     *
     * @return array<string, list<string>>
     */
    private function importRules(): array
    {
        $rules = $this->enrolmentRules();

        // Name, LRN and the class stay required: they are what identifies the
        // learner and what scopes the write, and the masterlist carries them.
        foreach ([
            'birth_month', 'birth_day', 'birth_year',
            'birthplace', 'parent_guardian', 'address', 'telephone_no',
            'height_cm', 'weight_kg',
        ] as $field) {
            $rules[$field] = array_values(array_map(
                fn (string $rule): string => $rule === 'required' ? 'nullable' : $rule,
                $rules[$field] ?? []
            ));
        }

        // A date the adviser *did* give is judged even though giving one is
        // now optional. Without this, "15/06/2012" in the Birth Date column
        // parses to nothing, passes as "not provided" and the learner is
        // enrolled with no birth date at all — the sheet said something and
        // the import quietly disagreed. Absent is fine; unreadable is not.
        $rules['birth_date'] = ['nullable', 'date_format:Y-m-d'];

        return $rules;
    }

    /**
     * The form posts a birth date as one field or three, and a height in
     * centimetres or metres; the import posts what the sheet had. Both are
     * reduced to the shape the rules expect. A row with no grade or section
     * gets the adviser's own, which applyAssignedClass() enforces regardless.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normaliseEnrolmentInput(array $input): array
    {
        foreach (['birth_month', 'birth_day', 'birth_year'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && ctype_digit($input[$key])) {
                $input[$key] = (int) $input[$key];
            }
        }

        $birthDate = trim((string) ($input['birth_date'] ?? ''));
        if ($birthDate !== '') {
            try {
                $parsed = Carbon::createFromFormat('Y-m-d', $birthDate);
                $input['birth_year'] = (int) $parsed->format('Y');
                $input['birth_month'] = (int) $parsed->format('n');
                $input['birth_day'] = (int) $parsed->format('j');
            } catch (Throwable $_) {
                // Keep whatever month/day/year the input already carried.
            }
        }

        if ((empty($input['birth_month']) || empty($input['birth_day']) || empty($input['birth_year']))
            && $birthDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            [$yearPart, $monthPart, $dayPart] = explode('-', $birthDate);
            $input['birth_year'] = (int) $yearPart;
            $input['birth_month'] = (int) $monthPart;
            $input['birth_day'] = (int) $dayPart;
        }

        $heightCm = $input['height_cm'] ?? null;
        $heightMeters = $input['height_m'] ?? null;
        if ((! is_numeric($heightCm) || (float) $heightCm <= 0) && is_numeric($heightMeters)) {
            $input['height_cm'] = round(((float) $heightMeters) * 100, 2);
        }

        // A height under three metres typed in the centimetre column is a
        // height in metres ("1.42"): the rules would refuse it as under 30 cm.
        if (is_numeric($input['height_cm'] ?? null) && (float) $input['height_cm'] > 0 && (float) $input['height_cm'] < 3) {
            $input['height_cm'] = round(((float) $input['height_cm']) * 100, 2);
        }

        $input['grade_level'] = trim((string) ($input['grade_level'] ?? '')) ?: (string) session('assigned_grade_level', '');
        $input['section'] = trim((string) ($input['section'] ?? '')) ?: (string) session('assigned_section', '');

        return $input;
    }

    /**
     * The write is scoped to the adviser's own class, whatever was posted.
     *
     * @param  array<string, mixed>  $validated
     */
    private function applyAssignedClass(Request $request, array &$validated): void
    {
        $assignedGradeLevel = (string) $request->session()->get('assigned_grade_level', '');
        $assignedSection = (string) $request->session()->get('assigned_section', '');

        if ($assignedGradeLevel !== '') {
            $validated['grade_level'] = $assignedGradeLevel;
        }
        if ($assignedSection !== '') {
            $validated['section'] = $assignedSection;
        }
    }

    private function existingRecordFor(Request $request, string $lrn): ?StudentHealthRecord
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            return null;
        }

        return StudentHealthRecord::query()
            ->where('student_id', $lrn)
            ->where('institution_id', $request->session()->get('active_institution_id'))
            ->where('school_year', StudentHealthRecord::currentSchoolYear())
            ->first();
    }

    /**
     * One learner, enrolled or updated: the roster row and the database row.
     *
     * The single path every enrolment takes — the form and the spreadsheet
     * import both land here — so a learner is classified, scoped and
     * persisted one way. The caller has already validated the input and
     * checked the class scope; this writes the roster row into $records (the
     * caller saves the session once) and the database row immediately.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<mixed>  $healthHistoryInput
     * @param  array<int, array<string, mixed>>  $records
     */
    private function enrolLearner(
        Request $request,
        array $validated,
        array $healthHistoryInput,
        ?StudentHealthRecord $existingRecord,
        array &$records,
    ): void {
        // A masterlist carries no birth date, and the import's rules no longer
        // demand one — so these keys can be absent from validated() entirely
        // rather than merely empty. resolveAge() already reads a zero as "no
        // date", so an unmeasured learner simply has no age.
        $birthYear = (int) ($validated['birth_year'] ?? 0);
        $birthMonth = (int) ($validated['birth_month'] ?? 0);
        $birthDay = (int) ($validated['birth_day'] ?? 0);

        $age = $this->resolveAge($birthYear, $birthMonth, $birthDay);

        // NULL where nobody has measured the learner yet, never 0.
        //
        // An imported class masterlist carries no height or weight, and a zero
        // does not stay in these columns: it becomes a BMI, then a nutritional
        // status, then a learner the feeding programme qualifies, then a cell
        // in the DepEd grid the School Head exports. "0 kg, Severely Wasted"
        // is a child fed on the strength of a measurement nobody took. An
        // absent reading is reported as absent all the way through — the same
        // rule the Feeding Program roster keeps when it prints "No baseline"
        // rather than inventing one.
        $heightCm = $this->measurementOrNull($validated['height_cm'] ?? null);
        $weightKg = $this->measurementOrNull($validated['weight_kg'] ?? null);

        $bmi = ($heightCm === null || $weightKg === null)
            ? null
            : $this->computeBmi($heightCm, $weightKg);
        // A status is a reading of a measurement, so an unmeasured learner has
        // none at all rather than the classifier's "Not enough data" — which
        // is a sentence about the record, not a nutritional status, and would
        // be counted as one by anything grouping on the column.
        $nutritionalStatusBmiForAge = $bmi === null ? null : $this->classifyBmiForAge($bmi, $age);
        $nutritionalStatusHeightForAge = $heightCm === null
            ? null
            : $this->classifyHeightForAge($heightCm, $age);

        // Sheet 2 belongs to the School Nurse (invariant).
        //
        // The systems review is a clinical finding, so it is examined and
        // recorded by the nurse on Fill Medical Record — the adviser reads it
        // and never writes it, at enrolment or afterwards. This endpoint
        // therefore keeps whatever is on file and ignores whatever the form
        // posted: a disabled control is only a suggestion, and a stale tab, a
        // replayed form or devtools all reach it the same way. A finding a
        // browser round-trip can silently overwrite is not a record.
        //
        // (Until the nurse's form existed the adviser wrote it at enrolment —
        // docs/open-decisions.md entry 3, now settled. Reviews recorded that
        // way are kept and still read everywhere; nothing is migrated away.)
        $storedReview = $existingRecord?->student_details['systems_review'] ?? null;

        $systemsReview = $this->normaliseSystemsReview(is_array($storedReview) ? $storedReview : []);

        // The roster carries no copy of the signature image, so the pad is blank
        // whenever an existing learner is edited. Blank means "keep what is on
        // file" — re-signing is only needed to replace it.
        if ($systemsReview['examiner_signature'] === null) {
            $stored = $existingRecord?->student_details['systems_review']['examiner_signature'] ?? null;
            $systemsReview['examiner_signature'] = is_string($stored) && $stored !== '' ? $stored : null;
        }

        // The spreadsheet carries no Sheet 1 history; an existing learner's
        // stays as it was rather than being blanked by a re-import.
        $storedHistory = $existingRecord?->student_details['health_history'] ?? null;
        $healthHistory = ($healthHistoryInput === [] && is_array($storedHistory))
            ? $this->normaliseHealthHistory($storedHistory)
            : $this->normaliseHealthHistory($healthHistoryInput);

        $sessionRow = [
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'lrn' => $validated['lrn'],
            // Null, not '', for every field the sheet did not carry:
            // StudentDataCompleteness reads these and names the ones still to
            // be entered, which is how an imported roster reaches the
            // adviser's own "needs data entry" count.
            'birth_month' => $validated['birth_month'] ?? null,
            'birth_day' => $validated['birth_day'] ?? null,
            'birth_year' => $validated['birth_year'] ?? null,
            'birthplace' => $validated['birthplace'] ?? null,
            'parent_guardian' => $validated['parent_guardian'] ?? null,
            'address' => $validated['address'] ?? null,
            'region' => $validated['region'] ?? null,
            'division' => $validated['division'] ?? null,
            'telephone_no' => $validated['telephone_no'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            // Vital signs are filled in from the record by StudentVitalSigns
            // ::preserve() below, never from this request.
            'health_history' => $healthHistory,
            'age' => $age,
            'bmi_value' => $bmi,
            'nutritional_status_bmi_for_age' => $nutritionalStatusBmiForAge,
            'nutritional_status_height_for_age' => $nutritionalStatusHeightForAge,
            'grade_level' => $validated['grade_level'],
            'section' => $validated['section'],
            'systems_review' => $systemsReview,
            'examination' => [],
        ];

        // The adviser's form rebuilds the whole card on every save, so the
        // nurse's reading has to be carried across explicitly or a teacher
        // correcting a phone number would wipe it.
        $sessionRow = StudentVitalSigns::preserve($sessionRow, $existingRecord);

        // Editing a learner re-posts their LRN. Replace that roster row rather
        // than appending, or My Students would list the learner twice — the DB
        // side already resolves the same LRN to one row via updateOrCreate.
        $existingIndex = null;
        foreach ($records as $index => $existingRow) {
            if ((string) ($existingRow['lrn'] ?? '') === (string) $validated['lrn']) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex !== null) {
            // The nurse owns the examination, and the feeding coordinator owns
            // attendance — an adviser edit must not wipe either.
            $sessionRow['examination'] = $records[$existingIndex]['examination'] ?? [];
            $sessionRow['attendance_by_month'] = $records[$existingIndex]['attendance_by_month'] ?? [];
        }

        // The database keeps the signature image; the session roster does not.
        $rosterRow = StudentRosterSync::withoutSignature($sessionRow);

        if ($existingIndex !== null) {
            $records[$existingIndex] = $rosterRow;
        } else {
            $records[] = $rosterRow;
        }

        if (! SchemaCache::hasTable('student_health_records')) {
            return;
        }

        $studentName = $this->buildStudentName(
            (string) $validated['last_name'],
            (string) $validated['first_name'],
            (string) ($validated['middle_name'] ?? '')
        );

        $schoolName = (string) $request->session()->get('assigned_school_name', '');
        if ($schoolName === '') {
            $schoolName = (string) ($validated['division'] ?? '');
        }

        $sectionLabel = trim((string) $validated['grade_level'].' / '.(string) $validated['section']);

        // Persist the full adviser entry so the roster can be rebuilt after
        // session loss or a server restart. Examination and attendance live
        // in their own columns, so they are excluded here.
        $details = $sessionRow;
        unset($details['examination'], $details['attendance_by_month']);

        $payload = [
            'institution_id' => $request->session()->get('active_institution_id'),
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $studentName,
            'section' => $sectionLabel !== '' ? $sectionLabel : (string) $validated['section'],
            'student_details' => $details,
            'weight' => $weightKg,
            'bmi_value' => $bmi,
            'nutritional_status' => $nutritionalStatusBmiForAge,
            'baseline_age' => $age,
            'baseline_height_cm' => $heightCm,
            'baseline_weight_kg' => $weightKg,
            'baseline_bmi_value' => $bmi,
            'baseline_nutritional_status' => $nutritionalStatusBmiForAge,
            'baseline_recorded_at' => now()->toDateString(),
        ];

        if (SchemaCache::hasColumn('student_health_records', 'school_name')) {
            $payload['school_name'] = $schoolName !== '' ? $schoolName : null;
        }

        // Keep compatibility with databases that have not run newer migrations yet.
        $existingColumns = array_flip(SchemaCache::columns('student_health_records'));
        $payload = array_intersect_key($payload, $existingColumns);

        // updateOrCreate() would re-run the lookup we already did above —
        // a wasted round trip, and a slow one against a hosted database.
        if ($existingRecord !== null) {
            $existingRecord->fill($payload)->save();
        } else {
            StudentHealthRecord::query()->create($payload + [
                'student_id' => (string) $validated['lrn'],
                'institution_id' => $request->session()->get('active_institution_id'),
                'school_year' => StudentHealthRecord::currentSchoolYear(),
            ]);
        }
    }

    public function success(): View
    {
        return view('adviser.success');
    }

    /**
     * Reduce a posted checklist to its whitelisted keys. Unchecked boxes are
     * absent from the request, so every flag is normalised to a boolean and
     * every text answer to a trimmed string or null.
     *
     * @param  array<mixed>  $input
     * @param  array<string>  $flags
     * @param  array<string>  $textKeys
     * @return array<string, bool|string|null>
     */
    private function normaliseChecklist(array $input, array $flags, array $textKeys): array
    {
        $result = [];

        foreach ($flags as $flag) {
            $result[$flag] = filter_var($input[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        foreach ($textKeys as $key) {
            $value = $input[$key] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';
            $result[$key] = $value !== '' ? mb_substr($value, 0, 1000) : null;
        }

        return $result;
    }

    /** @param  array<mixed>  $input */
    private function normaliseSystemsReview(array $input): array
    {
        $review = $this->normaliseChecklist($input, self::SYSTEMS_REVIEW_FLAGS, self::SYSTEMS_REVIEW_TEXT);
        $review['examiner_signature'] = $this->normaliseSignature($input['examiner_signature'] ?? null);

        return $review;
    }

    /** @param  array<mixed>  $input */
    private function normaliseHealthHistory(array $input): array
    {
        // Posture / Gait is Good or Poor; a form still posting the old
        // Normal / Abnormal words is saved under the current ones.
        return PostureGait::normalizeHistory(
            $this->normaliseChecklist($input, self::HEALTH_HISTORY_FLAGS, self::HEALTH_HISTORY_TEXT)
        );
    }

    /**
     * The examiner signature arrives as a data URL, drawn on a canvas or read
     * from an uploaded PNG/JPG. Anything that is not one of those two shapes is
     * discarded rather than stored.
     */
    private function normaliseSignature(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $value) === 1
            ? $value
            : null;
    }

    private function resolveAge(int $birthYear, int $birthMonth, int $birthDay): ?int
    {
        if ($birthYear <= 0 || $birthMonth <= 0 || $birthDay <= 0) {
            return null;
        }

        try {
            $birthDate = Carbon::createFromDate($birthYear, $birthMonth, $birthDay);
        } catch (Throwable $_) {
            return null;
        }

        return $birthDate->isFuture() ? null : $birthDate->age;
    }

    /**
     * A measurement, or null where there is none.
     *
     * A blank cell, a missing column and a zero all mean the same thing here:
     * nobody has taken this reading. Casting them to 0.0 is what turns "not
     * measured" into a number the rest of the app will happily classify.
     */
    private function measurementOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return ((float) $value) > 0 ? (float) $value : null;
    }

    private function computeBmi(float $heightCm, float $weightKg): ?float
    {
        if ($heightCm <= 0 || $weightKg <= 0) {
            return null;
        }

        $heightMeters = $heightCm / 100;

        return round($weightKg / ($heightMeters * $heightMeters), 2);
    }

    private function classifyBmiForAge(?float $bmi, ?int $age): string
    {
        return BmiClassifier::bmiForAge($bmi, $age);
    }

    private function classifyHeightForAge(float $heightCm, ?int $age): string
    {
        if ($heightCm <= 0 || $age === null) {
            return 'Not enough data';
        }

        $minNormalHeight = 70 + ($age * 5);
        if ($heightCm < ($minNormalHeight - 8)) {
            return 'Severely Stunted';
        }
        if ($heightCm < $minNormalHeight) {
            return 'Stunted';
        }

        return 'Normal Height-for-Age';
    }

    private function buildStudentName(string $lastName, string $firstName, string $middleName): string
    {
        $middleName = trim($middleName);
        $middleInitial = $middleName !== '' ? (' '.strtoupper(substr($middleName, 0, 1)).'.') : '';

        return trim(trim($lastName).', '.trim($firstName).$middleInitial);
    }
}
