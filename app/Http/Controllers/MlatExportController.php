<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\AdviserClassScope;
use App\Support\AuditTrail;
use App\Support\MlatWorkbook;
use App\Support\SchemaCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Mandatory Learner's Health Assessment Tool as a download — the clinic's
 * own two-sheet workbook, filled from the learner's record.
 *
 * The form's content and layout live in App\Support\MlatWorkbook; this turns
 * that model into an .xlsx through OpenSpout and decides who may have it.
 * Everything on it is personal and health information about one child, so
 * the download is scoped to the learner's own school, to the adviser's own
 * class for a class adviser, and is recorded on the audit trail like every
 * other download under /health-records.
 */
class MlatExportController extends Controller
{
    /** Who may download a learner's assessment. */
    public const ROLES = ['school_nurse', 'clinic_staff', 'class_adviser'];

    /** The clinic's colours: the teal band and the light head row. */
    private const BAND = '0F6E62';

    private const HEAD = 'DCE6F1';

    public function download(Request $request, string $lrn): BinaryFileResponse|RedirectResponse
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

        if (! class_exists(XlsxWriter::class)) {
            return back()->with('error', 'The spreadsheet library is not installed on this server, so the assessment cannot be downloaded.');
        }

        $workbook = MlatWorkbook::build($record, (string) $request->session()->get('active_school_name', ''));

        AuditTrail::record(
            'downloaded',
            'StudentHealthRecord',
            (int) $record->id,
            'Downloaded the Mandatory Learner\'s Health Assessment Tool for LRN '.$lrn
        );

        return response()
            ->download($this->write($workbook), $workbook['file_name'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * The model, written out sheet by sheet in the form's registers.
     *
     * @param  array{sheets: list<array<string, mixed>>, file_name: string}  $workbook
     */
    private function write(array $workbook): string
    {
        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path.
        $reserved = tempnam(sys_get_temp_dir(), 'mlat-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $options = new XlsxOptions;

        // Column widths and the full-width merges are declared up front, per
        // sheet, because OpenSpout takes them as options rather than as calls
        // on the sheet.
        foreach ($workbook['sheets'] as $sheetIndex => $sheet) {
            if ($sheetIndex === 0) {
                foreach ($sheet['widths'] as $column => $width) {
                    $options->setColumnWidth((float) $width, $column + 1);
                }
            }
            foreach ($sheet['rows'] as $rowIndex => $row) {
                if ($row['merge']) {
                    $options->mergeCells(0, $rowIndex + 1, $sheet['columns'] - 1, $rowIndex + 1, $sheetIndex);
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
                $writer->getCurrentSheet()->setName($sheet['name']);

                foreach ($sheet['rows'] as $row) {
                    $style = $styles[$row['register']] ?? $styles[MlatWorkbook::R_PLAIN];
                    $cells = $row['cells'];

                    // A banded row is one merged cell; it still needs a cell per
                    // column so the fill runs the full width of the form.
                    if ($row['merge'] || $row['register'] === MlatWorkbook::R_HEAD) {
                        $cells = array_pad($cells, $sheet['columns'], '');
                    }

                    $writer->addRow(new Row(array_map(
                        static fn (string $value): Cell => Cell::fromValue($value, $style),
                        $cells
                    )));
                }
            }
        } finally {
            $writer->close();
        }

        return $path;
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
