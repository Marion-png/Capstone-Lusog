<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\ClassMasterlistRoster;
use App\Support\ClassMasterlistTemplate;
use App\Support\ClassMasterlistWorkbook;
use App\Support\SchoolLetterhead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One class's CLASS MASTERLIST, filled from the roster, on the School Nurse's
 * and the Clinic Teacher's Forms & Templates page: shown as a sheet (sheet())
 * and downloaded as a workbook (download()), both from the one model. Scoped
 * to the reader's own school; a class it does not hold is refused, never
 * guessed. Audited by AuditSensitiveAccess (dashboard/*).
 */
class ClinicClassMasterlistController extends Controller
{
    public const ROLES = ['school_nurse', 'clinic_teacher'];

    /** The sheet for the chosen class, as HTML the page swaps into its panel. */
    public function sheet(Request $request): Response
    {
        if (! $this->allowed($request)) {
            abort(403);
        }

        $class = $this->chosenClass($request);
        $template = $this->template($request, $class);

        if ($template === null) {
            abort(404);
        }

        $route = $request->session()->get('active_role') === 'clinic_teacher'
            ? 'dashboard.clinic-teacher.class-masterlist'
            : 'dashboard.school-nurse.class-masterlist';

        $institutionId = $request->session()->get('active_institution_id');
        $institutionId = $institutionId ? (int) $institutionId : null;
        $parts = explode(' / ', $class, 2);

        return response()->view('feedingcor-dashboard.partials.class-masterlist-sheet', [
            'rows' => ClassMasterlistTemplate::printRows($template),
            'widths' => $template['widths'],
            'downloadUrl' => route($route, ['class' => $class]),
            // The heading every form template on the page opens under.
            'letterhead' => SchoolLetterhead::for($institutionId, (string) $request->session()->get('active_school_name', '')),
            'seals' => SchoolLetterhead::seals($institutionId),
            'classLabel' => ClassMasterlistTemplate::classLabel($parts[0], $parts[1] ?? ''),
            'schoolYear' => StudentHealthRecord::currentSchoolYear(),
        ]);
    }

    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->allowed($request)) {
            return redirect()->route('login')
                ->with('error', 'Only the School Nurse and the Clinic Teacher can download a class masterlist.');
        }

        if (! ClassMasterlistWorkbook::available()) {
            return back()->with('error', 'The spreadsheet library is not installed, so the masterlist cannot be downloaded.');
        }

        $template = $this->template($request, $this->chosenClass($request));

        if ($template === null) {
            return back()->with('error', 'Choose a class to download its masterlist.');
        }

        return response()
            ->download(ClassMasterlistWorkbook::write($template), $template['file_name'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    private function allowed(Request $request): bool
    {
        return in_array((string) $request->session()->get('active_role', ''), self::ROLES, true);
    }

    private function chosenClass(Request $request): string
    {
        return trim((string) $request->query('class', ''));
    }

    /** @return array{rows: list<array{cells: list<string>, register: string}>, merges: list<array{0: int, 1: int, 2: int, 3: int}>, widths: list<int>, file_name: string}|null */
    private function template(Request $request, string $class): ?array
    {
        $institutionId = $request->session()->get('active_institution_id');

        return ClassMasterlistRoster::template(
            $institutionId ? (int) $institutionId : null,
            (string) $request->session()->get('active_school_name', ''),
            $class,
        );
    }
}
