<?php

namespace App\Support;

/**
 * The DepEd CLASS MASTERLIST, as the blank sheet an adviser fills in.
 *
 * The import used to hand out a flat twelve-column CSV of its own invention —
 * LRN, Last Name, First Name, Birth Date, Birthplace, Guardian, Address,
 * Contact, Height, Weight — a sheet no school has and every adviser would have
 * had to build. The document a class actually holds at the start of a year is
 * the CLASS MASTERLIST: the school and its address, the grade and section, the
 * school year, then `NO | Student's Name | LRN | REMARKS` with the boys listed
 * under a row reading MALE and the girls under one reading FEMALE, and the
 * adviser's signature underneath. That is the sheet this writes, so the
 * template an adviser downloads and the file they already keep are the same
 * document — which is the whole point of offering a template at all.
 *
 * It builds a **model**, not a file: rows, the register each is printed in, the
 * merges the form's heading needs and the column widths. AdviserController
 * writes that out through OpenSpout. Keeping the layout separate from the
 * writer is what makes every cell testable without a spreadsheet library.
 *
 * Two details of the form are deliberate rather than accidental, because
 * App\Support\StudentImportSheet reads this exact shape back:
 *
 *  - The three name columns are headed by **one merged caption**, on its own
 *    line under the headings that span both rows. A merged cell hands its value
 *    to its top-left position and leaves the span empty, so the caption's own
 *    column is the last name and the two blanks after it are the first and the
 *    middle.
 *  - There is **no Sex column**. The MALE and FEMALE band rows are where the
 *    sheet records it, and the reader gives a band's sex to every learner
 *    beneath it — which is what every DepEd BMI grid then counts by.
 *
 * Nothing on the blank form is personal information: it carries the school, the
 * class, the year and the adviser's own name, and not one fact about a child.
 */
final class ClassMasterlistTemplate
{
    public const SHEET_NAME = 'MASTERLIST';

    public const TITLE = 'CLASS MASTERLIST';

    /** The second title line, as the school's own sheet repeats it. */
    public const SUBTITLE = 'MASTERLIST';

    /**
     * The one caption over the three name columns.
     *
     * Kept verbatim from the school's sheet: StudentImportSheet recognises a
     * caption by its naming both a last and a first name, so the wording here
     * and the wording it matches on have to stay in step.
     */
    public const NAME_CAPTION = "Student's Name (Last Name, First Name Middle Initial)";

    /** NO | last | first | middle | LRN | REMARKS. */
    public const COLUMNS = 6;

    /**
     * The bands the list is split into, in the order the form prints them.
     *
     * The first also heads the name columns on the school's own sheet, above
     * the caption — an artefact of how the form was built, reproduced here
     * because this is that form. The reader ignores it: a band is a row that
     * says MALE or FEMALE and nothing else, and the heading row says three
     * other things beside it.
     */
    public const BANDS = ['MALE', 'FEMALE'];

    /**
     * Blank numbered lines under each band.
     *
     * A class is around forty learners split between the two, and the printed
     * form always carries spare lines to write into by hand — a template that
     * runs out of rows is a template somebody has to repair before using.
     */
    public const BAND_ROWS = 25;

    public const SIGNATORY_LABEL = 'ADVISER';

    /** Row registers the writer styles. */
    public const R_SCHOOL = 'school';

    public const R_HEADING = 'heading';

    public const R_CLASS = 'class';

    public const R_HEAD = 'head';

    public const R_BAND = 'band';

    public const R_LINE = 'line';

    public const R_PLAIN = 'plain';

    /** Column widths, in the order the form sets them. */
    public const WIDTHS = [5, 20, 18, 9, 17, 26];

    /**
     * The blank form for one class.
     *
     * @param  array<string, string>  $letterhead  App\Support\SchoolLetterhead::for()
     * @return array{rows: list<array{cells: list<string>, register: string}>, merges: list<array{0: int, 1: int, 2: int, 3: int}>, widths: list<int>, file_name: string}
     */
    public static function build(array $letterhead, string $classLabel, string $schoolYear, string $adviser): array
    {
        $rows = [];
        $merges = [];

        // Returns the 1-indexed row number just written, which is what a merge
        // is declared against.
        $write = static function (array $cells, string $register) use (&$rows): int {
            $rows[] = [
                'cells' => array_pad(array_map(static fn ($cell): string => (string) $cell, $cells), self::COLUMNS, ''),
                'register' => $register,
            ];

            return count($rows);
        };

        // The heading block, each line centred across the whole form. The
        // school and its address are read through SchoolLetterhead, so this
        // sheet heads the same school the printed DepEd forms do — and a school
        // with no address on file gets an empty line to write on rather than a
        // neighbouring school's street.
        $banner = [
            [$letterhead['school'] ?? '', self::R_SCHOOL],
            [$letterhead['address'] ?? '', self::R_HEADING],
            [self::TITLE, self::R_HEADING],
            [$classLabel, self::R_CLASS],
            [self::SUBTITLE, self::R_HEADING],
            ['School Year: '.self::schoolYearLine($schoolYear), self::R_HEADING],
        ];

        foreach ($banner as [$text, $register]) {
            $at = $write([$text], $register);
            $merges[] = [0, $at, self::COLUMNS - 1, $at];
        }

        // The header, stacked over two rows exactly as the school's sheet has
        // it: NO, LRN and REMARKS span the pair, the name columns carry a band
        // heading on the first line and the merged caption on the second.
        $head = $write(['NO', self::BANDS[0], '', '', 'LRN', 'REMARKS'], self::R_HEAD);
        $caption = $write(['', self::NAME_CAPTION], self::R_HEAD);

        $merges[] = [0, $head, 0, $caption];        // NO
        $merges[] = [1, $head, 3, $head];           // the band heading
        $merges[] = [1, $caption, 3, $caption];     // the name caption
        $merges[] = [4, $head, 4, $caption];        // LRN
        $merges[] = [5, $head, 5, $caption];        // REMARKS

        // The list itself: a band, then its own numbering from 1, twice. The
        // numbering restarts at each band on the printed form, and the reader
        // never reads the NO column, so it is decoration the adviser writes
        // against rather than anything the import depends on.
        foreach (self::BANDS as $band) {
            $write([$band], self::R_BAND);

            for ($line = 1; $line <= self::BAND_ROWS; $line++) {
                $write([(string) $line], self::R_LINE);
            }
        }

        $write([], self::R_PLAIN);
        $write([mb_strtoupper(trim($adviser))], self::R_PLAIN);
        $write([self::SIGNATORY_LABEL], self::R_PLAIN);

        return [
            'rows' => $rows,
            'merges' => $merges,
            'widths' => self::WIDTHS,
            'file_name' => self::fileName($classLabel, $schoolYear),
        ];
    }

    /**
     * What is missing before a scanned document can be called this form.
     *
     * A photograph is read by a model, and a model asked "is this a class
     * masterlist?" will answer yes to a great many sheets that are not one —
     * an SBFP Master List of Beneficiaries, a seating chart, last year's grade
     * sheet. So the model is asked what it *read* — the heading lines, the name
     * caption, the column headings, the band rows — and the decision is taken
     * here, against the four things that make this form readable at all. The
     * model reports; the server decides. It is the same split the section
     * catalogue keeps when it re-checks a dropdown choice server-side.
     *
     * The four are not arbitrary: they are exactly what StudentImportSheet
     * needs to find a learner on the sheet. A document missing any one of them
     * could not be imported even if it were let through, so refusing it is the
     * honest answer rather than a strictness for its own sake.
     *
     * Returns the plain-language list of what was not found, so a refusal can
     * name it. Empty means the document is this form.
     *
     * @param  array{headings?: list<string>, name_caption?: string, columns?: list<string>, bands?: list<string>}  $document
     * @return list<string>
     */
    public static function mismatches(array $document): array
    {
        $flat = static fn (array $values): string => self::compare(implode(' ', array_map(
            static fn ($value): string => (string) $value,
            $values
        )));

        $headings = $flat((array) ($document['headings'] ?? []));
        $caption = self::compare((string) ($document['name_caption'] ?? ''));
        $columns = $flat((array) ($document['columns'] ?? []));
        $bands = $flat((array) ($document['bands'] ?? []));

        $missing = [];

        if (! str_contains($headings, self::compare(self::TITLE))) {
            $missing[] = 'the "'.self::TITLE.'" heading';
        }

        // The same test StudentImportSheet applies to a merged caption: it has
        // to name both halves of the name, or the three columns under it cannot
        // be told apart.
        if (! str_contains($caption, 'lastname') || ! str_contains($caption, 'firstname')) {
            $missing[] = 'the "'.self::NAME_CAPTION.'" column caption';
        }

        if (! str_contains($columns, 'lrn')) {
            $missing[] = 'the LRN column';
        }

        // One band is enough: a class with no girls in it is a class, and the
        // form is still this form.
        $hasBand = false;
        foreach (self::BANDS as $band) {
            $hasBand = $hasBand || str_contains($bands, self::compare($band));
        }

        if (! $hasBand) {
            $missing[] = 'the '.implode(' / ', self::BANDS).' rows';
        }

        return $missing;
    }

    /** Letters and digits only, lower-case: how two headings are compared. */
    private static function compare(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($value));
    }

    /**
     * "GRADE 7 - MATATAG", as the form sets the class line.
     *
     * An adviser account cannot be approved without both halves, so an empty
     * line here means a session with no assignment — a demo session, or a
     * broken account. The form then prints a blank line to write the class on,
     * which is the same answer SchoolLetterhead gives for a missing address:
     * a gap on a form is honest, an invented value is not.
     */
    public static function classLabel(string $gradeLevel, string $section): string
    {
        $parts = array_values(array_filter([
            mb_strtoupper(trim($gradeLevel)),
            mb_strtoupper(trim($section)),
        ], static fn (string $part): bool => $part !== ''));

        return implode(' - ', $parts);
    }

    /** "2026-2027" as the form prints it — "2026 - 2027". */
    public static function schoolYearLine(string $schoolYear): string
    {
        return (string) preg_replace('/\s*-\s*/', ' - ', trim($schoolYear));
    }

    private static function fileName(string $classLabel, string $schoolYear): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $classLabel.' '.$schoolYear), '-');

        return 'CLASS-MASTERLIST'.($slug !== '' ? '-'.$slug : '').'.xlsx';
    }
}
