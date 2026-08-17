<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\SchoolHeadHealthOverview;
use App\Support\SchoolHeadOverview;
use App\Support\SchoolHeadPulse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The School Head's Health Overview — what the clinic did.
 *
 * Aggregated only. The head is accountable for clinic activity as a service
 * the school runs: how much of it there is, what it is for, which grades it
 * comes from and how a visit ended. The clinical narrative — the treatment, the
 * note, the learner's history — stays on the nurse's screen, because a head
 * reading a management summary has no need for it and the minimum-necessary
 * principle says they should not be handed it.
 *
 * Nothing here writes. Consultations are logged by the school nurse and the
 * clinic staff; this tab reads what they logged and offers no way to change it.
 *
 * Every grouping runs in PHP: the learner, the grade and the complaint on a
 * consultation are encrypted at rest, so only `consulted_at`, `status`,
 * `condition_id` and `institution_id` are ever named in a WHERE.
 */
class SchoolHeadHealthController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can open the health overview.');
        }

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

        $overview = $school->scopedTo($grade, $section);
        $health = SchoolHeadHealthOverview::for(
            $institutionId,
            $schoolYear,
            $overview->records,
            $grade !== '' || $section !== '',
        );

        return view('schoolhead-dashboard.health', [
            'schoolName' => $request->session()->get('active_school_name', 'School'),
            'schoolYear' => $schoolYear,
            'schoolYears' => $years,
            'todayLabel' => now()->format('j F Y'),
            'filters' => ['school_year' => $schoolYear, 'grade' => $grade, 'section' => $section],
            'filterOptions' => $options,
            'clinic' => $health->clinic(),
            'students' => $overview->records->count(),
            'stamp' => SchoolHeadPulse::stamp($institutionId),
        ]);
    }

    private function isSchoolHead(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'school_head';
    }
}
