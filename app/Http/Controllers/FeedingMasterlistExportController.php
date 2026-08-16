<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\FeedingBeneficiarySummary;
use App\Support\SchemaCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The SBFP masterlist, exported as the school's own form.
 *
 * Two decisions worth keeping:
 *
 * 1. **It is the printed form, not a dump of the screen.** The file opens on
 *    the DepEd masterlist heading the coordinator already fills in by hand on
 *    the SBFP Forms page — school, address, title, school year — carries the
 *    same four columns (No. / Name / Grade / Section), and ends with the same
 *    "Prepared by / Noted by" block. Someone can print it and hand it in; a
 *    bare table of whatever was on screen always needed retyping first.
 *
 * 2. **It is a real workbook.** A .csv is a text file, and which program opens
 *    one is a setting on the reader's computer that no web application can
 *    reach — a coordinator whose machine hands .csv to Notepad gets Notepad,
 *    however the file was written. An .xlsx is a spreadsheet by format, so it
 *    opens in Excel on any machine that has it, and the heading rows and column
 *    widths survive the trip, which they never do in comma-separated text.
 *
 * The rows come from the client as an ordered list of record ids — exactly the
 * rows the coordinator had on screen after filtering, searching and sorting, in
 * that order. They are re-read and re-scoped to the coordinator's own school
 * here, because ids off the wire decide nothing on their own. With no ids (a
 * browser with JS off) the export falls back to this school's whole enrolled
 * roll for the year on screen, so the button is never a dead end.
 */
class FeedingMasterlistExportController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can export the masterlist.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $schoolYear = trim((string) $request->query('school_year', '')) ?: StudentHealthRecord::currentSchoolYear();

        $rows = $this->rows($request, $institutionId, $schoolYear);

        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path — otherwise every export
        // leaves an empty temp file behind.
        $reserved = tempnam(sys_get_temp_dir(), 'sbfp-masterlist-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $this->writeSheet(
            $writer,
            $rows,
            (string) $request->session()->get('active_school_name', 'School'),
            $schoolYear,
            (string) $request->session()->get('active_name', '')
        );

        $writer->close();

        $filename = 'SBFP-Masterlist-'.str_replace('/', '-', $schoolYear).'-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * The learners to print, in the order the coordinator was reading them.
     *
     * @return list<array{name: string, grade: string, section: string}>
     */
    private function rows(Request $request, ?int $institutionId, string $schoolYear): array
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            return [];
        }

        // Ids arrive off the wire, so the roster is re-read and re-scoped: only
        // this school's learners can reach the file, whatever was posted.
        $requested = collect($request->input('record_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values();

        $records = StudentHealthRecord::query()
            ->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId))
            ->forCurrentSchoolYear($schoolYear)
            ->when($requested->isNotEmpty(), fn ($query) => $query->whereIn('id', $requested->all()))
            ->get();

        if ($requested->isEmpty()) {
            // No client order to honour, so the fallback is the enrolled roll,
            // sorted the way the printed form reads: by section, then by name.
            // student_name is encrypted at rest, so the sort happens in PHP.
            $records = $records
                ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
                ->sortBy([
                    fn (StudentHealthRecord $a, StudentHealthRecord $b) => strcasecmp((string) $a->section, (string) $b->section),
                    fn (StudentHealthRecord $a, StudentHealthRecord $b) => strcasecmp((string) $a->student_name, (string) $b->student_name),
                ]);
        } else {
            // The client's order is the order on screen, which is the order the
            // coordinator chose — so it is preserved rather than re-sorted.
            $position = array_flip($requested->all());
            $records = $records->sortBy(fn (StudentHealthRecord $record): int => $position[$record->id] ?? PHP_INT_MAX);
        }

        return $records
            ->map(function (StudentHealthRecord $record): array {
                [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);

                return [
                    'name' => (string) $record->student_name,
                    // "Grade 8" prints as 8 on the DepEd form: the column head
                    // already says Grade.
                    'grade' => (string) preg_replace('/^grade\s*/i', '', $grade),
                    'section' => $section,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The form itself. The heading block, the four-column table and the
     * signature block are the SBFP Forms masterlist, so a printed export and a
     * printed form are the same document.
     *
     * @param  list<array{name: string, grade: string, section: string}>  $rows
     */
    private function writeSheet(XlsxWriter $writer, array $rows, string $schoolName, string $schoolYear, string $preparedBy): void
    {
        $title = (new Style)->withFontBold(true)->withFontSize(12);
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

        $writer->addRow($line([$schoolName], $title));
        // Left blank deliberately: the school address is typed on the form, and
        // the app does not hold it. An invented line would be worse than a gap.
        $writer->addRow($line(['School address:']));
        $writer->addRow($line([
            'Masterlists of Identified Severely Wasted and Wasted Students Who Are Qualified for Feeding Program',
        ], $heading));
        $writer->addRow($line(['S.Y. '.$schoolYear], $centered));
        $writer->addRow($line(['']));

        $writer->addRow($line(['No.', 'Name', 'Grade', 'Section'], $ruledHead));

        foreach ($rows as $index => $row) {
            $writer->addRow($line([
                $index + 1,
                $row['name'],
                $row['grade'],
                $row['section'],
            ], $ruled));
        }

        // The printed form always carries blank rows to write into by hand, so
        // a short list is still a usable sheet at the feeding line.
        for ($blank = count($rows); $blank < 20; $blank++) {
            $writer->addRow($line([$blank + 1, '', '', ''], $ruled));
        }

        $writer->addRow($line(['']));
        $writer->addRow($line(['Prepared by:', '', 'Noted by:']));
        $writer->addRow($line([$preparedBy, '', '']));
        $writer->addRow($line(['Feeding Coordinator', '', 'Principal']));
    }

    private function isCoordinator(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'feeding_coor';
    }
}
