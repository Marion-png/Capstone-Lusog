<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ImageCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * The school's masterlist form, written into a workbook.
 *
 * One layout for every list that leaves the app as a masterlist: the school's
 * office letterhead with its two seals, the list's title and school year, a
 * ruled table numbered down its first column and padded to the form's twenty
 * lines, then the "Prepared by / Noted by" block. The printed masterlist
 * (feedingcor-dashboard/masterlist-print) sets the same letterhead in HTML. The Feeding Coordinator's
 * SBFP masterlist was written this way first; the Nutritional Health Status
 * list's Print list is the second. Two copies of the layout would be two forms
 * that drift apart, so both write through here and each decides only its
 * title, its columns and its rows.
 */
final class MasterlistSheet
{
    /** Ruled lines the printed form always carries, so a short list is still a usable sheet. */
    public const MIN_ROWS = 20;

    /** The letterhead's green: the school's name and the rule under the heading. */
    public const GREEN = '1A7A3A';

    /** Each seal's size in the workbook, in pixels (both files are near-square). */
    private const SEAL_PX = 80;

    /** Width of the two seal columns, A and B, in Excel character units (~87px). */
    private const SEAL_COLUMN = 11.8;

    /**
     * The letterhead's line heights, in points, by role. Set rather than left
     * to Excel so the block the seals sit beside is the same height wherever
     * the file is opened.
     */
    private const LINE_HEIGHTS = [
        'republic' => 12, 'department' => 13, 'division' => 13, 'school' => 20,
        'address' => 12, 'office' => 13, 'contact' => 12,
    ];

    /**
     * A writer for this form: A4, one page wide and as many long as the roll
     * needs, turned to landscape for a list wider than the coordinator's four
     * columns. The page setup is fixed when the writer is made, so the form
     * supplies the writer rather than taking any.
     */
    public static function writer(int $columnCount): XlsxWriter
    {
        return new XlsxWriter(new Options(pageSetup: new PageSetup(
            $columnCount > 4 ? PageOrientation::LANDSCAPE : PageOrientation::PORTRAIT,
            PaperSize::A4,
            fitToHeight: 0,
            fitToWidth: 1,
        )));
    }

    /**
     * The sheet is laid out around the school's own office letterhead: the
     * Division seal and the school seal side by side at the top left, and the
     * letterhead's lines beside them, left-aligned, with a green rule under
     * the block. A spreadsheet places an image at a cell's corner and nothing
     * finer, so each seal has a column of its own — A, which is also the
     * table's "No.", and B — and the letterhead starts in C. The table's first
     * column is B and C merged, row by row, so it reads as one wide column on
     * screen and on paper.
     *
     * @param  array<string, string>  $letterhead  SchoolLetterhead::for()
     * @param  list<string>  $columns  the column heads after "No."
     * @param  list<list<string|int|float>>  $rows  one value per column, in that order
     * @param  string  $scope  what the list was narrowed to, printed after the school year
     * @param  list<float>  $widths  Excel widths for the columns after "No.", in order; the first includes the seal column
     */
    public static function write(
        XlsxWriter $writer,
        array $letterhead,
        string $title,
        string $schoolYear,
        array $columns,
        array $rows,
        string $preparedBy,
        string $preparedRole,
        string $notedBy = '',
        string $notedRole = 'Principal',
        string $scope = '',
        array $widths = [],
    ): void {
        $options = $writer->getOptions();
        // 0-based: No. (A), the first column across B and C, then the rest.
        $lastColumn = count($columns) + 1;
        $rowNumber = 0;

        $options->setColumnWidth(self::SEAL_COLUMN, 1, 2);
        $options->setColumnWidth(max(4.0, (float) ($widths[0] ?? self::SEAL_COLUMN + 4) - self::SEAL_COLUMN), 3);
        foreach (array_slice($widths, 1) as $offset => $width) {
            $options->setColumnWidth((float) $width, $offset + 4);
        }

        $add = static function (Row $row) use ($writer, &$rowNumber): int {
            $writer->addRow($row);

            return ++$rowNumber;
        };
        $across = static fn (int $row, int $from = 0) => $options->mergeCells($from, $row, $lastColumn, $row);

        $titleStyle = (new Style)->withFontBold(true)->withFontSize(12)->withCellAlignment(CellAlignment::CENTER);
        $centered = (new Style)->withCellAlignment(CellAlignment::CENTER);

        // A hairline box, as on the printed sheet: the DepEd form is a ruled
        // table, and an unruled block of names is not the same document.
        $box = static fn (): Border => new Border(
            new BorderPart(BorderName::TOP, width: BorderWidth::THIN),
            new BorderPart(BorderName::BOTTOM, width: BorderWidth::THIN),
            new BorderPart(BorderName::LEFT, width: BorderWidth::THIN),
            new BorderPart(BorderName::RIGHT, width: BorderWidth::THIN),
        );

        $ruled = (new Style)->withBorder($box());
        $ruledHead = (new Style)->withFontBold(true)->withBorder($box());

        // One style per row, applied cell by cell — this OpenSpout takes a row
        // style through its cells rather than as a second argument.
        $line = static fn (array $values, ?Style $style = null): Row => new Row(array_values(array_map(
            static fn ($value): Cell => Cell::fromValue($value, $style),
            $values
        )));

        // ── The letterhead, read through SchoolLetterhead so this sheet and
        // the printed masterlist head the same school the same way. ──
        $mastheadStyles = [
            'republic' => (new Style)->withFontSize(9),
            'department' => (new Style)->withFontSize(10),
            'division' => (new Style)->withFontSize(10),
            'school' => (new Style)->withFontBold(true)->withFontSize(15)->withFontColor(self::GREEN),
            'address' => (new Style)->withFontSize(9),
            'office' => (new Style)->withFontBold(true)->withFontSize(10),
            'contact' => (new Style)->withFontSize(8),
        ];
        $seals = SchoolLetterhead::sealFiles();

        foreach (SchoolLetterhead::masthead($letterhead) as $index => $headLine) {
            // The seals hang from the second line, so they sit level with the
            // middle of the block rather than at its top edge.
            $seal = static fn (?string $file): Cell => $index === 1 && $file !== null
                ? new ImageCell($file, self::SEAL_PX, self::SEAL_PX)
                : Cell::fromValue('');

            $row = $add((new Row([
                $seal($seals['deped']),
                $seal($seals['school']),
                Cell::fromValue($headLine['text'], $mastheadStyles[$headLine['role']]),
            ]))->withHeight(self::LINE_HEIGHTS[$headLine['role']]));
            $across($row, 2);
        }

        // The green rule under the letterhead. OpenSpout skips a row of empty
        // cells however it is styled, so the first cell holds a space.
        $rule = (new Style)->withBorder(new Border(new BorderPart(BorderName::BOTTOM, self::GREEN, BorderWidth::THICK)));
        $add($line([' ', ...array_fill(0, $lastColumn, '')], $rule)->withHeight(6));
        $add($line(['']));

        // The title names WHICH list this is. Lists the school keeps
        // separately must never share a heading, or they get filed as each
        // other.
        $across($add($line([$title], $titleStyle)));
        // A filtered list says what it was filtered to, so a filed copy never
        // reads as the whole school.
        $across($add($line(['S.Y. '.$schoolYear.($scope !== '' ? ' · '.$scope : '')], $centered)));
        $add($line(['']));

        // The table: the first column after "No." spans B and C, so C is
        // written empty and merged into B.
        $tableRow = static function (string|int $number, array $cells, Style $style) use ($add, $line, $options): void {
            $cells = array_values($cells);
            $row = $add($line([$number, $cells[0] ?? '', '', ...array_slice($cells, 1)], $style));
            $options->mergeCells(1, $row, 2, $row);
        };

        $tableRow('No.', $columns, $ruledHead);

        foreach (array_values($rows) as $index => $row) {
            $tableRow($index + 1, $row, $ruled);
        }

        // The printed form always carries blank rows to write into by hand, so
        // a short list is still a usable sheet.
        $blankCells = array_fill(0, count($columns), '');
        for ($blank = count($rows); $blank < self::MIN_ROWS; $blank++) {
            $tableRow($blank + 1, $blankCells, $ruled);
        }

        // Prepared by under the names, Noted by from the column after them.
        $add($line(['']));
        $add($line(['Prepared by:', '', '', 'Noted by:']));
        $add($line([$preparedBy, '', '', $notedBy]));
        $add($line([$preparedRole, '', '', $notedRole]));
    }

    /**
     * A roster's "Grade 8 / Rosal" as the form's two columns.
     *
     * "Grade 8" prints as 8: the column head already says Grade.
     *
     * @return array{0: string, 1: string}
     */
    public static function gradeAndSection(string $section): array
    {
        [$grade, $sectionName] = FeedingBeneficiarySummary::splitSection($section);

        return [(string) preg_replace('/^grade\s*/i', '', $grade), $sectionName];
    }
}
