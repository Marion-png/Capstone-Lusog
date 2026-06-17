<?php

namespace App\Http\Controllers;

use App\Models\MedicalCertificate;
use App\Models\StudentHealthCondition;
use App\Models\StudentHealthRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicalCertificateController extends Controller
{
    /**
     * Upload a medical certificate for a student's health condition.
     * Restricted to class_adviser for their own class only.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            $request->session()->get('active_role') === 'class_adviser',
            403,
            'Only a Class Adviser may upload medical certificates.'
        );

        $validated = $request->validate([
            'lrn'            => ['required', 'string', 'max:50'],
            'condition_name' => ['required', 'string', 'max:255'],
            'certificate'    => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'doctor_clinic'  => ['nullable', 'string', 'max:255'],
            'diagnosis_date' => ['nullable', 'date', 'before_or_equal:today'],
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

        if ($record === null) {
            $record = StudentHealthRecord::create([
                'student_id'         => $validated['lrn'],
                'student_name'       => trim((string) $request->input('student_name', 'Unknown Student')),
                'section'            => $expectedSection,
                'weight'             => (float) $request->input('weight', 0),
                'bmi_value'          => (float) $request->input('bmi_value', 0),
                'nutritional_status' => trim((string) $request->input('nutritional_status', 'Unknown')),
            ]);
        } else {
            abort_unless(
                $record->section === $expectedSection,
                403,
                'You may only upload certificates for students in your assigned class.'
            );
        }

        $condition = StudentHealthCondition::firstOrCreate([
            'student_health_record_id' => $record->id,
            'condition_name'           => trim($validated['condition_name']),
        ]);

        $file         = $request->file('certificate');
        $originalName = $file->getClientOriginalName();
        $safeName     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $path         = $file->storeAs('medical-certificates/' . $record->id, $safeName, 'local');

        MedicalCertificate::create([
            'student_health_condition_id' => $condition->id,
            'file_path'                   => $path,
            'file_original_name'          => $originalName,
            'doctor_clinic'               => $validated['doctor_clinic'] ?? null,
            'diagnosis_date'              => $validated['diagnosis_date'] ?? null,
            'uploaded_by_name'            => (string) $request->session()->get('active_name', 'Class Adviser'),
        ]);

        return back()->with('cert_success', "Certificate for \"{$condition->condition_name}\" uploaded successfully.");
    }

    /**
     * Serve a certificate file for download.
     * Restricted to clinic_staff only.
     */
    public function download(Request $request, int $id): StreamedResponse
    {
        abort_unless(
            in_array($request->session()->get('active_role'), ['clinic_staff', 'school_nurse'], true),
            403,
            'Only Clinic Staff or School Nurse may download medical certificates.'
        );

        $cert = MedicalCertificate::find($id);
        abort_if($cert === null, 404, 'Certificate not found.');

        abort_unless(
            Storage::disk('local')->exists($cert->file_path),
            404,
            'Certificate file not found on disk.'
        );

        return Storage::disk('local')->response(
            $cert->file_path,
            $cert->file_original_name
        );
    }

    /**
     * Return health conditions (with certificate summary) for a student, by LRN.
     * Allowed roles: class_adviser (own class only) and clinic_staff.
     */
    public function getConditions(Request $request): JsonResponse
    {
        $activeRole = (string) $request->session()->get('active_role', '');

        abort_unless(
            in_array($activeRole, ['class_adviser', 'clinic_staff', 'school_nurse'], true),
            403,
            'Access denied.'
        );

        $lrn = (string) $request->query('lrn', '');
        if ($lrn === '') {
            return response()->json(['conditions' => []]);
        }

        $record = StudentHealthRecord::where('student_id', $lrn)->first();
        if ($record === null) {
            return response()->json(['conditions' => []]);
        }

        if ($activeRole === 'class_adviser') {
            $grade   = (string) $request->session()->get('assigned_grade_level', '');
            $section = (string) $request->session()->get('assigned_section', '');
            $expected = trim("{$grade} / {$section}");
            if ($grade === '' || $section === '' || $record->section !== $expected) {
                return response()->json(['conditions' => []]);
            }
        }

        $conditions = StudentHealthCondition::where('student_health_record_id', $record->id)
            ->with('certificates')
            ->get();

        $data = $conditions->map(function (StudentHealthCondition $c) use ($activeRole) {
            $certs = $c->certificates->map(function (MedicalCertificate $cert) use ($activeRole) {
                $entry = [
                    'id'            => $cert->id,
                    'original_name' => $cert->file_original_name,
                    'doctor_clinic' => $cert->doctor_clinic,
                    'diagnosis_date'=> $cert->diagnosis_date?->format('Y-m-d'),
                    'uploaded_by'   => $cert->uploaded_by_name,
                    'uploaded_at'   => $cert->created_at->format('M d, Y'),
                ];
                $entry['download_url'] = route('medical-certificate.download', $cert->id);
                return $entry;
            });

            return [
                'condition_name'    => $c->condition_name,
                'is_verified'       => $certs->isNotEmpty(),
                'certificate_count' => $certs->count(),
                'certificates'      => $certs,
            ];
        });

        return response()->json(['conditions' => $data]);
    }
}
