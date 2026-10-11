<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\AdviserClassScope;
use App\Support\AuditTrail;
use App\Support\MlatWorkbook;
use App\Support\SchemaCache;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Mandatory Learner's Health Assessment Tool as a download and as a
 * printed sheet — the clinic's own two-sheet form, filled from the learner's
 * record.
 *
 * The form's content and layout live in App\Support\MlatWorkbook; this has the
 * model's two writers — an .xlsx through OpenSpout, and a page the browser
 * prints — and decides who may have either. Both read the one model, so the
 * file the clinic files and the paper it prints are the same form. Everything
 * on it is personal and health information about one child, so both are
 * scoped to the learner's own school, to the adviser's own class for a class
 * adviser, and are recorded on the audit trail like every other read under
 * /health-records.
 */
class MlatExportController extends Controller
{
    /** Who may download or print a learner's assessment. */
    public const ROLES = ['school_nurse', 'clinic_staff', 'class_adviser', 'clinic_teacher'];

    /** The clinic's colours: the teal band and the light head row. */
    private const BAND = '0F6E62';

    private const HEAD = 'DCE6F1';

    /**
     * One line of the form's 12pt type, in points. Excel grows a wrapped row
     * to fit on its own, but never a row whose wrapped cell is merged, so the
     * writer sets those heights itself.
     */
    private const LINE_HEIGHT = 16;

    /**
     * Characters of running text one unit of column width holds. A width unit
     * is one "0" of the default font and most letters are narrower, so 1.0
     * errs toward a row a little taller than its text, never shorter.
     */
    private const CHARS_PER_WIDTH = 1.0;

    /**
     * The workbook: both sheets, or one when `?sheet=` names it — the Download
     * menu offers each sheet on its own.
     */
    public function download(Request $request, string $lrn): BinaryFileResponse|RedirectResponse
    {
        $record = $this->recordFor($request, $lrn);
        $sheet = $this->sheetNumber($request);

        if (! class_exists(XlsxWriter::class)) {
            return back()->with('error', 'The spreadsheet library is not installed on this server, so the assessment cannot be downloaded.');
        }

        $workbook = MlatWorkbook::build($record, (string) $request->session()->get('active_school_name', ''));
        if ($sheet !== null) {
            $workbook = MlatWorkbook::only($workbook, $sheet);
        }

        AuditTrail::record(
            'downloaded',
            'StudentHealthRecord',
            (int) $record->id,
            'Downloaded '.($sheet !== null ? MlatWorkbook::SHEETS[$sheet].' of ' : '')
                .'the Mandatory Learner\'s Health Assessment Tool for LRN '.$lrn
        );

        return response()
            ->download($this->write($workbook, $sheet !== null), $workbook['file_name'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * One sheet as a page to print, laid out from the same model the workbook
     * is written from. The profile loads it into a hidden frame and prints the
     * frame, so the nurse never leaves the learner; opened on its own, it
     * prints itself.
     */
    public function printSheet(Request $request, string $lrn): View
    {
        $record = $this->recordFor($request, $lrn);
        $sheet = $this->sheetNumber($request);

        // Printing is always one sheet: each is its own page in its own
        // orientation.
        if ($sheet === null) {
            abort(404);
        }

        $workbook = MlatWorkbook::build($record, (string) $request->session()->get('active_school_name', ''));
        $model = $workbook['sheets'][$sheet - 1];

        AuditTrail::record(
            'printed',
            'StudentHealthRecord',
            (int) $record->id,
            'Printed '.MlatWorkbook::SHEETS[$sheet].' of the Mandatory Learner\'s Health Assessment Tool for LRN '.$lrn
        );

        $total = array_sum($model['widths']) ?: 1;

        return view('dashboard.mlat-print', [
            'sheet' => $model,
            'learner' => $workbook['learner'],
            'widths' => array_map(fn ($width): float => round($width / $total * 100, 3), $model['widths']),
            'rows' => self::printRows($model),
        ]);
    }

    /**
     * Each row of a sheet as the cells a browser lays out: text plus the
     * number of columns it covers.
     *
     * A merge is a merge on paper too. Where the row has none, this does what
     * the spreadsheet does on screen — text runs on across the empty cells to
     * its right — so "Name of Learner:" and the name beside it sit where they
     * sit in the file instead of wrapping inside one narrow column. A ruled
     * table row, a heading row and a wrapped row keep every column their own,
     * as the spreadsheet does.
     *
     * @param  array<string, mixed>  $sheet
     * @return list<array{register: string, ruled: bool, wrap: bool, blank: bool, cells: list<array{text: string, span: int}>}>
     */
    public static function printRows(array $sheet): array
    {
        $columns = (int) $sheet['columns'];
        $out = [];

        foreach ($sheet['rows'] as $row) {
            $cells = array_pad(array_slice($row['cells'], 0, $columns), $columns, '');
            $blank = implode('', $cells) === '';

            if ($row['merge'] || $blank) {
                $out[] = self::printRow($row, $blank, [['text' => $cells[0], 'span' => $columns]]);

                continue;
            }

            $mergeEnds = [];
            foreach ($row['merges'] as [$from, $to]) {
                $mergeEnds[$from] = $to;
            }

            $overflows = ! $row['ruled'] && ! $row['wrap'] && $row['register'] !== MlatWorkbook::R_HEAD;
            $runs = [];
            for ($col = 0; $col < $columns;) {
                $end = min($mergeEnds[$col] ?? $col, $columns - 1);
                $text = $cells[$col];
                $span = $end - $col + 1;

                if ($text === '' && $overflows && $runs !== []) {
                    $runs[count($runs) - 1]['span'] += $span;
                } else {
                    $runs[] = ['text' => $text, 'span' => $span];
                }

                $col = $end + 1;
            }

            $out[] = self::printRow($row, false, $runs);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{text: string, span: int}>  $cells
     * @return array{register: string, ruled: bool, wrap: bool, blank: bool, cells: list<array{text: string, span: int}>}
     */
    private static function printRow(array $row, bool $blank, array $cells): array
    {
        return [
            'register' => $row['register'],
            'ruled' => $row['ruled'],
            'wrap' => $row['wrap'],
            'blank' => $blank,
            'cells' => $cells,
        ];
    }

    /**
     * The learner's record, once the reader has passed the guard: a clinic
     * role or the learner's own adviser, inside the reader's own school.
     */
    private function recordFor(Request $request, string $lrn): StudentHealthRecord
    {
        $role = (string) $request->session()->get('active_role', '');
        $institutionId = $request->session()->get('active_institution_id');

        if (! in_array($role, self::ROLES, true) || ! $institutionId || ! SchemaCache::hasTable('student_health_records')) {
            abort(403);
        }

        $record = StudentHealthRecord::currentForStudent($lrn, (int) $institutionId);

        if ($record === null) {
            abort(404);
        }

        // An adviser reaches only their own class; the nurse and clinic staff
        // serve the whole school.
        if ($role === 'class_adviser' && ! AdviserClassScope::coversRecord($request, $record)) {
            abort(403);
        }

        return $record;
    }

    /** `?sheet=` as one of MlatWorkbook::SHEETS, null when absent; anything else is a 404. */
    private function sheetNumber(Request $request): ?int
    {
        $sheet = $request->query('sheet');

        if ($sheet === null || $sheet === '') {
            return null;
        }

        if (! is_string($sheet) || ! ctype_digit($sheet) || ! isset(MlatWorkbook::SHEETS[(int) $sheet])) {
            abort(404);
        }

        return (int) $sheet;
    }

    /**
     * The model, written out sheet by sheet in the form's registers.
     *
     * @param  array{sheets: list<array<string, mixed>>, file_name: string}  $workbook
     * @param  bool  $single  one sheet, so the page setup can be that sheet's own
     */
    private function write(array $workbook, bool $single): string
    {
        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path.
        $reserved = tempnam(sys_get_temp_dir(), 'mlat-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        // Page setup is the workbook's, not a sheet's, in OpenSpout — so it is
        // set only when the file holds one sheet: that sheet's orientation,
        // scaled to one page wide, so Excel prints it the way the Print menu
        // does rather than splitting the last columns onto a second page.
        $pageSetup = $single
            ? new PageSetup(
                $workbook['sheets'][0]['orientation'] === 'portrait' ? PageOrientation::PORTRAIT : PageOrientation::LANDSCAPE,
                null,
                fitToHeight: 0,
                fitToWidth: 1,
            )
            : null;

        $options = new XlsxOptions(pageSetup: $pageSetup);

        // The full-width and in-row merges are declared up front, per sheet,
        // because OpenSpout takes them as options rather than as calls on the
        // sheet.
        foreach ($workbook['sheets'] as $sheetIndex => $sheet) {
            foreach ($sheet['rows'] as $rowIndex => $row) {
                if ($row['merge']) {
                    $options->mergeCells(0, $rowIndex + 1, $sheet['columns'] - 1, $rowIndex + 1, $sheetIndex);
                }
                foreach ($row['merges'] as [$from, $to]) {
                    $options->mergeCells($from, $rowIndex + 1, $to, $rowIndex + 1, $sheetIndex);
                }
            }
        }

        $writer = new XlsxWriter($options);
        $writer->openToFile($path);

        $styles = $this->styles();

        try {
            foreach ($workbook['sheets'] as $sheetIndex => $sheet) {
                if ($sheetIndex > 0) {
                    $writer->addNewSheetAndMakeItCurrent();
                }
                $current = $writer->getCurrentSheet();
                $current->setName($sheet['name']);

                // Each sheet's own widths. Set on the options, as they once
                // were, they are every sheet's fallback — and Sheet 2 was
                // written in Sheet 1's columns.
                foreach ($sheet['widths'] as $column => $width) {
                    $current->setColumnWidth((float) $width, $column + 1);
                }

                foreach ($sheet['rows'] as $row) {
                    $style = $this->rowStyle($styles, $row);
                    $cells = $row['cells'];

                    // A banded, shaded, merged or ruled row needs a cell in
                    // every column it covers, or the fill and the rules stop
                    // where the text does.
                    if ($row['merge'] || $row['register'] === MlatWorkbook::R_HEAD || $row['ruled'] || $row['merges'] !== []) {
                        $cells = array_pad($cells, $sheet['columns'], '');
                    }

                    $writer->addRow(new Row(
                        array_map(static fn (string $value): Cell => Cell::fromValue($value, $style), $cells),
                        $row['wrap'] ? $this->wrappedHeight($row, $sheet['widths']) : Row::DEFAULT_HEIGHT,
                    ));
                }
            }
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * @param  array<string, Style>  $styles
     * @param  array<string, mixed>  $row
     */
    private function rowStyle(array $styles, array $row): Style
    {
        $style = $styles[$row['register']] ?? $styles[MlatWorkbook::R_PLAIN];

        if ($row['ruled']) {
            $style = $style->withBorder(new Border(
                new BorderPart(BorderName::TOP, '000000', BorderWidth::THIN),
                new BorderPart(BorderName::RIGHT, '000000', BorderWidth::THIN),
                new BorderPart(BorderName::BOTTOM, '000000', BorderWidth::THIN),
                new BorderPart(BorderName::LEFT, '000000', BorderWidth::THIN),
            ));
        }

        // A label beside three lines of text belongs at the top of them.
        if ($row['wrap']) {
            $style = $style->withShouldWrapText(true)->withCellVerticalAlignment(CellVerticalAlignment::TOP);
        }

        return $style;
    }

    /**
     * The height a wrapped row needs: its tallest cell, measured against the
     * width of the columns that cell covers. One line is left to the default.
     *
     * @param  array<string, mixed>  $row
     * @param  list<float|int>  $widths
     */
    private function wrappedHeight(array $row, array $widths): float
    {
        $mergeEnds = [];
        foreach ($row['merges'] as [$from, $to]) {
            $mergeEnds[$from] = $to;
        }

        $lines = 1;
        foreach ($row['cells'] as $col => $text) {
            if ($text === '') {
                continue;
            }

            $end = $mergeEnds[$col] ?? $col;
            $perLine = max(1, (int) floor(array_sum(array_slice($widths, $col, $end - $col + 1)) * self::CHARS_PER_WIDTH));

            $cellLines = 0;
            foreach (preg_split('/\R/u', $text) ?: [''] as $paragraph) {
                $cellLines += max(1, (int) ceil(mb_strlen($paragraph) / $perLine));
            }
            $lines = max($lines, $cellLines);
        }

        return $lines > 1 ? (float) ($lines * self::LINE_HEIGHT + 4) : Row::DEFAULT_HEIGHT;
    }

    /**
     * @return array<string, Style>
     */
    private function styles(): array
    {
        $band = (new Style)
            ->withFontBold(true)
            ->withFontColor('FFFFFF')
            ->withBackgroundColor(self::BAND);

        return [
            MlatWorkbook::R_TITLE => $band->withFontSize(13)->withCellAlignment(CellAlignment::CENTER),
            MlatWorkbook::R_SUBTITLE => (new Style)
                ->withFontBold(true)
                ->withFontSize(10)
                ->withFontColor(self::BAND)
                ->withCellAlignment(CellAlignment::CENTER),
            MlatWorkbook::R_BAND => $band->withFontSize(10),
            MlatWorkbook::R_HEAD => (new Style)->withFontBold(true)->withBackgroundColor(self::HEAD),
            MlatWorkbook::R_PLAIN => new Style,
        ];
    }
}
