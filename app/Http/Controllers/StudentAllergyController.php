<?php

namespace App\Http\Controllers;

use App\Support\LearnerAllergies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A learner's recorded allergies, for the New Consultation dialog.
 *
 * Read when the learner is chosen rather than embedded for the whole roll:
 * the dialog sits on pages that list every learner in the school, and an
 * allergy is health information the page has no need to carry for children
 * nobody has opened. One learner at a time, on request, recorded on the audit
 * trail like every other read under /health-records.
 *
 * Under /health-records/*, not /nurse/*, for the reason every clinic endpoint
 * shared with Clinic Staff is: EnsureActiveSession re-seeds a prototype
 * session for whichever role a URL belongs to.
 */
class StudentAllergyController extends Controller
{
    /** The desks that record a consultation, and so need the allergies first. */
    public const ROLES = ['school_nurse', 'clinic_staff', 'clinic_teacher'];

    public function show(Request $request, string $lrn): JsonResponse
    {
        if (! in_array((string) $request->session()->get('active_role', ''), self::ROLES, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $institutionId = $request->session()->get('active_institution_id');

        return response()->json([
            'allergies' => LearnerAllergies::for($institutionId ? (int) $institutionId : null, $lrn),
        ]);
    }
}
