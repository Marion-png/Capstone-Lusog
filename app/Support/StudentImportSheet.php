<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * A class list as a spreadsheet, read into enrolment rows.
 *
 * The adviser's alternative to typing Sheet 1 forty times: one CSV or XLSX,
 * one row per learner, the columns named as the form names them. The header
 * row is matched by name rather than by position (advisers reorder columns,
 * and a list exported from another system will not be in ours), and a header
 * this reader does not recognise is ignored rather than refused.
 *
 * This is a reader, not a writer. Each row comes back shaped like the
 * enrolment form's own post, and AdviserController::import runs every one
 * through exactly the validation and the write the single-learner form uses —
 * a spreadsheet must not become a way around the rules the form applies.
 *
 * CSV is read natively; XLSX through OpenSpout. The first worksheet holds the
 * list, as it does for the attendance sheet.
 */
class StudentImportSheet
{
    /** Rows beyond this are refused rather than read: a class is not a school. */
    public const MAX_ROWS = 300;

    /**
     * The form field each recognised header fills, keyed by the header as it
     * compares after normalisation (lower-case, letters and digits only).
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'lrn' => 'lrn',
        'learnerreferencenumber' => 'lrn',
        'lastname' => 'last_name',
        'surname' => 'last_name',
        'familyname' => 'last_name',
        'firstname' => 'first_name',
        'givenname' => 'first_name',
        'middlename' => 'middle_name',
        'middleinitial' => 'middle_name',
        'birthdate' => 'birth_date',
        'dateofbirth' => 'birth_date',
        'birthday' => 'birth_date',
        'birthplace' => 'birthplace',
        'placeofbirth' => 'birthplace',
        'gender' => 'gender',
        'sex' => 'gender',
        'parentguardian' => 'parent_guardian',
        'parent' => 'parent_guardian',
        'guardian' => 'parent_guardian',
        'parentorguardian' => 'parent_guardian',
        'address' => 'address',
        'homeaddress' => 'address',
        'contactno' => 'telephone_no',
        'contactnumber' => 'telephone_no',
        'telephoneno' => 'telephone_no',
        'telephone' => 'telephone_no',
        'phone' => 'telephone_no',
        'mobile' => 'telephone_no',
        'heightcm' => 'height_cm',
        'height' => 'height_cm',
        'weightkg' => 'weight_kg',
        'weight' => 'weight_kg',
    ];

    /** The fields a row must carry for the reader to hand it on at all. */
    public const REQUIRED_HEADERS = ['lrn', 'last_name', 'first_name'];

    /**
     * The band rows a DepEd class masterlist splits its list with.
     *
     * The sheet has no Sex column: it lists the boys under a row reading MALE
     * and the girls under one reading FEMALE, and restarts the numbering at
     * each. So the band is where a learner's sex is recorded, and a reader
     * that skipped it as an empty row would drop the only copy of it on the
     * sheet — which every DepEd BMI grid then counts by.
     *
     * @var array<string, string>
     */
    private const SEX_BANDS = [
        'male' => 'Male',
        'female' => 'Female',
    ];

    /**
     * @return array{rows: list<array{line: int, data: array<string, string>}>, missing: list<string>, total: int}
     */
    public static function read(UploadedFile $file): array
    {
        $rows = [];
        $missing = [];
        $read = false;

        // **Every worksheet, not just the first.** A school that keeps its
        // class masterlist with one tab per sex — a MALE sheet and a FEMALE
        // sheet — had half its class silently dropped by a reader that stopped
        // after the first, and a workbook whose first tab is a cover page
        // imported nobody at all. Each tab heads its own list, so the header is
        // found again per tab rather than once for the file.
        foreach (self::readSheets($file) as $sheet) {
            $result = self::readSheet($sheet['rows'], $sheet['name'], count($rows));

            if ($result['missing'] !== []) {
                // Remember why the first unreadable tab failed, in case no tab
                // turns out to hold a list — a cover page or a summary tab is
                // not an error as long as the learners are on another one.
                $missing = $missing === [] ? $result['missing'] : $missing;

                continue;
            }

            $read = true;
            $rows = array_merge($rows, $result['rows']);
        }

        if (! $read) {
            return ['rows' => [], 'missing' => $missing !== [] ? $missing : self::REQUIRED_HEADERS, 'total' => 0];
        }

        return ['rows' => $rows, 'missing' => [], 'total' => count($rows)];
    }

    /**
     * One worksheet, read into enrolment rows.
     *
     * @param  list<list<string>>  $matrix
     * @param  string  $sheetName  the tab's own name, which on a masterlist
     *                             split by sex is where that sex is recorded
     * @param  int  $lineOffset  rows already read from earlier tabs, so a
     *                           reported line is unique across the workbook
     * @return array{rows: list<array{line: int, data: array<string, string>}>, missing: list<string>}
     */
    private static function readSheet(array $matrix, string $sheetName, int $lineOffset): array
    {
        // The header row is the first row with a recognisable LRN column.
        $headerIndex = null;
        $columns = [];
        foreach ($matrix as $index => $cells) {
            $mapped = self::mapHeader($cells);
            if (in_array('lrn', $mapped, true)) {
                $headerIndex = $index;
                $columns = $mapped;
                break;
            }
        }

        if ($headerIndex === null) {
            return ['rows' => [], 'missing' => self::REQUIRED_HEADERS];
        }

        // A masterlist names the three columns once, in a merged caption —
        // which on the school's own sheet sits on a line of its own.
        [$columns, $headerIndex] = self::readNameColumns($matrix, $headerIndex, $columns);

        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $columns));
        if ($missing !== []) {
            return ['rows' => [], 'missing' => $missing];
        }

        $rows = [];
        // The sex of the band the reader is currently inside, if the sheet
        // uses them. Empty until a band row says otherwise, so a sheet with a
        // Sex column of its own is unaffected.
        //
        // A tab named MALE or FEMALE is a band too — the same fact recorded in
        // the tab rather than in a row, and the only copy of it on a workbook
        // split that way. A band row inside the tab still wins, because it is
        // the more specific statement.
        $band = self::sexFromSheetName($sheetName) ?? '';

        foreach (array_slice($matrix, $headerIndex + 1, null, true) as $index => $cells) {
            $data = [];
            foreach ($columns as $position => $field) {
                if ($field === null) {
                    continue;
                }
                $data[$field] = trim((string) ($cells[$position] ?? ''));
            }

            // Read before the blank-row test below, which a band row would
            // otherwise fall through: the band carries no learner of its own,
            // so none of the mapped columns has anything in it.
            $sex = self::sexBandIn($cells);
            if ($sex !== null) {
                $band = $sex;

                continue;
            }

            // A blank line — a trailing row Excel keeps, a spacer, the
            // adviser's signature under the list — is not a learner.
            if (implode('', $data) === '') {
                continue;
            }

            // The band fills the sheet's own Sex column only where it is
            // empty: a sheet that states a learner's sex outright outranks the
            // heading they happen to be listed under.
            if ($band !== '' && trim((string) ($data['gender'] ?? '')) === '') {
                $data['gender'] = $band;
            }

            $rows[] = ['line' => $lineOffset + $index + 1, 'data' => $data];
        }

        return ['rows' => $rows, 'missing' => []];
    }

    /**
     * The sex a worksheet's own name records, if it records one.
     *
     * Read off the same list the band rows are (`SEX_BANDS`), so a tab called
     * "FEMALE" and a row reading "FEMALE" mean the same thing. A tab named
     * anything else — "Sheet1", "Class List", "Grade 7 Matiyaga" — records no
     * sex, and its learners keep whatever the sheet itself says.
     */
    private static function sexFromSheetName(string $name): ?string
    {
        $key = strtolower(trim($name));

        return self::SEX_BANDS[$key] ?? null;
    }

    /**
     * The name columns, and the row the learners start after.
     *
     * A masterlist's header is stacked over two rows: NO, LRN and REMARKS are
     * merged down the pair, and the name columns carry a band heading on the
     * first line with the merged caption on the second. So the row this reader
     * finds LRN on is not always the row that names the learner's name, and a
     * reader that looked only at the first reported the school's own sheet as
     * having no Last Name or First Name column — a refusal of the one document
     * every class already holds.
     *
     * The caption row is consumed along with the heading when it is found,
     * because it is a heading: left in the list it would be read as a learner
     * whose surname is "Student's Name (Last Name, First Name Middle Initial)".
     *
     * Only a row that IS a caption is ever consumed. expandMergedNameCaption
     * fires on a cell naming both a last and a first name, so a learner row
     * leaves the columns exactly as they were and the sheet is reported
     * unreadable instead — which is the honest answer.
     *
     * @param  list<list<string>>  $matrix
     * @param  list<string|null>  $columns
     * @return array{0: list<string|null>, 1: int}
     */
    private static function readNameColumns(array $matrix, int $headerIndex, array $columns): array
    {
        $expanded = self::expandMergedNameCaption($matrix[$headerIndex] ?? [], $columns);

        if (in_array('last_name', $expanded, true)) {
            return [$expanded, $headerIndex];
        }

        $below = $matrix[$headerIndex + 1] ?? null;

        if ($below === null) {
            return [$expanded, $headerIndex];
        }

        $stacked = self::expandMergedNameCaption($below, $columns);

        return in_array('last_name', $stacked, true)
            ? [$stacked, $headerIndex + 1]
            : [$expanded, $headerIndex];
    }

    /**
     * A masterlist writes the name columns as one merged caption.
     *
     * The DepEd CLASS MASTERLIST heads its three name columns with a single
     * merged cell — "Student's Name (Last Name, First Name Middle Initial)"
     * — so a reader matching headings one cell at a time sees that caption,
     * recognises none of it, and reports the sheet as having no Last Name or
     * First Name column at all. That is a refusal of the one document every
     * school already has.
     *
     * A merged cell hands its value to its top-left position and leaves the
     * rest of the span empty, which is exactly the shape looked for here: a
     * caption naming both a last and a first name, followed by blank headings.
     * The caption's own column is the last name and the blanks after it are
     * the first and middle, in the order the caption itself lists them.
     *
     * Only ever used to fill a gap. A sheet that names its columns separately
     * has already mapped them and is left untouched, and a caption that is
     * *not* followed by blanks is not a merge — it is a single name column
     * this reader deliberately does not try to split, because "DELA CRUZ JUAN
     * P." cannot be divided into a surname and a given name without guessing
     * which words belong to which.
     *
     * @param  list<string>  $header  the header row, as read
     * @param  list<string|null>  $columns  what mapHeader() made of it
     * @return list<string|null>
     */
    private static function expandMergedNameCaption(array $header, array $columns): array
    {
        if (in_array('last_name', $columns, true) || in_array('first_name', $columns, true)) {
            return $columns;
        }

        foreach ($header as $position => $cell) {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $cell)) ?? '';

            if (! str_contains($key, 'lastname') || ! str_contains($key, 'firstname')) {
                continue;
            }

            // The span is the caption plus however many blank headings follow
            // it, up to the two the name needs.
            $span = ['last_name', 'first_name', 'middle_name'];
            $filled = 0;

            foreach ($span as $offset => $field) {
                $at = $position + $offset;

                // The caption's own cell, then only genuinely empty ones: a
                // heading with text in it belongs to another column.
                if ($offset > 0 && trim((string) ($header[$at] ?? '')) !== '') {
                    break;
                }

                // Never overwrite a column that already mapped to something.
                if (($columns[$at] ?? null) !== null && $offset > 0) {
                    break;
                }

                $columns[$at] = $field;
                $filled++;
            }

            // One column on its own is a full name, not a surname. Put it back
            // rather than reading every learner's given names as their family
            // name — the caller then reports the sheet as unreadable, which is
            // the honest answer.
            if ($filled < 2) {
                $columns[$position] = null;

                continue;
            }

            break;
        }

        return $columns;
    }

    /**
     * The sex a band row declares, or null where the row is not one.
     *
     * A band is a row that says MALE or FEMALE and nothing else — whichever
     * column it happens to sit in, since the masterlist indents it under the
     * numbering. A row carrying a learner as well is never a band, so a sheet
     * whose first learner is surnamed "Male" is read as a learner.
     *
     * @param  list<string>  $cells
     */
    private static function sexBandIn(array $cells): ?string
    {
        $words = array_values(array_filter(
            array_map(fn ($cell): string => trim((string) $cell), $cells),
            fn (string $cell): bool => $cell !== ''
        ));

        if (count($words) !== 1) {
            return null;
        }

        return self::SEX_BANDS[strtolower($words[0])] ?? null;
    }

    /**
     * @param  list<string>  $cells
     * @return list<string|null>
     */
    private static function mapHeader(array $cells): array
    {
        return array_map(function (string $cell): ?string {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower($cell)) ?? '';
            // "Birth Date (YYYY-MM-DD)" → birthdate; "Height (cm)" → heightcm.
            $key = preg_replace('/yyyymmdd$/', '', $key) ?? $key;

            return self::HEADERS[$key] ?? null;
        }, $cells);
    }

    /**
     * @return list<list<string>>
     */
    /**
     * The file as worksheets, each with its own name.
     *
     * A CSV is one unnamed sheet; an XLSX is however many tabs it has, because
     * a school may split its class masterlist across them.
     *
     * @return list<array{name: string, rows: list<list<string>>}>
     */
    private static function readSheets(UploadedFile $file): array
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: ''));

        return in_array($extension, ['xlsx', 'xls'], true)
            ? self::readXlsx($file)
            : [['name' => '', 'rows' => self::readCsv($file)]];
    }

    /** @return list<list<string>> */
    private static function readCsv(UploadedFile $file): array
    {
        $handle = fopen((string) $file->getRealPath(), 'rb');
        if ($handle === false) {
            return [];
        }

        $matrix = [];
        try {
            while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (count($matrix) > self::MAX_ROWS + 1) {
                    break;
                }
                $matrix[] = array_map(
                    fn ($value): string => trim(str_replace("\xEF\xBB\xBF", '', (string) $value)),
                    $cells
                );
            }
        } finally {
            fclose($handle);
        }

        return $matrix;
    }

    /**
     * Every worksheet in the workbook, in order, each with its name.
     *
     * It used to stop after the first, which dropped the whole second half of
     * a class kept as a MALE tab and a FEMALE tab. MAX_ROWS is counted over
     * the workbook rather than per tab, so a huge file is still bounded.
     *
     * @return list<array{name: string, rows: list<list<string>>}>
     */
    private static function readXlsx(UploadedFile $file): array
    {
        $reader = new XlsxReader;
        $sheets = [];
        $total = 0;
        $reader->open((string) $file->getRealPath());

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $matrix = [];

                foreach ($sheet->getRowIterator() as $row) {
                    if ($total > self::MAX_ROWS + 1) {
                        break;
                    }
                    $cells = [];
                    foreach ($row->toArray() as $value) {
                        if ($value instanceof \DateTimeInterface) {
                            $value = $value->format('Y-m-d');
                        }
                        $cells[] = trim((string) $value);
                    }
                    $matrix[] = $cells;
                    $total++;
                }

                $sheets[] = ['name' => (string) $sheet->getName(), 'rows' => $matrix];

                if ($total > self::MAX_ROWS + 1) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }
}
