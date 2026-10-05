<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * The school's masterlist form, written into a workbook.
 *
 * One layout for every list that leaves the app as a masterlist: the DepEd
 * letterhead, the school and its address, the list's title and school year, a
 * ruled table numbered down its first column and padded to the form's twenty
 * lines, then the "Prepared by / Noted by" block. The Feeding Coordinator's
 * SBFP masterlist was written this way first; the Nutritional Health Status
 * list's Print list is the second. Two copies of the layout would be two forms
 * that drift apart, so both write through here and each decides only its
 * title, its columns and its rows.
 */
final class MasterlistSheet
{
    /** Ruled lines the printed form always carries, so a short list is still a usable sheet. */
    public const MIN_ROWS = 20;

    /**
     * @param  array<string, string>  $letterhead  SchoolLetterhead::for()
     * @param  list<string>  $columns  the column heads after "No."
     * @param  list<list<string|int|float>>  $rows  one value per column, in that order
     * @param  string  $scope  what the list was narrowed to, printed after the school year
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
    ): void {
        $titleStyle = (new Style)->withFontBold(true)->withFontSize(12);
        $heading = (new Style)->withFontBold(true)->withFontSize(10);
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

        // The DepEd heading, read through SchoolLetterhead so this sheet, the
        // printed SBFP form and the attendance export all head the same school
        // the same way.
        foreach (SchoolLetterhead::lines($letterhead) as $headingLine) {
            $writer->addRow($line([$headingLine], $centered));
        }

        $writer->addRow($line([$letterhead['school']], $titleStyle));
        // The address the school is on file with. A school with none gets an
        // empty line to write on, never a neighbouring school's street.
        $writer->addRow($line([$letterhead['address']], $centered));
        // The title names WHICH list this is. Lists the school keeps
        // separately must never share a heading, or they get filed as each
        // other.
        $writer->addRow($line([$title], $heading));
        // A filtered list says what it was filtered to, so a filed copy never
        // reads as the whole school.
        $writer->addRow($line(['S.Y. '.$schoolYear.($scope !== '' ? ' · '.$scope : '')], $centered));
        $writer->addRow($line(['']));

        $writer->addRow($line(['No.', ...$columns], $ruledHead));

        foreach (array_values($rows) as $index => $row) {
            $writer->addRow($line([$index + 1, ...$row], $ruled));
        }

        // The printed form always carries blank rows to write into by hand, so
        // a short list is still a usable sheet.
        $blankCells = array_fill(0, count($columns), '');
        for ($blank = count($rows); $blank < self::MIN_ROWS; $blank++) {
            $writer->addRow($line([$blank + 1, ...$blankCells], $ruled));
        }

        $writer->addRow($line(['']));
        $writer->addRow($line(['Prepared by:', '', 'Noted by:']));
        $writer->addRow($line([$preparedBy, '', $notedBy]));
        $writer->addRow($line([$preparedRole, '', $notedRole]));
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
