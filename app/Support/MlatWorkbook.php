<?php

namespace App\Support;

use App\Models\StudentHealthRecord;
use Carbon\Carbon;

/**
 * The Mandatory Learner's Health Assessment Tool, laid out as the clinic's own
 * spreadsheet.
 *
 * The school already keeps this form as a two-sheet workbook — Sheet 1
 * (learner information, history, vital signs) and Sheet 2 (systems review,
 * screenings, recommendations) — with a fixed run of lettered sections and a
 * particular arrangement of labels across six columns. A download that comes
 * out in any other shape is a document the clinic has to retype, so this
 * builds the same cells in the same places.
 *
 * It builds a **model**, not a file: a list of sheets, each a list of rows,
 * each row a list of cell values plus the register it is printed in (title
 * band, section band, column head, plain). MlatExportController writes that
 * model out through OpenSpout. Keeping the content separate from the writer
 * is what makes every cell testable without a spreadsheet library — and what
 * keeps the form's layout in one place should a second writer (print, PDF)
 * ever need it.
 *
 * Everything printed is read from the learner's record — the adviser's Sheet 1
 * and Sheet 2, the nurse's vital signs and examination, the baseline and
 * endline measurements — and nothing is invented for a blank: a reading nobody
 * took is an empty cell or "None", never a plausible value.
 */
class MlatWorkbook
{
    public const TITLE = "MANDATORY LEARNER'S HEALTH ASSESSMENT TOOL";

    public const SHEET_ONE = 'SHEET 1: LEARNER INFORMATION, HISTORY, AND VITAL SIGNS';

    public const SHEET_TWO = 'SHEET 2: SYSTEMS REVIEW, SCREENINGS, AND RECOMMENDATIONS';

    /** Row registers the writer styles. */
    public const R_TITLE = 'title';

    public const R_SUBTITLE = 'subtitle';

    public const R_BAND = 'band';

    public const R_HEAD = 'head';

    public const R_PLAIN = 'plain';

    /** Column count of each sheet — the merges span it. */
    public const SHEET_ONE_COLUMNS = 8;

    public const SHEET_TWO_COLUMNS = 6;

    /**
     * @return array{sheets: list<array{name: string, columns: int, widths: list<float>, rows: list<array{cells: list<string>, register: string, merge: bool}>}>, file_name: string}
     */
    public static function build(StudentHealthRecord $record, string $schoolName = ''): array
    {
        $details = is_array($record->student_details) ? $record->student_details : [];
        $history = is_array($details['health_history'] ?? null) ? $details['health_history'] : [];
        $review = is_array($details['systems_review'] ?? null) ? $details['systems_review'] : [];
        $exam = is_array($record->examination) ? $record->examination : [];
        $vitals = StudentVitalSigns::read($record);

        $name = self::learnerName($record, $details);
        $lrn = (string) $record->student_id;

        return [
            'file_name' => self::fileName($name, $lrn),
            'sheets' => [
                self::sheetOne($record, $details, $history, $exam, $vitals, $name, $lrn, $schoolName),
                self::sheetTwo($review, $exam),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $history
     * @param  array<string, mixed>  $exam
     * @param  array<string, mixed>  $vitals
     * @return array<string, mixed>
     */
    private static function sheetOne(
        StudentHealthRecord $record,
        array $details,
        array $history,
        array $exam,
        array $vitals,
        string $name,
        string $lrn,
        string $schoolName,
    ): array {
        $gradeSection = trim(
            trim((string) ($details['grade_level'] ?? '')).' - '.trim((string) ($details['section'] ?? '')),
            ' -'
        );
        if ($gradeSection === '') {
            $gradeSection = str_replace(' / ', ' - ', (string) $record->section);
        }

        $dob = self::birthDate($details);
        $age = self::text($details['age'] ?? $record->baseline_age);
        $sex = strtolower(trim((string) ($details['gender'] ?? '')));
        $sexLine = implode('   ', [
            self::box(str_starts_with($sex, 'm')).' Male',
            self::box(str_starts_with($sex, 'f')).' Female',
            self::box($sex !== '' && ! str_starts_with($sex, 'm') && ! str_starts_with($sex, 'f')).' Other',
        ]);

        $assessedBy = trim((string) ($exam['examined_by'] ?? ''));
        if ($assessedBy === '') {
            $assessedBy = trim((string) ($vitals['recorded_by'] ?? ''));
        }
        $assessedOn = self::date($exam['date_of_examination'] ?? null)
            ?: self::date($record->baseline_recorded_at)
            ?: '';

        $rows = [];
        $rows[] = self::row([self::TITLE], self::R_TITLE, true);
        $rows[] = self::row([self::SHEET_ONE], self::R_SUBTITLE, true);
        $rows[] = self::blank();

        // ── A. Learner information ──
        $rows[] = self::row(['A. LEARNER INFORMATION'], self::R_BAND, true);
        $rows[] = self::row(['Name of Learner:', $name, '', 'Learner ID / Grade & Section:', trim($lrn.' / '.$gradeSection, ' /')]);
        $rows[] = self::row(['Date of Birth:', $dob, $age !== '' ? 'Age: '.$age : '', 'School:', $schoolName !== '' ? $schoolName : (string) $record->school_name]);
        $rows[] = self::row(['Sex:', $sexLine, '', 'Date of Assessment:', $assessedOn]);
        $rows[] = self::row(['Assessed by (Name/Title):', $assessedBy]);
        $rows[] = self::blank();

        // ── B. Medical history ──
        $flag = fn (string $key): bool => (bool) ($history[$key] ?? false);
        $rows[] = self::row(['B. MEDICAL HISTORY (Check all that apply)'], self::R_BAND, true);
        $rows[] = self::row([self::box($flag('med_asthma')).' Asthma', '', '', 'Allergies: '.self::noneOr($flag('med_allergies') ? ($history['allergies_detail'] ?? 'Yes') : '')]);
        $rows[] = self::row([self::box($flag('med_diabetes')).' Diabetes', '', '', self::box($flag('med_heart')).' Heart Condition']);
        $rows[] = self::row([self::box($flag('med_seizure')).' Seizure Disorder', '', '', self::box($flag('med_tuberculosis')).' Tuberculosis']);
        $rows[] = self::row([self::box($flag('med_infections')).' Frequent Infections', '', '', 'Hospitalization/Surgery: '.self::noneOr($flag('med_hospitalization') ? ($history['hospitalization_detail'] ?? 'Yes') : '')]);
        $rows[] = self::row(['Current Medications: '.self::noneOr($history['current_medications'] ?? ''), '', '', 'Other Conditions: '.self::noneOr($history['other_conditions'] ?? '')]);
        $rows[] = self::blank();

        // ── C. Family history ──
        $rows[] = self::row(['C. FAMILY HISTORY'], self::R_BAND, true);
        $rows[] = self::row([implode('   ', [
            self::box($flag('fam_hypertension')).' Hypertension',
            self::box($flag('fam_diabetes')).' Diabetes',
            self::box($flag('fam_heart')).' Heart Disease',
            self::box($flag('fam_cancer')).' Cancer',
            self::box($flag('fam_mental')).' Mental Health',
        ])]);
        $rows[] = self::row(['Genetic/Hereditary Disorders: '.self::noneOr($history['genetic_disorders'] ?? '')]);
        $rows[] = self::blank();

        // ── D. General appearance ──
        $consciousness = self::text($history['consciousness'] ?? '');
        if ($consciousness === 'Other' && self::text($history['consciousness_other'] ?? '') !== '') {
            $consciousness .= ' — '.self::text($history['consciousness_other']);
        }
        $posture = PostureGait::normalize($history['posture'] ?? null) ?? '';
        if ($posture === PostureGait::POOR && self::text($history['posture_detail'] ?? '') !== '') {
            $posture .= ' — '.self::text($history['posture_detail']);
        }
        $rows[] = self::row(['D. GENERAL APPEARANCE'], self::R_BAND, true);
        $rows[] = self::row(['Level of Consciousness:', $consciousness]);
        $rows[] = self::row(['Posture/Gait:', $posture]);
        $rows[] = self::row(['Hygiene/Grooming:', self::text($history['hygiene'] ?? '')]);
        $rows[] = self::blank();

        // ── E. Vital signs & anthropometrics ──
        $rows[] = self::row(['E. VITAL SIGNS & ANTHROPOMETRICS (BASELINE & ENDLINE)'], self::R_BAND, true);
        $rows[] = self::row(['Evaluation Period', 'Height (cm)', 'Weight (kg)', 'BMI & Category', 'Temp (°C)', 'Pulse (bpm)', 'BP (mmHg)', 'Recorded Date'], self::R_HEAD);

        // The nurse's reading is one set of vitals on the card, taken when it
        // was taken; the two measurement rows are the adviser's. The vitals are
        // printed against the period they were recorded in, and never
        // repeated on the other row as though they had been taken twice.
        $vitalsDate = self::date($vitals['recorded_at'] ?? null);
        $baselineDate = self::date($record->baseline_recorded_at);
        $endlineDate = self::date($record->endline_recorded_at);
        $vitalsOn = $vitalsDate !== '' && $endlineDate !== '' && $vitalsDate >= $endlineDate ? 'endline' : 'baseline';

        $vitalCells = fn (string $period): array => $vitalsOn === $period
            ? [self::text($vitals['temperature_c']), self::text($vitals['pulse_bpm']), self::text($vitals['blood_pressure'])]
            : ['', '', ''];

        $baselineHeight = self::text($record->baseline_height_cm ?: ($details['height_cm'] ?? ''));
        $baselineWeight = self::text($record->baseline_weight_kg ?: ($details['weight_kg'] ?? ''));
        $baselineBmi = self::bmiCell(
            $record->baseline_bmi_value ?: ($details['bmi_value'] ?? ''),
            $record->baseline_nutritional_status ?: ($details['nutritional_status_bmi_for_age'] ?? '')
        );

        $rows[] = self::row(array_merge(
            ['Baseline Input', $baselineHeight, $baselineWeight, $baselineBmi],
            $vitalCells('baseline'),
            [$baselineDate]
        ));
        $rows[] = self::row(array_merge(
            ['Endline Input', self::text($record->endline_height_cm), self::text($record->endline_weight_kg), self::bmiCell($record->endline_bmi_value, $record->endline_nutritional_status)],
            $vitalCells('endline'),
            [$endlineDate]
        ));

        return [
            'name' => 'Sheet 1',
            'columns' => self::SHEET_ONE_COLUMNS,
            'widths' => [40, 34, 22, 26, 26, 14, 14, 16],
            'rows' => $rows,
        ];
    }

    /**
     * Sheet 2, read through Sheet2Review — the same sheet the nurse fills on
     * the Fill Medical Record form and the profile's Sheet 2 tab shows, so the
     * file and the screen cannot lay it out two ways.
     *
     * @param  array<string, mixed>  $review
     * @param  array<string, mixed>  $exam
     * @return array<string, mixed>
     */
    private static function sheetTwo(array $review, array $exam): array
    {
        $sheet = Sheet2Review::read($exam, $review);

        $rows = [];
        $rows[] = self::row([self::TITLE], self::R_TITLE, true);
        $rows[] = self::row([self::SHEET_TWO], self::R_SUBTITLE, true);
        $rows[] = self::blank();

        // ── F. Body systems ──
        $rows[] = self::row(['F. EVALUATION OF BODY SYSTEMS'], self::R_BAND, true);
        $rows[] = self::row(['Body System', 'Findings (Check / Status)', 'Notes / Details'], self::R_HEAD);
        foreach ($sheet['systems'] as $system) {
            $rows[] = self::row([$system['label'], $system['finding'], $system['notes']]);
        }
        $rows[] = self::blank();

        // ── G. Vision and hearing ──
        $visionLine = trim(
            ($sheet['vision']['right'] !== '' ? 'Right Eye: '.$sheet['vision']['right'] : '')
            .'   '
            .($sheet['vision']['left'] !== '' ? 'Left Eye: '.$sheet['vision']['left'] : '')
        );
        $rows[] = self::row(['G. VISION AND HEARING SCREENING'], self::R_BAND, true);
        $rows[] = self::row(['Vision:', $visionLine, '', self::resultLine($sheet['vision']['result'])]);
        $rows[] = self::row(['Hearing:', self::resultLine($sheet['hearing']['result'])]);
        $rows[] = self::blank();

        // ── H. Oral health ──
        $rows[] = self::row(['H. ORAL HEALTH EXAMINATION'], self::R_BAND, true);
        $rows[] = self::row([
            'Teeth Condition: '.$sheet['oral']['teeth'],
            '',
            '',
            'Last Dental Visit: '.($sheet['oral']['last_visit'] !== '' ? $sheet['oral']['last_visit'] : 'N/A'),
        ]);
        $rows[] = self::row(['Referral: '.$sheet['oral']['referral']]);
        $rows[] = self::blank();

        // ── I. Immunization ──
        $rows[] = self::row(['I. IMMUNIZATION STATUS'], self::R_BAND, true);
        $rows[] = self::row([
            'Status: '.$sheet['immunization']['status'],
            '',
            'Missing/Needed Vaccines: '.self::noneOr($sheet['immunization']['missing']),
            '',
            'Date Record Reviewed: '.$sheet['immunization']['reviewed_at'],
        ]);
        $rows[] = self::blank();

        // ── J. Summary and recommendations ──
        $rows[] = self::row(['J. ASSESSMENT SUMMARY AND RECOMMENDATIONS'], self::R_BAND, true);
        $rows[] = self::row(['Summary of Findings:', $sheet['summary']['findings']]);
        $rows[] = self::blank();
        $rows[] = self::row(['Recommendations / Referrals:', $sheet['summary']['recommendations']]);
        $rows[] = self::blank();
        $rows[] = self::row(['Examiner Signature / Name:', $sheet['summary']['examiner'], '', 'Date:', $sheet['summary']['date']]);

        return [
            'name' => 'Sheet 2',
            'columns' => self::SHEET_TWO_COLUMNS,
            'widths' => [34, 38, 34, 26, 26, 12],
            'rows' => $rows,
        ];
    }

    // ── Cell helpers ───────────────────────────────────────────────────

    /**
     * @param  list<string>  $cells
     * @return array{cells: list<string>, register: string, merge: bool}
     */
    private static function row(array $cells, string $register = self::R_PLAIN, bool $merge = false): array
    {
        return ['cells' => array_map(fn ($c): string => (string) $c, $cells), 'register' => $register, 'merge' => $merge];
    }

    /** @return array{cells: list<string>, register: string, merge: bool} */
    private static function blank(): array
    {
        return self::row(['']);
    }

    /** The form's tick box, as the clinic types it. */
    public static function box(bool $on): string
    {
        return $on ? '[ ✓ ]' : '[   ]';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function noneOr(mixed $value): string
    {
        $text = self::text($value);

        return $text !== '' ? $text : 'None';
    }

    private static function resultLine(mixed $value): string
    {
        $text = self::text($value);

        return $text !== '' ? 'Result: '.$text : '';
    }

    private static function bmiCell(mixed $bmi, mixed $status): string
    {
        $bmiText = self::text($bmi);
        $statusText = self::text($status);

        if ($bmiText === '' && $statusText === '') {
            return '';
        }
        if ($bmiText === '') {
            return $statusText;
        }

        return $statusText !== '' ? $bmiText.' ('.$statusText.')' : $bmiText;
    }

    private static function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = self::text($value);
        if ($text === '') {
            return '';
        }

        try {
            return Carbon::parse($text)->format('Y-m-d');
        } catch (\Throwable) {
            return $text;
        }
    }

    /** @param  array<string, mixed>  $details */
    private static function birthDate(array $details): string
    {
        $y = (int) ($details['birth_year'] ?? 0);
        $m = (int) ($details['birth_month'] ?? 0);
        $d = (int) ($details['birth_day'] ?? 0);

        if ($y <= 0 || $m <= 0 || $d <= 0) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** @param  array<string, mixed>  $details */
    private static function learnerName(StudentHealthRecord $record, array $details): string
    {
        $first = self::text($details['first_name'] ?? '');
        $middle = self::text($details['middle_name'] ?? '');
        $last = self::text($details['last_name'] ?? '');

        if ($first === '' && $last === '') {
            return self::text($record->student_name);
        }

        // "First M. Last", as the clinic's form writes it.
        $initial = $middle !== '' ? ' '.mb_strtoupper(mb_substr($middle, 0, 1)).'.' : '';

        return trim($first.$initial.' '.$last);
    }

    private static function fileName(string $name, string $lrn): string
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-');

        return 'MLAT-'.($slug !== '' ? $slug : $lrn).'.xlsx';
    }
}
