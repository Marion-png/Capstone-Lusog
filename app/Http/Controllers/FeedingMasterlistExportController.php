<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\FeedingBeneficiarySummary;
use App\Support\MasterlistSheet;
use App\Support\SchemaCache;
use App\Support\SchoolLetterhead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 *
 * **Three lists, not one, because the school keeps three.** The coordinator was
 * explicit that the master list of learners the measurement *qualifies* and the
 * master list of learners actually *enrolled* are two separate documents with
 * two separate purposes — qualifying is the adviser's measurement, enrolling is
 * the coordinator's decision after an interview and whatever the local budget
 * supports. Printing them under one heading is what let the two be confused for
 * each other:
 *
 *   beneficiaries  the enrolled roll — DepEd Form 1's Master List of
 *                  Beneficiaries, and the default
 *   qualified      every learner the measurement qualifies, enrolled or not —
 *                  the candidate list
 *   waitlist       the qualified learners nobody has enrolled: the coordinator's
 *                  "buffer", the list a vacated slot is filled from
 *
 * Only the first honours the client's ordering, because only the first is what
 * was on screen; the other two are computed here from the roll.
 */
class FeedingMasterlistExportController extends Controller
{
    /**
     * The three lists this endpoint can write, with the DepEd title each one is
     * filed under and the slug its filename carries.
     *
     * Public because the SBFP Forms page heads its two masterlist forms with
     * these same titles: a form typed under one heading and exported under
     * another is two documents claiming to be one.
     */
    public const LISTS = [
        'beneficiaries' => [
            'Master List of Beneficiaries',
            'Beneficiaries',
        ],
        'qualified' => [
            'Masterlists of Identified Severely Wasted and Wasted Students Who Are Qualified for Feeding Program',
            'Qualified',
        ],
        'waitlist' => [
            'Waiting List of Identified Severely Wasted and Wasted Students Not Yet Enrolled',
            'Waiting-List',
        ],
    ];

    public function __invoke(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can export the masterlist.');
        }

        $institutionId = $request->session()->get('active_institution_id');
        $schoolYear = trim((string) $request->query('school_year', '')) ?: StudentHealthRecord::currentSchoolYear();

        // An unrecognised value falls back to the enrolled roll rather than
        // producing an empty form under a title nobody asked for.
        $list = (string) $request->input('list', $request->query('list', 'beneficiaries'));
        if (! array_key_exists($list, self::LISTS)) {
            $list = 'beneficiaries';
        }
        [$title, $slug] = self::LISTS[$list];

        $rows = $this->rows($request, $institutionId, $schoolYear, $list);

        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path — otherwise every export
        // leaves an empty temp file behind.
        $reserved = tempnam(sys_get_temp_dir(), 'sbfp-masterlist-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        // The form itself — heading, the four-column ruled table, and the
        // signature block — is the SBFP Forms masterlist, so a printed export
        // and a printed form are the same document. MasterlistSheet holds the
        // layout; the Nutritional Health Status list's Print list writes
        // through it too.
        MasterlistSheet::write(
            $writer,
            letterhead: SchoolLetterhead::for($institutionId, (string) $request->session()->get('active_school_name', 'School')),
            title: $title,
            schoolYear: $schoolYear,
            columns: ['Name', 'Grade', 'Section'],
            rows: array_map(static fn (array $row): array => [$row['name'], $row['grade'], $row['section']], $rows),
            preparedBy: (string) $request->session()->get('active_name', ''),
            preparedRole: 'Feeding Coordinator',
        );

        $writer->close();

        $filename = 'SBFP-'.$slug.'-'.str_replace('/', '-', $schoolYear).'-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * The learners to print, in the order the coordinator was reading them.
     *
     * @return list<array{name: string, grade: string, section: string}>
     */
    private function rows(Request $request, ?int $institutionId, string $schoolYear, string $list): array
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            return [];
        }

        // Ids arrive off the wire, so the roster is re-read and re-scoped: only
        // this school's learners can reach the file, whatever was posted.
        //
        // They are honoured for the beneficiary list alone: that list IS the
        // rows on screen, in the order the coordinator sorted them. The
        // candidate list and the waiting list are different documents that were
        // never on screen, so they are computed from the roll here rather than
        // from whatever the page happened to be showing.
        $requested = $list === 'beneficiaries'
            ? collect($request->input('record_ids', []))
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->values()
            : collect();

        $records = StudentHealthRecord::query()
            ->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId))
            ->forCurrentSchoolYear($schoolYear)
            ->when($requested->isNotEmpty(), fn ($query) => $query->whereIn('id', $requested->all()))
            ->get();

        if ($requested->isEmpty()) {
            // No client order to honour, so the list is selected here and
            // sorted the way the printed form reads: by section, then by name.
            // student_name is encrypted at rest, so the sort happens in PHP.
            $keep = match ($list) {
                // Every learner the measurement qualifies, enrolled or not.
                'qualified' => fn (StudentHealthRecord $r): bool => FeedingBeneficiarySummary::isEligible($r),
                // The buffer: qualified, and nobody has given them a place.
                'waitlist' => fn (StudentHealthRecord $r): bool => FeedingBeneficiarySummary::isEligible($r)
                    && ! FeedingBeneficiarySummary::isEnrolled($r),
                default => fn (StudentHealthRecord $r): bool => FeedingBeneficiarySummary::isBeneficiary($r),
            };

            $records = $records
                ->filter($keep)
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
                [$grade, $section] = MasterlistSheet::gradeAndSection((string) $record->section);

                return [
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'section' => $section,
                ];
            })
            ->values()
            ->all();
    }

    private function isCoordinator(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'feeding_coor';
    }
}
