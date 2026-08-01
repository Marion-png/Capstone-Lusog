<?php

namespace App\Http\Controllers;

use App\Models\HealthAssessment;
use App\Models\HealthConsentForm;
use App\Models\StudentHealthCondition;
use App\Models\StudentHealthRecord;
use App\Support\StudentRosterSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class StudentHealthRecordController extends Controller
{
    public function classAdviserDashboard(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('active_role') !== 'class_adviser') {
            $roleRoutes = [
                'school_nurse' => 'dashboard.school-nurse',
                'clinic_staff' => 'dashboard.clinic-staff',
                'school_head' => 'dashboard.school-head',
                'feeding_coor' => 'dashboard.feedingcor-dashboard',
                'nutricor' => 'dashboard.nutricor-dashboard',
                'system_admin' => 'dashboard.system-admin',
            ];
            $role = (string) $request->session()->get('active_role', '');
            $route = $roleRoutes[$role] ?? 'dashboard.school-nurse';

            return redirect()->route($route);
        }

        $this->stripLegacyDemoRows($request);
        $this->ensureAssignedSchoolName($request);

        // Rebuild the roster from the database so adviser-entered students
        // survive session expiry, re-login, and server restarts.
        StudentRosterSync::syncToSession($request);

        $records = collect();

        if (Schema::hasTable('student_health_records')) {
            $q = StudentHealthRecord::query();
            $institutionId = $request->session()->get('active_institution_id');
            if ($institutionId) {
                $q->where('institution_id', $institutionId);
            }
            $records = $q->forCurrentSchoolYear()->orderByDesc('updated_at')->get();
        }

        $todayCount = $records
            ->filter(fn (StudentHealthRecord $record) => optional($record->updated_at)?->isToday())
            ->count();

        $avgBmi = $records->avg('bmi_value') ?: 0;

        $flaggedCount = $records
            ->filter(function (StudentHealthRecord $record): bool {
                $status = strtolower((string) $record->nutritional_status);

                return str_contains($status, 'wast');
            })
            ->count();

        $lrnsWithCertificates = [];
        if (Schema::hasTable('student_health_conditions') && Schema::hasTable('medical_certificates')) {
            $lrnsWithCertificates = array_flip(
                StudentHealthCondition::query()
                    ->when(
                        $request->session()->get('active_institution_id'),
                        fn ($q, $id) => $q->where('institution_id', $id)
                    )
                    ->whereHas('certificates')
                    ->pluck('student_lrn')
                    ->unique()
                    ->toArray()
            );
        }

        return view('adviser-dashboard.class-adviser', [
            'records' => $records,
            'stats' => [
                'encoded_today' => $todayCount,
                'avg_bmi' => number_format((float) $avgBmi, 1),
                'flagged' => $flaggedCount,
            ],
            'lrnsWithCertificates' => $lrnsWithCertificates,
            'overview' => $this->buildAdviserOverview($request),
            'rosterMeta' => $this->buildRosterMeta($request),
        ]);
    }

    /**
     * Per-learner profile/consent state for the My Students table, keyed by
     * LRN. consent_choice is encrypted, so consent forms are fetched by the
     * plain student_lrn column and classified in PHP.
     *
     * @return array<string, array{has_assessment: bool, consent: string, at_risk: bool}>
     */
    private function buildRosterMeta(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');

        $lrns = collect($request->session()->get('school_health_card_records', []))
            ->pluck('lrn')
            ->filter()
            ->map(fn ($lrn) => (string) $lrn)
            ->unique()
            ->values();

        if ($lrns->isEmpty()) {
            return [];
        }

        $shRecords = Schema::hasTable('student_health_records')
            ? StudentHealthRecord::query()
                ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
                ->whereIn('student_id', $lrns)
                ->forCurrentSchoolYear()
                ->get()
                ->keyBy('student_id')
            : collect();

        $assessments = collect();
        if (Schema::hasTable('health_assessments') && $shRecords->isNotEmpty()) {
            $assessments = HealthAssessment::whereIn('student_health_record_id', $shRecords->pluck('id'))
                ->where('school_year', HealthAssessment::currentSchoolYear())
                ->get()
                ->keyBy('student_health_record_id');
        }

        $consentForms = Schema::hasTable('health_consent_forms')
            ? HealthConsentForm::where('school_year', HealthConsentForm::currentSchoolYear())
                ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
                ->whereIn('student_lrn', $lrns)
                ->get()
                ->keyBy('student_lrn')
            : collect();

        $meta = [];
        foreach ($lrns as $lrn) {
            $shRecord = $shRecords->get($lrn);
            $assessment = $shRecord ? $assessments->get($shRecord->id) : null;
            $consent = $consentForms->get($lrn);

            $meta[$lrn] = [
                'has_assessment' => $assessment !== null,
                'consent' => $this->classifyConsentStatus($consent),
                'at_risk' => (bool) ($shRecord?->is_at_risk),
                // Read-only summaries for the Consent and Feeding Status tabs
                // of the student profile.
                'consent_detail' => [
                    'status' => $consent
                        ? (HealthConsentForm::statusBadges()[$consent->status]['label'] ?? $consent->status)
                        : null,
                    'choice' => $consent ? $this->consentChoiceLabel($consent->consent_choice) : null,
                    'signed_at' => $consent?->signed_at?->toDateString(),
                    'reviewed_at' => $consent?->reviewed_at?->toDateString(),
                ],
                'feeding' => [
                    'baseline_status' => $shRecord?->baseline_nutritional_status,
                    'endline_status' => $shRecord?->endline_nutritional_status,
                    'sessions' => (int) ($shRecord?->attendance_sessions_count ?? 0),
                ],
                'assessment_date' => $assessment?->date_of_assessment?->toDateString(),
            ];
        }

        return $meta;
    }

    private function consentChoiceLabel(?string $choice): ?string
    {
        return match ($choice) {
            HealthConsentForm::CONSENT_ALL => 'Consented to all health services',
            HealthConsentForm::CONSENT_SPECIFIC => 'Consented with exceptions',
            HealthConsentForm::CONSENT_DENY => 'Consent declined',
            default => null,
        };
    }

    /**
     * A consent form only counts as answered once the parent has signed it —
     * drafts and sent-but-unsigned forms are still pending.
     */
    private function classifyConsentStatus(?HealthConsentForm $form): string
    {
        if ($form === null) {
            return 'pending';
        }

        $answered = [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED];
        if (! in_array($form->status, $answered, true)) {
            return 'pending';
        }

        return match ($form->consent_choice) {
            HealthConsentForm::CONSENT_DENY => 'declined',
            HealthConsentForm::CONSENT_SPECIFIC => 'partial',
            default => 'approved',
        };
    }

    /**
     * Redesigned dashboard data: real per-class totals, a "needs attention"
     * list (consent declined/pending, no health profile, at-risk), and a
     * recent-activity feed — all derived from this adviser's own roster
     * (session, scoped to their assigned grade/section) joined against the
     * DB-backed consent and assessment records.
     */
    private function buildAdviserOverview(Request $request): array
    {
        $grade = (string) $request->session()->get('assigned_grade_level', '');
        $section = (string) $request->session()->get('assigned_section', '');
        $institutionId = $request->session()->get('active_institution_id');

        $roster = collect($request->session()->get('school_health_card_records', []))
            ->filter(function ($row) use ($grade, $section) {
                if ($grade === '' || $section === '') {
                    return true;
                }

                return (string) ($row['grade_level'] ?? '') === $grade
                    && strcasecmp(trim((string) ($row['section'] ?? '')), trim($section)) === 0;
            })
            ->unique(fn ($row) => (string) ($row['lrn'] ?? ''))
            ->values();

        $gradeSection = trim("{$grade} / {$section}", ' /') ?: 'Not Assigned';
        $lrns = $roster->pluck('lrn')->filter()->values();

        $empty = [
            'grade_section' => $gradeSection,
            'total' => 0,
            'complete' => 0,
            'pending' => 0,
            'needs_followup' => 0,
            'needs_attention' => collect(),
            'recent_activity' => collect(),
        ];

        if ($lrns->isEmpty()) {
            return $empty;
        }

        $shRecords = Schema::hasTable('student_health_records')
            ? StudentHealthRecord::query()
                ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
                ->whereIn('student_id', $lrns)
                ->forCurrentSchoolYear()
                ->get()
                ->keyBy('student_id')
            : collect();

        $consentForms = Schema::hasTable('health_consent_forms')
            ? HealthConsentForm::where('school_year', HealthConsentForm::currentSchoolYear())
                ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
                ->whereIn('student_lrn', $lrns)
                ->get()
                ->keyBy('student_lrn')
            : collect();

        $assessments = collect();
        if (Schema::hasTable('health_assessments') && $shRecords->isNotEmpty()) {
            $assessments = HealthAssessment::whereIn('student_health_record_id', $shRecords->pluck('id'))
                ->where('school_year', HealthAssessment::currentSchoolYear())
                ->get()
                ->keyBy('student_health_record_id');
        }

        $complete = 0;
        $needsFollowup = 0;
        $needsAttention = collect();
        $activity = collect();

        foreach ($roster as $row) {
            $lrn = (string) ($row['lrn'] ?? '');
            if ($lrn === '') {
                continue;
            }

            $middle = trim((string) ($row['middle_name'] ?? ''));
            $middleInitial = $middle !== '' ? ' '.strtoupper(substr($middle, 0, 1)).'.' : '';
            $name = trim(($row['last_name'] ?? '').', '.($row['first_name'] ?? '').$middleInitial);

            $shRecord = $shRecords->get($lrn);
            $assessment = $shRecord ? $assessments->get($shRecord->id) : null;
            $consent = $consentForms->get($lrn);

            $hasAssessment = $assessment !== null;
            if ($hasAssessment) {
                $complete++;
            }

            $atRisk = (bool) ($shRecord?->is_at_risk);
            $consentDenied = $consent && $consent->consent_choice === HealthConsentForm::CONSENT_DENY;
            $consentPending = $consent && $consent->status === HealthConsentForm::STATUS_SENT;

            $badges = [];
            if ($consentDenied) {
                $badges[] = ['label' => 'Consent form declined', 'tone' => 'bad'];
            } elseif ($consentPending) {
                $badges[] = ['label' => 'Consent form pending', 'tone' => 'warn'];
            }
            if (! $hasAssessment) {
                $badges[] = ['label' => 'Health profile not started', 'tone' => 'warn'];
            }
            if ($atRisk) {
                $badges[] = ['label' => 'Flagged at-risk', 'tone' => 'bad'];
            }

            if ($consentDenied || $atRisk) {
                $needsFollowup++;
            }

            if (! empty($badges)) {
                $needsAttention->push([
                    'lrn' => $lrn,
                    'name' => $name !== ',' ? $name : $lrn,
                    'section' => trim(($row['grade_level'] ?? '').'-'.($row['section'] ?? ''), '-'),
                    'badges' => $badges,
                    'updated_at' => $shRecord?->updated_at ?? $consent?->updated_at,
                ]);
            }

            if ($assessment?->created_at) {
                $activity->push([
                    'icon' => 'profile',
                    'text' => "Health profile completed for {$name}",
                    'badge' => 'PROFILE',
                    'at' => $assessment->created_at,
                ]);
            }
            if ($consent && $consent->status === HealthConsentForm::STATUS_SIGNED && $consent->signed_at) {
                $verb = $consent->consent_choice === HealthConsentForm::CONSENT_DENY ? 'declined' : 'signed';
                $activity->push([
                    'icon' => $consent->consent_choice === HealthConsentForm::CONSENT_DENY ? 'declined' : 'consent',
                    'text' => "Consent form {$verb} by guardian of {$name}",
                    'badge' => 'CONSENT',
                    'at' => $consent->signed_at,
                ]);
            }
        }

        return [
            'grade_section' => $gradeSection,
            'total' => $roster->count(),
            'complete' => $complete,
            'pending' => max(0, $roster->count() - $complete),
            'needs_followup' => $needsFollowup,
            'needs_attention' => $needsAttention->sortByDesc('updated_at')->values()->take(6),
            'recent_activity' => $activity->sortByDesc('at')->values()->take(6),
        ];
    }

    /**
     * Read-only feeding/nutrition status for the adviser's own class —
     * nutritional status, at-risk flag, and feeding-program attendance
     * pulled from student_health_records. Advisers don't manage the feeding
     * program (that's the Feeding Coordinator/Nurse), this is a summary view.
     */
    public function feedingStatus(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('active_role') !== 'class_adviser') {
            return redirect()->route('dashboard.class-adviser');
        }

        $grade = (string) $request->session()->get('assigned_grade_level', '');
        $section = (string) $request->session()->get('assigned_section', '');
        $institutionId = $request->session()->get('active_institution_id');

        $lrns = collect($request->session()->get('school_health_card_records', []))
            ->filter(function ($row) use ($grade, $section) {
                if ($grade === '' || $section === '') {
                    return true;
                }

                return (string) ($row['grade_level'] ?? '') === $grade
                    && strcasecmp(trim((string) ($row['section'] ?? '')), trim($section)) === 0;
            })
            ->pluck('lrn')
            ->filter()
            ->unique()
            ->values();

        $students = collect();
        if (Schema::hasTable('student_health_records') && $lrns->isNotEmpty()) {
            $students = StudentHealthRecord::query()
                ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
                ->whereIn('student_id', $lrns)
                ->forCurrentSchoolYear()
                ->orderBy('student_name')
                ->get();
        }

        return view('adviser-dashboard.feeding-status', [
            'students' => $students,
            'gradeSection' => trim("{$grade} / {$section}", ' /') ?: 'Not Assigned',
        ]);
    }

    /**
     * Remove any leftover built-in demo student rows that older sessions may
     * still carry, so they don't reappear after the demo accounts were removed.
     */
    private function stripLegacyDemoRows(Request $request): void
    {
        $demoLrns = ['100234560201', '100234560202', '100234560203'];
        $existing = $request->session()->get('school_health_card_records', []);
        $cleaned = collect($existing)
            ->reject(fn (array $r) => in_array((string) ($r['lrn'] ?? ''), $demoLrns, true))
            ->values()
            ->all();

        if (count($cleaned) !== count($existing)) {
            $request->session()->put('school_health_card_records', $cleaned);
        }
    }

    /**
     * Ensure assigned_school_name is always populated in the session.
     * Sessions created before this key was introduced will not have it, so we
     * recover it from active_school_name or the accounts table on first access.
     */
    private function ensureAssignedSchoolName(Request $request): void
    {
        if ($request->session()->get('assigned_school_name') !== null) {
            return;
        }

        $schoolName = $request->session()->get('active_school_name');

        if ($schoolName === null && Schema::hasTable('accounts')) {
            $username = strtolower((string) $request->session()->get('active_username', ''));
            if ($username !== '') {
                $account = DB::table('accounts')
                    ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
                    ->when($request->session()->get('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
                    ->first();
                $schoolName = $account?->school_name ?? null;
            }
        }

        if ($schoolName !== null) {
            $request->session()->put('assigned_school_name', $schoolName);
        }
    }

    /**
     * Every school year on file for one student, oldest first — proves
     * grade promotion preserves history instead of overwriting it.
     * Allowed roles: class_adviser (own class only), clinic_staff, school_nurse.
     */
    public function history(Request $request): JsonResponse
    {
        $activeRole = (string) $request->session()->get('active_role', '');

        abort_unless(
            in_array($activeRole, ['class_adviser', 'clinic_staff', 'school_nurse'], true),
            403,
            'Access denied.'
        );

        $lrn = (string) $request->query('lrn', '');
        if ($lrn === '' || ! Schema::hasTable('student_health_records')) {
            return response()->json(['years' => []]);
        }

        $records = StudentHealthRecord::where('student_id', $lrn)
            ->when(
                $request->session()->get('active_institution_id'),
                fn ($q, $id) => $q->where('institution_id', $id)
            )
            ->orderBy('school_year')
            ->get();

        if ($activeRole === 'class_adviser') {
            $grade = (string) $request->session()->get('assigned_grade_level', '');
            $section = (string) $request->session()->get('assigned_section', '');
            $expected = trim("{$grade} / {$section}");
            $current = $records->firstWhere('school_year', StudentHealthRecord::currentSchoolYear());
            if ($grade === '' || $section === '' || $current === null || $current->section !== $expected) {
                return response()->json(['years' => []]);
            }
        }

        return response()->json([
            'years' => $records->map(fn (StudentHealthRecord $r) => [
                'school_year' => $r->school_year,
                'section' => $r->section,
                'is_current' => $r->school_year === StudentHealthRecord::currentSchoolYear(),
                'baseline' => [
                    'height_cm' => $r->baseline_height_cm,
                    'weight_kg' => $r->baseline_weight_kg,
                    'bmi' => $r->baseline_bmi_value,
                    'status' => $r->baseline_nutritional_status,
                    'recorded_at' => optional($r->baseline_recorded_at)->format('M d, Y'),
                ],
                'endline' => [
                    'height_cm' => $r->endline_height_cm,
                    'weight_kg' => $r->endline_weight_kg,
                    'bmi' => $r->endline_bmi_value,
                    'status' => $r->endline_nutritional_status,
                    'recorded_at' => optional($r->endline_recorded_at)->format('M d, Y'),
                ],
            ])->values(),
        ]);
    }

    public function storeBaseline(Request $request): RedirectResponse
    {
        if (! $this->canRecordMeasurements($request)) {
            return redirect()->route('login')->with('error', 'You are not allowed to record baseline data.');
        }

        $validated = $request->validate([
            'student_name' => ['required', 'string', 'max:255'],
            'student_id' => ['required', 'string', 'max:100'],
            'school_name' => ['nullable', 'string', 'max:255'],
            'section' => ['required', 'string', 'max:255'],
            'age' => ['required', 'integer', 'min:2', 'max:25'],
            'height_cm' => ['required', 'numeric', 'min:50', 'max:250'],
            'weight_kg' => ['required', 'numeric', 'min:5', 'max:300'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $bmi = $this->computeBmi((float) $validated['height_cm'], (float) $validated['weight_kg']);
        $status = $this->classifyStatus($bmi, (int) $validated['age']);
        $recordedAt = $validated['recorded_at'] ?? now()->toDateString();

        $institutionId = $request->session()->get('active_institution_id');

        StudentHealthRecord::query()->updateOrCreate(
            [
                'student_id' => $validated['student_id'],
                'institution_id' => $institutionId,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
            ],
            [
                'institution_id' => $institutionId,
                'student_name' => $validated['student_name'],
                'school_name' => $validated['school_name'] ?? session('active_school_name'),
                'section' => $validated['section'],
                'weight' => (float) $validated['weight_kg'],
                'bmi_value' => $bmi,
                'nutritional_status' => $status,
                'baseline_age' => (int) $validated['age'],
                'baseline_height_cm' => (float) $validated['height_cm'],
                'baseline_weight_kg' => (float) $validated['weight_kg'],
                'baseline_bmi_value' => $bmi,
                'baseline_nutritional_status' => $status,
                'baseline_recorded_at' => $recordedAt,
            ]
        );

        return back()->with('success', 'Baseline record saved. BMI and nutritional status were computed automatically.');
    }

    public function storeEndline(Request $request, StudentHealthRecord $record): RedirectResponse
    {
        if (! $this->canRecordMeasurements($request)) {
            return redirect()->route('login')->with('error', 'You are not allowed to record endline data.');
        }

        // Child records inherit school scope from their parent — never serve or
        // write one belonging to another institution.
        $institutionId = $request->session()->get('active_institution_id');
        if ($institutionId && (int) $record->institution_id !== (int) $institutionId) {
            abort(403);
        }

        // Endline is only meaningful once a baseline exists for the same
        // student and program cycle (school year).
        if ($record->baseline_bmi_value === null) {
            return back()->with('error', 'Record the baseline measurement first — endline cannot be entered without a baseline.');
        }

        $validated = $request->validate([
            'age' => ['required', 'integer', 'min:2', 'max:25'],
            'height_cm' => ['required', 'numeric', 'min:50', 'max:250'],
            'weight_kg' => ['required', 'numeric', 'min:5', 'max:300'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $bmi = $this->computeBmi((float) $validated['height_cm'], (float) $validated['weight_kg']);
        $status = $this->classifyStatus($bmi, (int) $validated['age']);
        $recordedAt = $validated['recorded_at'] ?? now()->toDateString();

        $record->update([
            'weight' => (float) $validated['weight_kg'],
            'bmi_value' => $bmi,
            'nutritional_status' => $status,
            'endline_age' => (int) $validated['age'],
            'endline_height_cm' => (float) $validated['height_cm'],
            'endline_weight_kg' => (float) $validated['weight_kg'],
            'endline_bmi_value' => $bmi,
            'endline_nutritional_status' => $status,
            'endline_recorded_at' => $recordedAt,
        ]);

        return back()->with('success', 'Endline record saved. BMI comparison is now available.');
    }

    public function feedingHealthRecords(Request $request): View
    {
        $records = collect();

        if (Schema::hasTable('student_health_records')) {
            $q = StudentHealthRecord::query();
            $institutionId = $request->session()->get('active_institution_id');
            if ($institutionId) {
                $q->where('institution_id', $institutionId);
            }
            // student_name is encrypted at rest, so sorting happens in PHP.
            $records = $q->forCurrentSchoolYear()->get()->sortBy([
                ['section', 'asc'],
                ['student_name', 'asc'],
            ])->values();
        }

        $sessionAtRiskRecords = collect($request->session()->get('school_health_card_records', []))
            ->filter(function (array $row): bool {
                $status = strtolower((string) ($row['nutritional_status_bmi_for_age'] ?? ''));

                return str_contains($status, 'wasted') || str_contains($status, 'underweight');
            })
            ->map(function (array $row): object {
                $middle = trim((string) ($row['middle_name'] ?? ''));
                $middleInitial = $middle !== '' ? (' '.strtoupper(substr($middle, 0, 1)).'.') : '';
                $fullName = trim((string) ($row['last_name'] ?? '').', '.(string) ($row['first_name'] ?? '').$middleInitial);

                $baselineStatus = (string) ($row['nutritional_status_bmi_for_age'] ?? '');
                $baselineBmi = is_numeric($row['bmi_value'] ?? null) ? (float) $row['bmi_value'] : null;

                $endlineBmiRaw = data_get($row, 'endline_snapshot.bmi_value');
                if (! is_numeric($endlineBmiRaw)) {
                    $endlineWeight = data_get($row, 'endline_snapshot.weight_kg');
                    $heightCm = $row['height_cm'] ?? null;
                    if (is_numeric($endlineWeight) && is_numeric($heightCm) && (float) $heightCm > 0) {
                        $heightMeters = ((float) $heightCm) / 100;
                        $endlineBmiRaw = round(((float) $endlineWeight) / ($heightMeters * $heightMeters), 2);
                    }
                }

                return (object) [
                    'student_name' => $fullName !== '' ? $fullName : ((string) ($row['first_name'] ?? 'Unknown Student')),
                    'section' => trim((string) ($row['grade_level'] ?? '').' / '.(string) ($row['section'] ?? '')),
                    'baseline_bmi_value' => $baselineBmi,
                    'baseline_nutritional_status' => $baselineStatus,
                    'endline_bmi_value' => is_numeric($endlineBmiRaw) ? (float) $endlineBmiRaw : null,
                    'endline_nutritional_status' => data_get($row, 'endline_snapshot.nutritional_status_bmi'),
                    'nutritional_status' => $baselineStatus,
                ];
            });

        $records = $records->concat($sessionAtRiskRecords)->values();

        $statusCounts = [
            'severely_wasted' => 0,
            'wasted' => 0,
            'normal' => 0,
            'overweight' => 0,
        ];

        foreach ($records as $record) {
            $key = $this->statusKey((string) $record->nutritional_status);
            $statusCounts[$key]++;
        }

        $sectionSummary = $records
            ->groupBy(fn ($record) => (string) ($record->section ?: 'Unassigned'))
            ->map(function ($sectionRows, string $section): array {
                $counts = [
                    'severely_wasted' => 0,
                    'wasted' => 0,
                    'normal' => 0,
                    'overweight' => 0,
                ];

                foreach ($sectionRows as $row) {
                    $counts[$this->statusKey((string) $row->baseline_nutritional_status ?: (string) $row->nutritional_status)]++;
                }

                return [
                    'section' => $section,
                    'total' => count($sectionRows),
                    'counts' => $counts,
                ];
            })
            ->values();

        return view('feedingcor-dashboard.feed-healthrec', [
            'records' => $records,
            'statusCounts' => $statusCounts,
            'sectionSummary' => $sectionSummary,
        ]);
    }

    /** Baseline/endline measurements may be recorded by the feeding coordinator or class adviser. */
    private function canRecordMeasurements(Request $request): bool
    {
        $role = strtolower(trim((string) $request->session()->get('active_role', '')));

        return in_array($role, ['feeding_coor', 'class_adviser'], true);
    }

    private function computeBmi(float $heightCm, float $weightKg): float
    {
        $heightMeters = $heightCm / 100;
        if ($heightMeters <= 0) {
            return 0;
        }

        return round($weightKg / ($heightMeters * $heightMeters), 2);
    }

    private function classifyStatus(float $bmi, int $age): string
    {
        if ($bmi < 16.0) {
            return 'Severely Wasted';
        }
        if ($bmi < 17.0) {
            return 'Wasted';
        }
        if ($bmi < 18.5) {
            return 'Underweight';
        }
        if ($bmi >= 25.0) {
            return 'Overweight';
        }

        return 'Normal';
    }

    private function statusKey(string $status): string
    {
        $normalized = strtolower($status);

        if (str_contains($normalized, 'severe')) {
            return 'severely_wasted';
        }
        if (str_contains($normalized, 'wast') || str_contains($normalized, 'underweight')) {
            return 'wasted';
        }
        if (str_contains($normalized, 'over')) {
            return 'overweight';
        }

        return 'normal';
    }
}
