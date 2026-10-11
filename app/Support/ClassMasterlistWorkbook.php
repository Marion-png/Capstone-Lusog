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
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * The CLASS MASTERLIST model (App\Support\ClassMasterlistTemplate), written
 * out as a workbook.
 *
 * One writer for every reader who downloads the form: the class adviser, who
 * fills it in and uploads it back, and the school nurse, who keeps it beside
 * the SBFP forms. It used to live inside AdviserController, which is how a
 * second reader would have ended up with a second copy of the layout.
 */
final class ClassMasterlistWorkbook
{
    /** Whether the spreadsheet library this needs is installed. */
    public static function available(): bool
    {
        return class_exists(XlsxWriter::class);
    }

    /**
     * Writes the model to a temporary .xlsx and returns its path.
     *
     * Column widths and merges are declared up front because OpenSpout takes
     * them as options rather than as calls on the sheet — the heading block and
     * the stacked header are merged cells, and a merge the writer never hears
     * about leaves the caption sitting in one narrow column.
     *
     * @param  array{rows: list<array{cells: list<string>, register: string}>, merges: list<array{0: int, 1: int, 2: int, 3: int}>, widths: list<int>, file_name: string}  $template
     */
    public static function write(array $template): string
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

        $styles = self::styles();

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
    private static function styles(): array
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
}
