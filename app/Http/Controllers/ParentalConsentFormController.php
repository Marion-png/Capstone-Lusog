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
     * Upload a signed parental consent form for the Deworming program.
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
            'lrn'     => ['required', 'string', 'max:50'],
            'consent' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
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

        $schoolYear   = ParentalConsentForm::currentSchoolYear();
        $file         = $request->file('consent');
        $originalName = $file->getClientOriginalName();
        $safeName     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $path         = $file->storeAs('parental-consents/' . $record->id, $safeName, 'local');

        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type'             => 'Deworming',
            'school_year'              => $schoolYear,
            'file_path'                => $path,
            'file_original_name'       => $originalName,
            'uploaded_by_name'         => (string) $request->session()->get('active_name', 'Class Adviser'),
        ]);

        return back()->with('consent_success', "Parental consent form uploaded successfully for SY {$schoolYear}.");
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
            'has_consent' => $form !== null,
            'school_year' => $schoolYear,
            'uploaded_by' => $form?->uploaded_by_name,
            'uploaded_at' => $form?->created_at?->format('M d, Y'),
            'consent_id'  => $form?->id,
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

        abort_unless(
            Storage::disk('local')->exists($form->file_path),
            404,
            'Consent form file not found on disk.'
        );

        return Storage::disk('local')->response($form->file_path, $form->file_original_name, [], 'inline');
    }
}
