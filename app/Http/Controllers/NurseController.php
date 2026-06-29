<?php

namespace App\Http\Controllers;

use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class NurseController extends Controller
{
    public function index(Request $request): View
    {
        $this->syncInstitutionRecordsToSession($request);

        $rawRecords = $request->session()->get('school_health_card_records', []);

        // Deduplicate by LRN — prefer the entry that already has exam data, otherwise keep first.
        $seen    = [];
        $records = [];
        foreach ($rawRecords as $r) {
            $lrn = (string) ($r['lrn'] ?? '');
            if ($lrn === '') {
                $records[] = $r;
                continue;
            }
            if (!isset($seen[$lrn])) {
                $seen[$lrn]  = count($records);
                $records[]   = $r;
            } elseif (!empty($r['examination']) && empty($records[$seen[$lrn]]['examination'])) {
                $records[$seen[$lrn]] = $r; // replace with the examined copy
            }
        }
        $records = array_values($records);

        $consentByLrn = [];

        if (!empty($records)) {
            $schoolYear = ParentalConsentForm::currentSchoolYear();
            $lrns       = array_values(array_filter(array_column($records, 'lrn'), fn($v) => $v !== null && $v !== ''));

            if (!empty($lrns)) {
                $studentRecords = StudentHealthRecord::whereIn('student_id', $lrns)->get()->keyBy('student_id');
                $studentIds     = $studentRecords->pluck('id')->toArray();

                if (!empty($studentIds)) {
                    $consents = ParentalConsentForm::whereIn('student_health_record_id', $studentIds)
                        ->where('program_type', 'Deworming')
                        ->where('school_year', $schoolYear)
                        ->get()
                        ->keyBy('student_health_record_id');

                    foreach ($lrns as $lrn) {
                        $sr                  = $studentRecords->get($lrn);
                        $consentByLrn[$lrn]  = ($sr !== null && $consents->has($sr->id))
                            ? $consents->get($sr->id)
                            : null;
                    }
                }
            }
        }

        return view('nurse.index', [
            'records'      => $records,
            'consentByLrn' => $consentByLrn,
        ]);
    }

    public function examine(Request $request, int $index): View
    {
        $records = $request->session()->get('school_health_card_records', []);

        if (!isset($records[$index])) {
            abort(404);
        }

        $lrn         = (string) ($records[$index]['lrn'] ?? '');
        $schoolYear  = ParentalConsentForm::currentSchoolYear();
        $consentForm = null;

        if ($lrn !== '') {
            $studentRecord = StudentHealthRecord::where('student_id', $lrn)->first();
            if ($studentRecord !== null) {
                $consentForm = ParentalConsentForm::where('student_health_record_id', $studentRecord->id)
                    ->where('program_type', 'Deworming')
                    ->where('school_year', $schoolYear)
                    ->latest()
                    ->first();
            }
        }

        return view('nurse.examine', [
            'index'             => $index,
            'record'            => $records[$index],
            'consentForm'       => $consentForm,
            'consentSchoolYear' => $schoolYear,
        ]);
    }

    public function saveExamination(Request $request, int $index): RedirectResponse
    {
        $records = $request->session()->get('school_health_card_records', []);

        if (!isset($records[$index])) {
            abort(404);
        }

        // Gate: block if deworming is being marked "given" but no valid consent is on file
        if ($request->input('deworming') === 'V') {
            $lrn           = (string) ($records[$index]['lrn'] ?? '');
            $studentRecord = StudentHealthRecord::where('student_id', $lrn)->first();
            $schoolYear    = ParentalConsentForm::currentSchoolYear();
            $consentForm   = $studentRecord !== null
                ? ParentalConsentForm::where('student_health_record_id', $studentRecord->id)
                    ->where('program_type', 'Deworming')
                    ->where('school_year', $schoolYear)
                    ->latest()
                    ->first()
                : null;

            if ($consentForm === null) {
                return back()
                    ->withInput()
                    ->withErrors(['deworming' => "Cannot proceed — no signed parental consent on file for this student for SY {$schoolYear}."]);
            }

            if ($consentForm->consent_type === 'refused') {
                return back()
                    ->withInput()
                    ->withErrors(['deworming' => "Cannot proceed — the parent/guardian refused consent for health services for SY {$schoolYear}."]);
            }
        }

        $existingHeight = $records[$index]['height_cm'] ?? null;
        $existingWeight = $records[$index]['weight_kg'] ?? null;

        if (empty($records[$index]['baseline_snapshot'])) {
            $records[$index]['baseline_snapshot'] = [
                'height_cm' => $existingHeight,
                'weight_kg' => $existingWeight,
            ];
        }

        $examDateInput = (string) $request->input('date_of_examination', '');
        $examDate = now()->toDateString();
        if ($examDateInput !== '') {
            try {
                $examDate = Carbon::parse($examDateInput)->toDateString();
            } catch (\Throwable $_) {
                $examDate = now()->toDateString();
            }
        }

        $attendanceByMonth = $records[$index]['attendance_by_month'] ?? [];
        if (!is_array($attendanceByMonth)) {
            $attendanceByMonth = [];
        }
        $monthKey = Carbon::parse($examDate)->format('Y-m');
        $attendanceByMonth[$monthKey] = ((int) ($attendanceByMonth[$monthKey] ?? 0)) + 1;
        ksort($attendanceByMonth);
        $records[$index]['attendance_by_month'] = $attendanceByMonth;

        $records[$index]['height_cm'] = $request->input('height_cm', $records[$index]['height_cm'] ?? null);
        $records[$index]['weight_kg'] = $request->input('weight_kg', $records[$index]['weight_kg'] ?? null);
        $lockedBmiStatus = (string) ($records[$index]['nutritional_status_bmi_for_age'] ?? '');
        $lockedHeightAgeStatus = (string) ($records[$index]['nutritional_status_height_for_age'] ?? '');
        $records[$index]['endline_snapshot'] = [
            'height_cm' => $request->input('height_cm'),
            'weight_kg' => $request->input('weight_kg'),
            'nutritional_status_bmi' => $lockedBmiStatus,
        ];
        $records[$index]['examination'] = [
            'date_of_examination' => $examDate,
            'temperature_bp' => $request->input('temperature_bp'),
            'heart_rate' => $request->input('heart_rate'),
            'pulse_rate' => $request->input('pulse_rate'),
            'respiratory_rate' => $request->input('respiratory_rate'),
            'height_cm' => $request->input('height_cm'),
            'weight_kg' => $request->input('weight_kg'),
            'nutritional_status_bmi' => $lockedBmiStatus,
            'nutritional_status_height_age' => $lockedHeightAgeStatus,
            'vision_screening' => $request->input('vision_screening'),
            'auditory_screening' => $request->input('auditory_screening'),
            'skin_scalp' => $request->input('skin_scalp'),
            'eyes_ears_nose' => $request->input('eyes_ears_nose'),
            'mouth_throat_neck' => $request->input('mouth_throat_neck'),
            'lungs_heart' => $request->input('lungs_heart'),
            'abdomen' => $request->input('abdomen'),
            'deformities' => $request->input('deformities'),
            'iron_supplementation' => $request->input('iron_supplementation'),
            'deworming' => $request->input('deworming'),
            'immunization' => $request->input('immunization'),
            'sbfp_beneficiary' => $request->input('sbfp_beneficiary'),
            'four_ps_beneficiary' => $request->input('four_ps_beneficiary'),
            'menarche' => $request->input('menarche'),
            'others' => $request->input('others'),
            'examined_by' => $request->input('examined_by'),
        ];

        $request->session()->put('school_health_card_records', $records);

        return redirect()->route('dashboard.student-health-records')->with('success', 'Medical record saved.');
    }

    /**
     * Pull any student_health_records for this institution from the DB into
     * the nurse's session queue, so adviser submissions from the same school
     * become visible without requiring a shared browser session.
     * Records from other institutions are never added.
     */
    private function syncInstitutionRecordsToSession(Request $request): void
    {
        $institutionId = $request->session()->get('active_institution_id');

        if (!$institutionId || !Schema::hasTable('student_health_records')) {
            return;
        }

        $existing   = collect($request->session()->get('school_health_card_records', []));
        $sessionLrns = $existing
            ->pluck('lrn')
            ->filter()
            ->map(fn ($v) => (string) $v)
            ->flip();

        $dbRecords = StudentHealthRecord::query()
            ->where('institution_id', $institutionId)
            ->get();

        $toAdd = [];
        foreach ($dbRecords as $record) {
            $lrn = (string) $record->student_id;

            if ($sessionLrns->has($lrn)) {
                continue;
            }

            // student_name is stored as "LastName, FirstName MiddleInitial"
            $name     = (string) $record->student_name;
            $commaPos = strpos($name, ', ');
            if ($commaPos !== false) {
                $lastName = substr($name, 0, $commaPos);
                $rest     = substr($name, $commaPos + 2);
                $spacePos = strpos($rest, ' ');
                if ($spacePos !== false) {
                    $firstName  = substr($rest, 0, $spacePos);
                    $middleName = substr($rest, $spacePos + 1);
                } else {
                    $firstName  = $rest;
                    $middleName = '';
                }
            } else {
                $lastName   = $name;
                $firstName  = '';
                $middleName = '';
            }

            // section is stored as "Grade X / SectionName"
            $sectionParts = explode(' / ', (string) $record->section, 2);
            $gradeLevel   = $sectionParts[0] ?? (string) $record->section;
            $section      = $sectionParts[1] ?? '';

            $toAdd[] = [
                'lrn'                               => $lrn,
                'last_name'                         => $lastName,
                'first_name'                        => $firstName,
                'middle_name'                       => $middleName,
                'grade_level'                       => $gradeLevel,
                'section'                           => $section,
                'height_cm'                         => $record->baseline_height_cm,
                'weight_kg'                         => $record->baseline_weight_kg,
                'age'                               => $record->baseline_age,
                'bmi_value'                         => $record->bmi_value,
                'nutritional_status_bmi_for_age'    => $record->nutritional_status,
                'nutritional_status_height_for_age' => null,
                'examination'                       => [],
            ];
        }

        if (!empty($toAdd)) {
            $request->session()->put(
                'school_health_card_records',
                array_merge($existing->all(), $toAdd)
            );
        }
    }
}
