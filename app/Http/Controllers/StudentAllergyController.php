<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Support\AllergyCheck;
use App\Support\LearnerAllergies;
use App\Support\MedicineAllergies;
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
        $institutionId = $institutionId ? (int) $institutionId : null;

        return response()->json([
            'allergies' => MedicineAllergies::annotate(LearnerAllergies::for($institutionId, $lrn), $institutionId, $lrn),
        ]);
    }

    /**
     * The allergy check for a medicine about to be given (App\Support\AllergyCheck),
     * shown in the dialog before the save. The save asks again; this answer
     * decides nothing on its own.
     */
    public function check(Request $request): JsonResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        if (! in_array($role, self::ROLES, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! AllergyCheck::supported()) {
            return response()->json(['result' => null]);
        }

        $validated = $request->validate([
            'medicine_id' => ['required', 'integer'],
            'lrn' => ['nullable', 'string', 'max:20'],
        ]);

        $institutionId = $request->session()->get('active_institution_id');
        $medicine = Medicine::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->findOrFail($validated['medicine_id']);

        $check = AllergyCheck::evaluate($role, $medicine, $institutionId ? (int) $institutionId : null, $validated['lrn'] ?? '');

        return response()->json([
            'result' => $check['result'],
            'message' => $check['message'],
            'note' => AllergyCheck::NOTE,
            'requires' => AllergyCheck::requires($check['result']),
        ]);
    }
}
