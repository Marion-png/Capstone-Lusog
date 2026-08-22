<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\StudentVitalSigns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Temperature, pulse rate and blood pressure — recorded by the school nurse.
 *
 * The class adviser measures height and weight; those are the two readings a
 * teacher takes, and they are what the feeding programme's BMI is built from.
 * These three are a clinical observation, so the adviser's form shows them
 * and never asks for them, and this is the only endpoint that writes one.
 *
 * The read-only rendering on the adviser's form is presentation. This is the
 * guarantee: a role that is not the nurse is refused here whatever any form
 * on any page happens to render.
 *
 * Routes sit under /health-records/*, not /nurse/*, for the same reason the
 * medical-documents ones do: EnsureActiveSession seeds a demo session for
 * whichever role a URL belongs to, so a prototype adviser opening a /nurse/*
 * URL would be switched to school_nurse before the request arrived — which
 * would hand the role its own restriction back.
 */
class StudentVitalSignsController extends Controller
{
    /**
     * The school nurse takes vital signs. Clinic staff deliberately are not
     * on this list: the brief names the nurse, and widening who may write a
     * clinical reading is a decision for the school, not a convenience.
     */
    private const WRITE_ROLES = ['school_nurse'];

    public function show(Request $request, string $lrn): JsonResponse
    {
        $record = $this->readableRecord($request, $lrn);

        if ($record === null) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['vitals' => StudentVitalSigns::read($record)]);
    }

    public function store(Request $request, string $lrn): JsonResponse
    {
        if (! in_array((string) $request->session()->get('active_role'), self::WRITE_ROLES, true)) {
            return response()->json(['message' => 'Only the school nurse records vital signs.'], 403);
        }

        $record = $this->readableRecord($request, $lrn);

        if ($record === null) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Ranges match what the adviser's form used to enforce, so a reading
        // that was valid before this moved desks is still valid now.
        $validated = $request->validate([
            'temperature_c' => ['nullable', 'numeric', 'between:25,45'],
            'pulse_bpm' => ['nullable', 'integer', 'between:20,250'],
            'blood_pressure' => ['nullable', 'string', 'max:20'],
        ]);

        StudentVitalSigns::write(
            $record,
            $validated,
            (string) $request->session()->get('active_name', 'School Nurse'),
        );

        return response()->json(['vitals' => StudentVitalSigns::read($record->fresh())]);
    }

    /**
     * The learner must be in the caller's own school.
     *
     * Reading is open to the desks that already see a learner's profile — the
     * adviser's form displays these, and the nurse and clinic staff read them
     * on the health-records page. Writing is the nurse's alone, checked
     * separately above.
     */
    private function readableRecord(Request $request, string $lrn): ?StudentHealthRecord
    {
        $role = (string) $request->session()->get('active_role');

        if (! in_array($role, ['school_nurse', 'clinic_staff', 'class_adviser'], true)) {
            return null;
        }

        $institutionId = $request->session()->get('active_institution_id');

        if (! $institutionId) {
            return null;
        }

        return StudentHealthRecord::currentForStudent($lrn, $institutionId);
    }
}
