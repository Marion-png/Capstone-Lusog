<?php

namespace App\Http\Controllers;

use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParentalConsentFormController extends Controller
{
    /**
     * Save parental consent details (and optional signed form upload) for the health services program.
     * Restricted to class_adviser for students in their own assigned class only.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            $request->session()->get('active_role') === 'class_adviser',
            403,
            'Only a Class Adviser may upload parental consent forms.'
        );

        $validated = $request->validate([
            'lrn'                      => ['required', 'string', 'max:50'],
            'consent_type'             => ['required', 'string', 'in:full,partial,refused'],
            'partial_exception'        => ['nullable', 'string', 'max:500'],
            'refused_reason'           => ['nullable', 'string', 'max:500'],
            'allergy_food'             => ['nullable', 'boolean'],
            'allergy_food_detail'      => ['nullable', 'string', 'max:255'],
            'allergy_medicine'         => ['nullable', 'boolean'],
            'allergy_medicine_detail'  => ['nullable', 'string', 'max:255'],
            'prev_immunization'        => ['nullable', 'boolean'],
            'prev_immunization_detail' => ['nullable', 'string', 'max:255'],
            'has_other_illness'        => ['nullable', 'boolean'],
            'other_illness_detail'     => ['nullable', 'string', 'max:255'],
            'medical_cert_attached'    => ['nullable', 'boolean'],
            'consent'                  => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $advisedGrade   = (string) $request->session()->get('assigned_grade_level', '');
        $advisedSection = (string) $request->session()->get('assigned_section', '');

        abort_if(
            $advisedGrade === '' || $advisedSection === '',
            403,
            'Your account has no assigned class.'
        );

        $expectedSection = trim("{$advisedGrade} / {$advisedSection}");

        $record = StudentHealthRecord::where('student_id', $validated['lrn'])->first();

        abort_if(
            $record === null || $record->section !== $expectedSection,
            403,
            'You may only upload consent forms for students in your assigned class.'
        );

        $schoolYear = ParentalConsentForm::currentSchoolYear();
        $path       = null;
        $originalName = null;

        if ($request->hasFile('consent')) {
            $file         = $request->file('consent');
            $originalName = $file->getClientOriginalName();
            $safeName     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            $path         = $file->storeAs('parental-consents/' . $record->id, $safeName, 'local');
        }

        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type'             => 'Deworming',
            'school_year'              => $schoolYear,
            'consent_type'             => $validated['consent_type'],
            'partial_exception'        => $validated['partial_exception'] ?? null,
            'refused_reason'           => $validated['refused_reason'] ?? null,
            'allergy_food'             => !empty($validated['allergy_food']),
            'allergy_food_detail'      => $validated['allergy_food_detail'] ?? null,
            'allergy_medicine'         => !empty($validated['allergy_medicine']),
            'allergy_medicine_detail'  => $validated['allergy_medicine_detail'] ?? null,
            'prev_immunization'        => !empty($validated['prev_immunization']),
            'prev_immunization_detail' => $validated['prev_immunization_detail'] ?? null,
            'has_other_illness'        => !empty($validated['has_other_illness']),
            'other_illness_detail'     => $validated['other_illness_detail'] ?? null,
            'medical_cert_attached'    => !empty($validated['medical_cert_attached']),
            'file_path'                => $path,
            'file_original_name'       => $originalName,
            'uploaded_by_name'         => (string) $request->session()->get('active_name', 'Class Adviser'),
        ]);

        $typeLabel = match($validated['consent_type']) {
            'full'    => 'Full consent',
            'partial' => 'Partial consent',
            'refused' => 'Consent refused',
            default   => 'Consent',
        };

        return back()->with('consent_success', "{$typeLabel} recorded successfully for SY {$schoolYear}.");
    }

    /**
     * Return whether a valid consent form exists for a student for the current school year.
     * Accessible by class_adviser (own class only), clinic_staff, and school_nurse.
     */
    public function consentStatus(Request $request): JsonResponse
    {
        $activeRole = (string) $request->session()->get('active_role', '');

        abort_unless(
            in_array($activeRole, ['class_adviser', 'clinic_staff', 'school_nurse'], true),
            403,
            'Access denied.'
        );

        $lrn = (string) $request->query('lrn', '');
        if ($lrn === '') {
            return response()->json(['has_consent' => false, 'school_year' => ParentalConsentForm::currentSchoolYear()]);
        }

        $record = StudentHealthRecord::where('student_id', $lrn)->first();

        if ($activeRole === 'class_adviser' && $record !== null) {
            $grade   = (string) $request->session()->get('assigned_grade_level', '');
            $section = (string) $request->session()->get('assigned_section', '');
            $expected = trim("{$grade} / {$section}");
            if ($grade === '' || $section === '' || $record->section !== $expected) {
                return response()->json(['has_consent' => false, 'school_year' => ParentalConsentForm::currentSchoolYear()]);
            }
        }

        $schoolYear = ParentalConsentForm::currentSchoolYear();

        if ($record === null) {
            return response()->json(['has_consent' => false, 'school_year' => $schoolYear]);
        }

        $form = ParentalConsentForm::where('student_health_record_id', $record->id)
            ->where('program_type', 'Deworming')
            ->where('school_year', $schoolYear)
            ->latest()
            ->first();

        return response()->json([
            'has_consent'              => $form !== null,
            'school_year'              => $schoolYear,
            'uploaded_by'              => $form?->uploaded_by_name,
            'uploaded_at'              => $form?->created_at?->format('M d, Y'),
            'consent_id'               => $form?->id,
            'consent_type'             => $form?->consent_type,
            'partial_exception'        => $form?->partial_exception,
            'refused_reason'           => $form?->refused_reason,
            'allergy_food'             => (bool) $form?->allergy_food,
            'allergy_food_detail'      => $form?->allergy_food_detail,
            'allergy_medicine'         => (bool) $form?->allergy_medicine,
            'allergy_medicine_detail'  => $form?->allergy_medicine_detail,
            'prev_immunization'        => (bool) $form?->prev_immunization,
            'prev_immunization_detail' => $form?->prev_immunization_detail,
            'has_other_illness'        => (bool) $form?->has_other_illness,
            'other_illness_detail'     => $form?->other_illness_detail,
            'medical_cert_attached'    => (bool) $form?->medical_cert_attached,
            'has_file'                 => $form?->file_path !== null,
        ]);
    }

    /**
     * Serve a consent form file for download.
     * Restricted to school_nurse and clinic_staff.
     */
    public function download(Request $request, int $id): StreamedResponse
    {
        abort_unless(
            in_array($request->session()->get('active_role'), ['clinic_staff', 'school_nurse'], true),
            403,
            'Only Clinic Staff or School Nurse may download consent forms.'
        );

        $form = ParentalConsentForm::find($id);
        abort_if($form === null, 404, 'Consent form not found.');
        abort_if($form->file_path === null, 404, 'No file was uploaded for this consent record.');

        abort_unless(
            Storage::disk('local')->exists($form->file_path),
            404,
            'Consent form file not found on disk.'
        );

        return Storage::disk('local')->response($form->file_path, $form->file_original_name, [], 'inline');
    }
}
