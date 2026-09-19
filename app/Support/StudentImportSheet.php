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

    /** The template's columns, in the order the form asks for them. */
    public const TEMPLATE_COLUMNS = [
        'LRN',
        'Last Name',
        'First Name',
        'Middle Name',
        'Birth Date (YYYY-MM-DD)',
        'Birthplace',
        'Gender',
        'Parent/Guardian',
        'Address',
        'Contact No.',
        'Height (cm)',
        'Weight (kg)',
    ];

    /** The fields a row must carry for the reader to hand it on at all. */
    public const REQUIRED_HEADERS = ['lrn', 'last_name', 'first_name'];

    /**
     * @return array{rows: list<array{line: int, data: array<string, string>}>, missing: list<string>, total: int}
     */
    public static function read(UploadedFile $file): array
    {
        $matrix = self::readMatrix($file);

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
            return ['rows' => [], 'missing' => self::REQUIRED_HEADERS, 'total' => 0];
        }

        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $columns));
        if ($missing !== []) {
            return ['rows' => [], 'missing' => $missing, 'total' => 0];
        }

        $rows = [];
        foreach (array_slice($matrix, $headerIndex + 1, null, true) as $index => $cells) {
            $data = [];
            foreach ($columns as $position => $field) {
                if ($field === null) {
                    continue;
                }
                $data[$field] = trim((string) ($cells[$position] ?? ''));
            }

            // A blank line — a trailing row Excel keeps, a spacer — is not a learner.
            if (implode('', $data) === '') {
                continue;
            }

            $rows[] = ['line' => $index + 1, 'data' => $data];
        }

        return ['rows' => $rows, 'missing' => [], 'total' => count($rows)];
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
    private static function readMatrix(UploadedFile $file): array
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: ''));

        return in_array($extension, ['xlsx', 'xls'], true)
            ? self::readXlsx($file)
            : self::readCsv($file);
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

    /** @return list<list<string>> */
    private static function readXlsx(UploadedFile $file): array
    {
        $reader = new XlsxReader;
        $matrix = [];
        $reader->open((string) $file->getRealPath());

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    if (count($matrix) > self::MAX_ROWS + 1) {
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
                }

                break; // The first worksheet holds the list.
            }
        } finally {
            $reader->close();
        }

        return $matrix;
    }
}
