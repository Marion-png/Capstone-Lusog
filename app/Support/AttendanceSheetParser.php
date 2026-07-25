<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads an uploaded SBFP feeding-attendance sheet (CSV or XLSX) and turns it
 * into a structured roster + per-session present/absent grid.
 *
 * The sheet follows the DepEd "List of Identified Severely Wasted and Wasted
 * Students" layout: optional title rows, then a header row containing NAME /
 * GRADE / SECTION, then one column per feeding-session date. Attendance cells
 * are marked present (H, M, H/M, P, /, ✓ …) or absent (A, blank, …).
 */
class AttendanceSheetParser
{
    /**
     * @return array{
     *   sessions: list<array{label: string, date: string}>,
     *   students: list<array{name: string, grade: string, section: string, present: array<int, bool>}>,
     *   undated_columns: list<string>,
     *   error?: string
     * }
     */
    public function parse(UploadedFile $file): array
    {
        return $this->interpret($this->readMatrix($file));
    }

    /**
     * A present mark is anything that reads as "was served / attended":
     * the DepEd feeding codes start with H (hot meal) or M (milk), plus the
     * common tick conventions. Everything else — A, blank, x, 0 — is absent.
     */
    public static function isPresentMark(string $value): bool
    {
        $value = strtoupper(trim($value));

        if ($value === '') {
            return false;
        }

        if ($value[0] === 'H' || $value[0] === 'M') {
            return true;
        }

        return in_array($value, ['P', 'PRESENT', '1', 'YES', 'Y', 'TRUE', '/', '✓', '✔'], true);
    }

    /**
     * @return list<list<string>>
     */
    private function readMatrix(UploadedFile $file): array
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: ''));
        $reader = in_array($extension, ['xlsx', 'xls'], true) ? new XlsxReader : new CsvReader;

        $matrix = [];
        $reader->open((string) $file->getRealPath());

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    foreach ($row->toArray() as $value) {
                        if ($value instanceof \DateTimeInterface) {
                            $value = $value->format('Y-m-d');
                        }
                        $cells[] = trim((string) $value);
                    }
                    $matrix[] = $cells;
                }

                break; // Only the first worksheet holds the attendance grid.
            }
        } finally {
            $reader->close();
        }

        return $matrix;
    }

    /**
     * @param  list<list<string>>  $matrix
     */
    private function interpret(array $matrix): array
    {
        [$headerRowIndex, $nameCol, $gradeCol, $sectionCol] = $this->locateHeader($matrix);

        if ($headerRowIndex === null) {
            return [
                'sessions' => [],
                'students' => [],
                'undated_columns' => [],
                'error' => 'Could not find a header row with a NAME column in the uploaded file.',
            ];
        }

        $afterCol = $sectionCol ?? $gradeCol ?? $nameCol;

        // Candidate session columns: every column to the right of the roster
        // columns that has a header. We try to read the header as a date, but a
        // non-date header no longer discards the column — the marks are what
        // matter, and a fallback date is assigned later. This is what lets a
        // sheet whose headers are "Oct 8", "Day 1", "1", etc. still work.
        $sessionColumns = [];
        foreach ($matrix[$headerRowIndex] as $col => $label) {
            if ($col <= $afterCol) {
                continue;
            }
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $sessionColumns[] = ['col' => $col, 'label' => $label, 'date' => $this->parseDate($label)];
        }

        $students = $this->readStudents($matrix, $headerRowIndex, $nameCol, $gradeCol, $sectionCol, $sessionColumns);

        // A column counts as a real feeding session only when it has marks. A
        // dated header is trusted outright; an undated column must actually look
        // like attendance (P/A/H/M/…) so stray "Remarks"/"Total" columns with
        // free text are never mistaken for a session.
        $conducted = array_values(array_filter(
            array_keys($sessionColumns),
            function (int $index) use ($sessionColumns, $students): bool {
                if (! $this->columnHasData($students, $index)) {
                    return false;
                }

                return $sessionColumns[$index]['date'] !== null
                    || $this->columnLooksLikeAttendance($students, $index);
            }
        ));

        return $this->finalize($sessionColumns, $students, $conducted);
    }

    /**
     * @param  list<list<string>>  $matrix
     * @return array{0: int|null, 1: int|null, 2: int|null, 3: int|null}
     */
    private function locateHeader(array $matrix): array
    {
        foreach ($matrix as $rowIndex => $row) {
            // Prefer a cell that is exactly "NAME"; otherwise accept one that
            // contains it ("NAME OF LEARNER", "LEARNER'S NAME", …).
            $nameCol = null;
            $nameColLoose = null;
            foreach ($row as $col => $value) {
                $normalized = strtoupper(trim((string) $value));
                if ($normalized === 'NAME') {
                    $nameCol = $col;

                    break;
                }
                if ($nameColLoose === null && str_contains($normalized, 'NAME') && ! str_contains($normalized, 'SURNAME')) {
                    $nameColLoose = $col;
                }
            }

            $nameCol ??= $nameColLoose;

            if ($nameCol === null) {
                continue;
            }

            $gradeCol = null;
            $sectionCol = null;
            foreach ($row as $col => $value) {
                $normalized = strtoupper(trim((string) $value));
                if ($gradeCol === null && str_contains($normalized, 'GRADE')) {
                    $gradeCol = $col;
                }
                if ($sectionCol === null && str_contains($normalized, 'SECTION')) {
                    $sectionCol = $col;
                }
            }

            return [$rowIndex, $nameCol, $gradeCol, $sectionCol];
        }

        return [null, null, null, null];
    }

    /**
     * @param  list<list<string>>  $matrix
     * @param  list<array{col: int, label: string, date: string}>  $sessionColumns
     * @return list<array{name: string, grade: string, section: string, cells: array<int, array{raw: string, present: bool}>}>
     */
    private function readStudents(array $matrix, int $headerRowIndex, int $nameCol, ?int $gradeCol, ?int $sectionCol, array $sessionColumns): array
    {
        $students = [];
        $started = false;

        for ($rowIndex = $headerRowIndex + 1; $rowIndex < count($matrix); $rowIndex++) {
            $row = $matrix[$rowIndex];
            $name = trim((string) ($row[$nameCol] ?? ''));

            if ($name === '') {
                if ($started) {
                    break; // Blank NAME after data — end of the roster.
                }

                continue;
            }

            // Signature/footer block — the marker ("Prepared by:", "Noted by:",
            // …) may sit in any column of the row, not just under NAME.
            $rowText = strtoupper(implode(' ', array_map(fn ($v) => (string) $v, $row)));
            if (str_contains($rowText, 'PREPARED BY')
                || str_contains($rowText, 'NOTED BY')
                || str_contains($rowText, 'VERIFIED BY')
                || str_contains($rowText, 'APPROVED BY')
                || str_contains($rowText, 'ATTESTED BY')) {
                break;
            }
            if (strtoupper($name) === 'NAME') {
                continue; // A repeated header row.
            }

            $started = true;

            $cells = [];
            foreach ($sessionColumns as $index => $session) {
                $raw = trim((string) ($row[$session['col']] ?? ''));
                $cells[$index] = ['raw' => $raw, 'present' => self::isPresentMark($raw)];
            }

            $students[] = [
                'name' => $name,
                'grade' => $gradeCol !== null ? trim((string) ($row[$gradeCol] ?? '')) : '',
                'section' => $sectionCol !== null ? trim((string) ($row[$sectionCol] ?? '')) : '',
                'cells' => $cells,
            ];
        }

        return $students;
    }

    private function columnHasData(array $students, int $sessionIndex): bool
    {
        foreach ($students as $student) {
            if (($student['cells'][$sessionIndex]['raw'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a column's cells read like attendance (present or absent
     * tokens) rather than free text — used to keep undated "Remarks"/"Total"
     * columns from being mistaken for a feeding session.
     */
    private function columnLooksLikeAttendance(array $students, int $sessionIndex): bool
    {
        foreach ($students as $student) {
            $raw = $student['cells'][$sessionIndex]['raw'] ?? '';
            if ($raw !== '' && self::isAttendanceToken($raw)) {
                return true;
            }
        }

        return false;
    }

    private static function isAttendanceToken(string $value): bool
    {
        if (self::isPresentMark($value)) {
            return true;
        }

        return in_array(strtoupper(trim($value)), ['A', 'ABSENT', '0', 'X', 'NO', 'N', '-'], true);
    }

    /**
     * @param  list<array{col: int, label: string, date: string|null}>  $sessionColumns
     * @param  list<int>  $conducted
     */
    private function finalize(array $sessionColumns, array $students, array $conducted): array
    {
        // Real header dates are used as-is; any conducted column without a
        // readable date gets a recent placeholder date so its attendance still
        // counts. Placeholders never collide with a real date already in use.
        $usedDates = [];
        foreach ($conducted as $oldIndex) {
            $date = $sessionColumns[$oldIndex]['date'];
            if ($date !== null) {
                $usedDates[$date] = true;
            }
        }

        $sessions = [];
        $indexMap = [];
        $undated = [];
        $fallbackOffset = 0;
        foreach ($conducted as $oldIndex) {
            $date = $sessionColumns[$oldIndex]['date'];
            if ($date === null) {
                do {
                    $candidate = Carbon::today()->subDays($fallbackOffset)->format('Y-m-d');
                    $fallbackOffset++;
                } while (isset($usedDates[$candidate]));
                $usedDates[$candidate] = true;
                $date = $candidate;
                $undated[] = $sessionColumns[$oldIndex]['label'];
            }

            $indexMap[$oldIndex] = count($sessions);
            $sessions[] = [
                'label' => $sessionColumns[$oldIndex]['label'],
                'date' => $date,
            ];
        }

        $finalStudents = [];
        foreach ($students as $student) {
            $present = [];
            foreach ($indexMap as $oldIndex => $newIndex) {
                $present[$newIndex] = (bool) ($student['cells'][$oldIndex]['present'] ?? false);
            }

            $finalStudents[] = [
                'name' => $student['name'],
                'grade' => $student['grade'],
                'section' => $student['section'],
                'present' => $present,
            ];
        }

        return [
            'sessions' => $sessions,
            'students' => $finalStudents,
            'undated_columns' => $undated,
        ];
    }

    private function parseDate(string $label): ?string
    {
        $label = trim($label);

        // Require something date-shaped: a month name, an m/d, or a 4-digit
        // year — so plain grade numbers or section names never parse as dates.
        $looksDated = preg_match('/[A-Za-z]{3,}/', $label)
            || preg_match('~\d{1,2}\s*[/-]\s*\d{1,2}~', $label)
            || preg_match('/\d{4}/', $label);

        if (! $looksDated) {
            return null;
        }

        try {
            return Carbon::parse($label)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
