<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\SchoolHeadHealthOverview;
use App\Support\SchoolHeadOverview;
use App\Support\SchoolHeadPulse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The School Head's Consent Compliance tab — whether the school holds the
 * health-services consent it is required to hold.
 *
 * The head's question is a compliance one: how complete is the school, which
 * sections are behind, and who is still outstanding. So the tab reports the
 * standing of each learner's form and never its contents — the allergies, the
 * write-in exceptions and the parent's signature belong to the adviser and the
 * nurse who act on them, and the minimum-necessary principle keeps them off a
 * monitoring screen.
 *
 * Four standings, and only the first authorises anything:
 *
 *   valid     — a parent answered and did not refuse
 *   awaiting  — sent to the parent, no answer yet
 *   declined  — the parent refused
 *   none      — no form on file at all
 *
 * A draft nobody sent counts as no form: it authorises nothing, and counting it
 * would report the school as compliant on the strength of an unsent letter.
 */
class SchoolHeadConsentController extends Controller
{
    /** The standings the list can be narrowed to. */
    private const STATES = ['valid', 'awaiting', 'declined', 'none'];

    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can open consent compliance.');
        }

        return view('schoolhead-dashboard.consent', $this->build($request));
    }

    /**
     * The outstanding list as the filters leave it, as a real workbook.
     *
     * It carries learner names, so it is a read of personal data like any
     * other: the route sits under dashboard/* and is audited.
     */
    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can export consent compliance.');
        }

        $data = $this->build($request);

        // tempnam() creates the file it names, so the reservation is released
        // before the writer claims the .xlsx path — otherwise every export
        // leaves an empty temp file behind.
        $reserved = tempnam(sys_get_temp_dir(), 'sh-consent-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues(['HEALTH SERVICES CONSENT — COMPLIANCE']));
        $writer->addRow(Row::fromValues([(string) $data['schoolName']]));
        $writer->addRow(Row::fromValues(['S.Y. '.$data['schoolYear']]));
        $writer->addRow(Row::fromValues(['Generated '.now()->format('Y-m-d')]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['GRADE', 'SECTION', 'ON ROLL', 'VALID', 'MISSING', 'COMPLETION']));
        foreach ($data['consent']['sections'] as $section) {
            $writer->addRow(Row::fromValues([
                $section['grade'],
                $section['section'],
                $section['required'],
                $section['valid'],
                $section['missing'],
                $section['rate'] !== null ? $this->percent($section['rate']).'%' : '—',
            ]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['LEARNERS WITHOUT VALID CONSENT']));
        $writer->addRow(Row::fromValues(['NO.', 'LRN', 'NAME', 'GRADE', 'SECTION', 'STANDING']));

        $number = 0;
        foreach ($data['rows'] as $row) {
            $number++;
            $writer->addRow(Row::fromValues([
                $number,
                $row['lrn'],
                $row['name'],
                $row['grade'],
                $row['section'],
                $row['state_label'],
            ]));
        }

        $writer->close();

        $filename = 'Consent-Compliance-'.str_replace('/', '-', $data['schoolYear']).'-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Everything the tab shows, from one reading of one roster.
     *
     * @return array<string, mixed>
     */
    private function build(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');
        $years = SchoolHeadOverview::schoolYears($institutionId);

        $schoolYear = trim((string) $request->query('school_year', ''));
        if (! in_array($schoolYear, $years, true)) {
            $schoolYear = $years[0] ?? StudentHealthRecord::currentSchoolYear();
        }

        $school = SchoolHeadOverview::for($institutionId, $schoolYear);
        $options = $school->sectionOptions();

        $grade = trim((string) $request->query('grade', ''));
        if (! in_array($grade, $options['grades'], true)) {
            $grade = '';
        }

        $section = trim((string) $request->query('section', ''));
        if (! in_array($section, $options['sections'], true)) {
            $section = '';
        }

        $state = trim((string) $request->query('state', ''));
        if (! in_array($state, self::STATES, true)) {
            $state = '';
        }

        $overview = $school->scopedTo($grade, $section);
        $health = SchoolHeadHealthOverview::for($institutionId, $schoolYear, $overview->records);
        $consent = $health->consent();

        // "valid" narrows the outstanding list to nothing on purpose: the list
        // is the learners without consent, and a head asking for the compliant
        // ones is asking a question this list does not answer.
        $rows = collect($consent['missing_rows'])
            ->when($state !== '' && $state !== 'valid', fn ($list) => $list->where('state', $state))
            ->when($state === 'valid', fn ($list) => $list->take(0))
            ->sortBy([
                fn (array $row): string => $row['grade'],
                fn (array $row): string => $row['section'],
                fn (array $row): string => strtolower($row['name']),
            ])
            ->values();

        return [
            'schoolName' => $request->session()->get('active_school_name', 'School'),
            'schoolYear' => $schoolYear,
            'schoolYears' => $years,
            'todayLabel' => now()->format('j F Y'),
            'filters' => [
                'school_year' => $schoolYear,
                'grade' => $grade,
                'section' => $section,
                'state' => $state,
            ],
            'filterOptions' => $options,
            'states' => self::STATES,
            'consent' => $consent,
            'rows' => $rows,
            'shown' => $rows->count(),
            'stamp' => SchoolHeadPulse::stamp($institutionId),
        ];
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    private function isSchoolHead(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'school_head';
    }
}
