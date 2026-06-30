<?php

use App\Http\Controllers\AdviserController;
use App\Http\Controllers\HealthAssessmentController;
use App\Http\Controllers\ConditionController;
use App\Http\Controllers\MedicalCertificateController;
use App\Http\Controllers\ParentalConsentFormController;
use App\Http\Controllers\NutricorController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\FeedingCoordinatorController;
use App\Http\Controllers\FeedingProgramController;
use App\Http\Controllers\MedicineInventoryController;
use App\Http\Controllers\NutritionCoordinatorController;
use App\Http\Controllers\NurseController;
use App\Http\Controllers\SchoolHeadController;
use App\Http\Controllers\StudentHealthRecordController;
use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;

$demoInstitutionId = Schema::hasTable('institutions')
    ? optional(Institution::where('name', 'Demo Elementary School')->first())->id
    : null;

$demoAccounts = [
    ['role' => 'school_nurse',  'label' => 'School Nurse',            'username' => 'nurse.demo',   'password' => 'Demo@123', 'name' => 'Demo Nurse',        'school_name' => 'Demo Elementary School', 'institution_id' => $demoInstitutionId],
    ['role' => 'clinic_staff',  'label' => 'Clinic Staff',            'username' => 'staff.demo',   'password' => 'Demo@123', 'name' => 'Demo Staff',        'school_name' => 'Demo Elementary School', 'institution_id' => $demoInstitutionId],
    ['role' => 'class_adviser', 'label' => 'Class Adviser',           'username' => 'adviser.demo', 'password' => 'Demo@123', 'name' => 'Demo Adviser',      'school_name' => 'Demo Elementary School', 'institution_id' => $demoInstitutionId, 'assigned_grade_level' => 'Grade 1', 'assigned_section' => 'Sampaguita'],
    ['role' => 'school_head',   'label' => 'School Head',             'username' => 'head.demo',    'password' => 'Demo@123', 'name' => 'Demo School Head',  'school_name' => 'Demo Elementary School', 'institution_id' => $demoInstitutionId],
    ['role' => 'feeding_coor',  'label' => 'Feeding Coordinator',     'username' => 'feeding.demo', 'password' => 'Demo@123', 'name' => 'Demo Feeding Coor', 'school_name' => 'Demo Elementary School', 'institution_id' => $demoInstitutionId],
    ['role' => 'nutricor',      'label' => 'Nutritional Coordinator', 'username' => 'nutricor.demo','password' => 'Demo@123', 'name' => 'Demo Nutri-Cor',   'school_name' => 'Demo Elementary School', 'institution_id' => $demoInstitutionId],
];

Route::get('/', function () use ($demoAccounts) {
    if (Schema::hasTable('accounts')) {
        foreach ($demoAccounts as $demo) {
            if (!DB::table('accounts')->where('username', $demo['username'])->exists()) {
                DB::table('accounts')->insert([
                    'name'                 => $demo['name'],
                    'username'             => $demo['username'],
                    'password_hash'        => Hash::make($demo['password']),
                    'role'                 => $demo['role'],
                    'school_name'          => $demo['school_name'] ?? null,
                    'institution_id'       => $demo['institution_id'] ?? null,
                    'assigned_grade_level' => $demo['role'] === 'class_adviser' ? ($demo['assigned_grade_level'] ?? null) : null,
                    'assigned_section'     => $demo['role'] === 'class_adviser' ? ($demo['assigned_section'] ?? null) : null,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }
        }
    }
    return view('auth.login', ['demoAccounts' => $demoAccounts]);
});

Route::get('/login', function () use ($demoAccounts) {
    if (Schema::hasTable('accounts')) {
        foreach ($demoAccounts as $demo) {
            if (!DB::table('accounts')->where('username', $demo['username'])->exists()) {
                DB::table('accounts')->insert([
                    'name'                 => $demo['name'],
                    'username'             => $demo['username'],
                    'password_hash'        => Hash::make($demo['password']),
                    'role'                 => $demo['role'],
                    'school_name'          => $demo['school_name'] ?? null,
                    'institution_id'       => $demo['institution_id'] ?? null,
                    'assigned_grade_level' => $demo['role'] === 'class_adviser' ? ($demo['assigned_grade_level'] ?? null) : null,
                    'assigned_section'     => $demo['role'] === 'class_adviser' ? ($demo['assigned_section'] ?? null) : null,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }
        }
    }
    return view('auth.login', ['demoAccounts' => $demoAccounts]);
})->name('login');

Route::get('/admin-login', function () {
    return view('auth.admin-login');
})->name('admin.login');

Route::get('/account-request', function () {
    $institutions = collect();

    if (Schema::hasTable('institutions')) {
        if (! Institution::active()->exists()) {
            Institution::seedDefaults();
        }

        $institutions = Institution::active()->orderBy('name')->get(['id', 'name']);
    }

    return view('auth.account-request', ['institutions' => $institutions]);
})->name('account.request');

Route::post('/account-request', function (Request $request) {
    $scopedRoles = ['school_nurse', 'clinic_staff', 'class_adviser', 'school_head', 'feeding_coor', 'nutricor'];

    $validated = $request->validate([
        'name'                 => ['required', 'string', 'max:255'],
        'username'             => ['required', 'string', 'max:255'],
        'password'             => ['required', 'string', 'min:6', 'confirmed'],
        'role'                 => ['required', 'in:school_nurse,clinic_staff,class_adviser,school_head,feeding_coor,nutricor'],
        'institution_id'       => ['nullable', 'integer', 'exists:institutions,id'],
        'assigned_grade_level' => ['required_if:role,class_adviser', 'nullable', 'string', 'max:50'],
        'assigned_section'     => ['required_if:role,class_adviser', 'nullable', 'string', 'max:100'],
    ]);

    $role = $validated['role'];

    // Scoped roles must select a school
    if (in_array($role, $scopedRoles, true) && empty($validated['institution_id'])) {
        return back()
            ->withErrors(['institution_id' => 'Please select your school.'])
            ->withInput();
    }

    $username = strtolower(trim($validated['username']));

    $alreadyPending = Schema::hasTable('account_requests') && DB::table('account_requests')
        ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
        ->where('status', 'pending')
        ->exists();

    $alreadyApproved = Schema::hasTable('accounts') && DB::table('accounts')
        ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
        ->exists();

    if ($alreadyPending || $alreadyApproved) {
        return back()
            ->withErrors(['username' => 'A request or account with this username already exists.'])
            ->withInput();
    }

    $institutionId = in_array($role, $scopedRoles, true) ? ((int) $validated['institution_id']) : null;
    $institution   = $institutionId ? Institution::find($institutionId) : null;

    DB::table('account_requests')->insert([
        'id'                   => (string) str()->uuid(),
        'name'                 => $validated['name'],
        'username'             => $validated['username'],
        'password_hash'        => Hash::make((string) $validated['password']),
        'role'                 => $role,
        'institution_id'       => $institutionId,
        'school_name'          => $institution?->name,
        'assigned_grade_level' => $role === 'class_adviser' ? ($validated['assigned_grade_level'] ?? null) : null,
        'assigned_section'     => $role === 'class_adviser' ? ($validated['assigned_section'] ?? null) : null,
        'status'               => 'pending',
        'decided_at'           => null,
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);

    return redirect()
        ->route('account.request')
        ->with('success', 'Account request submitted. Please wait for System Admin approval.');
})->name('account.request.submit');

// Prototype flow: Class Adviser -> School Nurse (Session-based, no database)
Route::get('/adviser/create', [AdviserController::class, 'create'])
    ->name('adviser.create');

Route::post('/adviser/store', [AdviserController::class, 'store'])
    ->name('adviser.store');

Route::get('/adviser/success', [AdviserController::class, 'success'])
    ->name('adviser.success');

Route::get('/nurse', [NurseController::class, 'index'])
    ->name('nurse.index');

Route::get('/nurse/{index}/examine', [NurseController::class, 'examine'])
    ->whereNumber('index')
    ->name('nurse.examine');

Route::post('/nurse/{index}/examine', [NurseController::class, 'saveExamination'])
    ->whereNumber('index')
    ->name('nurse.examine.save');

Route::get('/dashboard/school-nurse', function (Request $request) {
    $institutionId = $request->session()->get('active_institution_id');

    $totalRecords      = 0;
    $consultationsToday = 0;
    $atRiskCount       = 0;
    $lowStockCount     = 0;
    $recentConsultations = collect();
    $topConditions       = collect();
    $lowStockMedicines   = collect();

    if (Schema::hasTable('student_health_records')) {
        $totalRecords = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->count();
        $atRiskCount = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->where('is_at_risk', true)
            ->count();
    }

    if (Schema::hasTable('consultations')) {
        $consultationsToday = Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereDate('consulted_at', now()->toDateString())
            ->count();

        $recentConsultations = Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->latest('consulted_at')->latest('id')
            ->limit(8)
            ->get();

        $topConditions = Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereMonth('consulted_at', now()->month)
            ->whereYear('consulted_at', now()->year)
            ->selectRaw('LOWER(condition) as condition_name, COUNT(*) as total')
            ->groupBy('condition_name')
            ->orderByDesc('total')
            ->limit(4)
            ->get();
    }

    if (Schema::hasTable('medicines')) {
        $lowStockCount = Medicine::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereColumn('stock_quantity', '<=', 'minimum_threshold')
            ->count();

        $lowStockMedicines = Medicine::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereColumn('stock_quantity', '<=', 'minimum_threshold')
            ->orderBy('stock_quantity')
            ->limit(4)
            ->get();
    }

    return view('dashboard.school-nurse', compact(
        'totalRecords', 'consultationsToday', 'atRiskCount', 'lowStockCount',
        'recentConsultations', 'topConditions', 'lowStockMedicines'
    ));
})->name('dashboard.school-nurse');

Route::get('/dashboard/student-health-records', function () {
    $role = (string) session('active_role', '');
    if (!in_array($role, ['school_nurse', 'clinic_staff'], true)) {
        $redirectByRole = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head'   => 'dashboard.school-head',
            'feeding_coor'  => 'dashboard.feedingcor-dashboard',
            'nutricor'      => 'dashboard.nutricor-dashboard',
            'system_admin'  => 'dashboard.system-admin',
        ];
        return redirect()->route($redirectByRole[$role] ?? 'login');
    }
    return view('dashboard.student-health-records');
})->name('dashboard.student-health-records');

Route::get('/dashboard/school-nurse/deworming', function (Request $request) {
    $institutionId = $request->session()->get('active_institution_id');

    if (Schema::hasTable('deworming_requests')) {
        $q = DB::table('deworming_requests');
        if ($institutionId) {
            $q->where('institution_id', $institutionId);
        }
        $requests = $q->orderByDesc('submitted_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values();
    } else {
        $requests = collect($request->session()->get('deworming_requests', []))
            ->sortByDesc('submitted_at')
            ->values();
    }

    return view('dashboard.school-nurse-deworming', [
        'dewormingRequests' => $requests,
    ]);
})->name('dashboard.school-nurse.deworming');

Route::post('/dashboard/school-nurse/deworming/{requestId}/{decision}', function (Request $request, string $requestId, string $decision) {
    $activeRole = strtolower(trim((string) $request->session()->get('active_role', '')));
    $allowedReviewerRoles = ['school_nurse', 'school nurse', 'clinic_staff', 'clinic staff', 'nurse'];

    if (!in_array($activeRole, $allowedReviewerRoles, true)) {
        return redirect()->route('dashboard.school-nurse')->with('error', 'Only School Nurse can review deworming requests.');
    }

    if (Schema::hasTable('deworming_requests')) {
        $exists = DB::table('deworming_requests')
            ->where('id', $requestId)
            ->exists();

        if (!$exists) {
            return back()->with('error', 'Deworming request not found.');
        }

        DB::table('deworming_requests')
            ->where('id', $requestId)
            ->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'reviewed_by' => (string) $request->session()->get('active_name', 'School Nurse'),
                'released_date' => now()->toDateString(),
                'updated_at' => now(),
            ]);
    } else {
        $requests = collect($request->session()->get('deworming_requests', []));
        $index = $requests->search(fn (array $item): bool => (string) ($item['id'] ?? '') === $requestId);

        if ($index === false) {
            return back()->with('error', 'Deworming request not found.');
        }

        $requests[$index]['status'] = 'approved';
        $requests[$index]['reviewed_at'] = now()->toIso8601String();
        $requests[$index]['reviewed_by'] = (string) $request->session()->get('active_name', 'School Nurse');
        $requests[$index]['released_date'] = now()->toDateString();

        $request->session()->put('deworming_requests', $requests->values()->all());
    }

    return back()->with('success', 'Deworming request accepted successfully.');
})->whereIn('decision', ['accept'])->name('dashboard.school-nurse.deworming.decide');

Route::post('/dashboard/school-nurse/deworming/{requestId}/comment', function (Request $request, string $requestId) {
    $activeRole = strtolower(trim((string) $request->session()->get('active_role', '')));
    $allowedReviewerRoles = ['school_nurse', 'school nurse', 'clinic_staff', 'clinic staff', 'nurse'];

    if (!in_array($activeRole, $allowedReviewerRoles, true)) {
        return redirect()->route('dashboard.school-nurse')->with('error', 'Only School Nurse can add comments to deworming requests.');
    }

    $validated = $request->validate([
        'nurse_comment' => ['required', 'string', 'max:500'],
    ]);

    if (Schema::hasTable('deworming_requests')) {
        $exists = DB::table('deworming_requests')
            ->where('id', $requestId)
            ->exists();

        if (!$exists) {
            return back()->with('error', 'Deworming request not found.');
        }

        DB::table('deworming_requests')
            ->where('id', $requestId)
            ->update([
                'status' => 'commented',
                'nurse_comment' => trim((string) $validated['nurse_comment']),
                'commented_at' => now(),
                'reviewed_by' => (string) $request->session()->get('active_name', 'School Nurse'),
                'released_date' => null,
                'updated_at' => now(),
            ]);
    } else {
        $requests = collect($request->session()->get('deworming_requests', []));
        $index = $requests->search(fn (array $item): bool => (string) ($item['id'] ?? '') === $requestId);

        if ($index === false) {
            return back()->with('error', 'Deworming request not found.');
        }

        $requests[$index]['status'] = 'commented';
        $requests[$index]['nurse_comment'] = trim((string) $validated['nurse_comment']);
        $requests[$index]['commented_at'] = now()->toIso8601String();
        $requests[$index]['reviewed_by'] = (string) $request->session()->get('active_name', 'School Nurse');
        $requests[$index]['released_date'] = null;

        $request->session()->put('deworming_requests', $requests->values()->all());
    }

    return back()->with('success', 'Comment added to deworming request.');
})->name('dashboard.school-nurse.deworming.comment');

Route::get('/dashboard/consultation-log', [ConsultationController::class, 'index'])
    ->name('dashboard.consultation-log');

Route::get('/dashboard/consultation-log/new', [ConsultationController::class, 'create'])
    ->name('consultations.create');

Route::post('/dashboard/consultation-log', [ConsultationController::class, 'store'])
    ->name('consultations.store');

// API: list active institutions for registration dropdown
Route::get('/api/institutions', function () {
    if (! Schema::hasTable('institutions')) {
        return response()->json([]);
    }

    if (Schema::hasTable('institutions') && ! Institution::active()->exists()) {
        Institution::seedDefaults();
    }

    return Institution::active()->orderBy('name')->get(['id', 'name']);
})->name('api.institutions.index');

// API routes for condition search and creation
Route::get('/api/conditions', [ConditionController::class, 'index'])
    ->name('api.conditions.index');

Route::post('/api/conditions', [ConditionController::class, 'store'])
    ->name('api.conditions.store');

Route::get('/dashboard/data-visualization', function () {
    return view('dashboard.data-visualization');
})->name('dashboard.data-visualization');

Route::get('/dashboard/medicine-inventory', [MedicineInventoryController::class, 'index'])
    ->name('dashboard.medicine-inventory');

Route::get('/dashboard/medicine-inventory/new', [MedicineInventoryController::class, 'create'])
    ->name('medicine-inventory.create');

Route::post('/dashboard/medicine-inventory', [MedicineInventoryController::class, 'store'])
    ->name('medicine-inventory.store');

Route::get('/dashboard/clinic-staff', function () {
    $atRiskStudents = collect();

    if (Schema::hasTable('student_health_records')) {
        $q = StudentHealthRecord::query()->where('is_at_risk', true);
        $institutionId = session('active_institution_id');
        if ($institutionId) {
            $q->where('institution_id', $institutionId);
        }
        $atRiskStudents = $q
            ->orderByDesc('attendance_sessions_count')
            ->orderBy('student_name')
            ->take(8)
            ->get();
    }

    return view('dashboard.clinic-staff', [
        'atRiskStudents' => $atRiskStudents,
    ]);
})->name('dashboard.clinic-staff');

Route::get('/dashboard/class-adviser', [StudentHealthRecordController::class, 'classAdviserDashboard'])
    ->name('dashboard.class-adviser');

Route::get('/dashboard/class-adviser/deworming', function (Request $request) {
    $assignedGradeLevel = (string) $request->session()->get('assigned_grade_level', '');
    $assignedSection = (string) $request->session()->get('assigned_section', '');
    $institutionId = $request->session()->get('active_institution_id');

    if (Schema::hasTable('deworming_requests')) {
        $query = DB::table('deworming_requests');
        if ($institutionId) {
            $query->where('institution_id', $institutionId);
        }
        if ($assignedGradeLevel !== '' && $assignedSection !== '') {
            $query
                ->where('grade_level', $assignedGradeLevel)
                ->where('section', $assignedSection);
        }

        $requests = $query
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    } else {
        $requests = collect($request->session()->get('deworming_requests', []))
            ->filter(function (array $item) use ($assignedGradeLevel, $assignedSection): bool {
                if ($assignedGradeLevel === '' || $assignedSection === '') {
                    return true;
                }

                return (string) ($item['grade_level'] ?? '') === $assignedGradeLevel
                    && (string) ($item['section'] ?? '') === $assignedSection;
            })
            ->sortByDesc('submitted_at')
            ->values()
            ->all();
    }

    return view('adviser-dashboard.deworming', [
        'dewormingRequests' => $requests,
        'assignedGradeLevel' => $assignedGradeLevel,
        'assignedSection' => $assignedSection,
    ]);
})->name('dashboard.class-adviser.deworming');

Route::post('/dashboard/class-adviser/deworming', function (Request $request) {
    // Keep submission working even if the active role changed in another tab.
    // This avoids losing adviser input and still forwards requests to School Nurse view.
    $submittedByRole = (string) $request->session()->get('active_role', 'class_adviser');

    $validated = $request->validate([
        'campaign' => ['required', 'in:start,end'],
        'total_students' => ['required', 'integer', 'min:1'],
        'consenting_students' => ['required', 'integer', 'min:1'],
    ]);

    if ((int) $validated['consenting_students'] > (int) $validated['total_students']) {
        return back()->withErrors(['consenting_students' => 'Consenting students cannot exceed total students.'])->withInput();
    }

    $newRequest = [
        'id'                  => (string) str()->uuid(),
        'submitted_at'        => now(),
        'submitted_by'        => (string) $request->session()->get('active_name', 'Class Adviser'),
        'submitted_by_role'   => $submittedByRole,
        'campaign'            => $validated['campaign'],
        'total_students'      => (int) $validated['total_students'],
        'consenting_students' => (int) $validated['consenting_students'],
        'tablets_requested'   => (int) $validated['consenting_students'],
        'status'              => 'pending',
        'released_date'       => null,
        'grade_level'         => (string) $request->session()->get('assigned_grade_level', ''),
        'section'             => (string) $request->session()->get('assigned_section', ''),
        'institution_id'      => $request->session()->get('active_institution_id'),
        'nurse_comment'       => null,
        'commented_at'        => null,
        'reviewed_at'         => null,
        'reviewed_by'         => null,
    ];

    if (Schema::hasTable('deworming_requests')) {
        DB::table('deworming_requests')->insert([
            ...$newRequest,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $requests = $request->session()->get('deworming_requests', []);
        $newRequest['submitted_at'] = now()->toIso8601String();
        $requests[] = $newRequest;
        $request->session()->put('deworming_requests', $requests);
    }

    return redirect()
        ->route('dashboard.class-adviser.deworming')
        ->with('success', 'Deworming request submitted successfully and sent to School Nurse monitoring.');
})->name('dashboard.class-adviser.deworming.store');

Route::get('/dashboard/school-head', [SchoolHeadController::class, 'index'])
    ->name('dashboard.school-head');

Route::get('/dashboard/school-head/reports', [SchoolHeadController::class, 'reports'])
    ->name('dashboard.school-head.reports');

Route::get('/dashboard/feedingcor-dashboard', [FeedingCoordinatorController::class, 'dashboard'])
    ->name('dashboard.feedingcor-dashboard');

Route::get('/dashboard/nutricor-dashboard', [NutritionCoordinatorController::class, 'dashboard'])
    ->name('dashboard.nutricor-dashboard');

Route::get('/dashboard/nutricor-beneficiaries', [NutritionCoordinatorController::class, 'beneficiaries'])
    ->name('dashboard.nutricor-beneficiaries');

Route::get('/dashboard/nutricor-analytics', [NutritionCoordinatorController::class, 'analytics'])
    ->name('dashboard.nutricor-analytics');

Route::get('/dashboard/nutricor-atrisk', [NutritionCoordinatorController::class, 'atRisk'])
    ->name('dashboard.nutricor-atrisk');

Route::get('/dashboard/nutricor-reports', [NutritionCoordinatorController::class, 'reports'])
    ->name('dashboard.nutricor-reports');

Route::get('/dashboard/nutricor-comparison', [NutritionCoordinatorController::class, 'comparison'])
    ->name('dashboard.nutricor-comparison');

Route::get('/dashboard/nutricor-consolidated', [NutricorController::class, 'consolidatedReport'])
    ->name('dashboard.nutricor-consolidated');

Route::get('/dashboard/feedingcor-sbfp-forms', [FeedingCoordinatorController::class, 'sbfpForms'])
    ->name('dashboard.feedingcor-sbfp-forms');

Route::get('/dashboard/feedingcor-health-records', [StudentHealthRecordController::class, 'feedingHealthRecords'])
    ->name('dashboard.feedingcor-health-records');

Route::post('/dashboard/class-adviser/health-records/baseline', [StudentHealthRecordController::class, 'storeBaseline'])
    ->name('class-adviser.health-records.baseline.store');

Route::post('/dashboard/class-adviser/health-records/{record}/endline', [StudentHealthRecordController::class, 'storeEndline'])
    ->name('class-adviser.health-records.endline.store');

// Medical certificate upload (class_adviser only, own class enforced in controller)
Route::post('/adviser/medical-certificate', [MedicalCertificateController::class, 'store'])
    ->name('medical-certificate.store');

// Medical certificate download (clinic_staff only, enforced in controller)
Route::get('/medical-certificate/{id}/download', [MedicalCertificateController::class, 'download'])
    ->whereNumber('id')
    ->name('medical-certificate.download');

// API: fetch health conditions for a student by LRN (class_adviser or clinic_staff)
Route::get('/api/student-conditions', [MedicalCertificateController::class, 'getConditions'])
    ->name('api.student-conditions');

// Parental consent form upload (class_adviser only, own class enforced in controller)
Route::post('/adviser/parental-consent', [ParentalConsentFormController::class, 'store'])
    ->name('parental-consent.store');

// Parental consent form download (school_nurse / clinic_staff only, enforced in controller)
Route::get('/parental-consent/{id}/download', [ParentalConsentFormController::class, 'download'])
    ->whereNumber('id')
    ->name('parental-consent.download');

// API: check deworming consent status for a student by LRN (class_adviser, clinic_staff, school_nurse)
Route::get('/api/student-consent-status', [ParentalConsentFormController::class, 'consentStatus'])
    ->name('api.student-consent-status');

// Health Assessment (MLHAT) — class_adviser submit, nurse/staff read
Route::post('/adviser/health-assessment', [HealthAssessmentController::class, 'store'])
    ->name('health-assessment.store');
Route::get('/api/student-health-assessment', [HealthAssessmentController::class, 'status'])
    ->name('api.student-health-assessment');

Route::get('/dashboard/feedingcor-program', function (Request $request) {
    $activeRole = strtolower(trim((string) $request->session()->get('active_role', '')));

    if ($activeRole === 'school_nurse') {
        return redirect()
            ->route('dashboard.school-nurse.feeding-program')
            ->with('error', 'School Nurse has view-only access to Feeding Program.');
    }

    return app(FeedingProgramController::class)->index($request);
})->name('dashboard.feedingcor-program');

Route::get('/dashboard/school-nurse/feeding-program', [FeedingProgramController::class, 'index'])
    ->name('dashboard.school-nurse.feeding-program');

Route::post('/dashboard/feedingcor-program/attendance', [FeedingProgramController::class, 'storeAttendance'])
    ->name('feedingcor-program.attendance.store');

Route::post('/dashboard/school-head/approvals/{approval}/{decision}', [SchoolHeadController::class, 'decide'])
    ->whereIn('decision', ['approve', 'decline'])
    ->name('dashboard.school-head.approvals.decide');

Route::get('/dashboard/system-admin', function () {
    $activeRole = session('active_role');
    if ($activeRole !== 'system_admin') {
        if (!$activeRole) {
            return redirect()
                ->route('login')
                ->with('error', 'Please sign in as System Admin to access this page.');
        }

        $routeByRole = [
            'school_nurse' => 'dashboard.school-nurse',
            'clinic_staff' => 'dashboard.clinic-staff',
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
        ];

        return redirect()
            ->route($routeByRole[$activeRole] ?? 'dashboard.school-nurse')
            ->with('error', 'You are not authorized to open the System Admin page.');
    }

    $accounts = Schema::hasTable('accounts')
        ? DB::table('accounts')->orderByDesc('created_at')->get()->map(fn ($r) => (array) $r)->values()->all()
        : [];

    $pendingRequests = Schema::hasTable('account_requests')
        ? DB::table('account_requests')->where('status', 'pending')->orderByDesc('created_at')->get()->map(fn ($r) => (array) $r)->values()->all()
        : [];

    $requestHistory = Schema::hasTable('account_requests')
        ? DB::table('account_requests')->whereIn('status', ['accepted', 'declined'])->orderByDesc('decided_at')->get()->map(fn ($r) => array_merge((array) $r, ['submitted_at' => $r->created_at]))->values()->all()
        : [];

    return view('dashboard.system-admin', [
        'accounts' => $accounts,
        'pendingRequests' => $pendingRequests,
        'requestHistory' => $requestHistory,
    ]);
})->name('dashboard.system-admin');

Route::post('/dashboard/system-admin/accounts', function (Request $request) {
    if ($request->session()->get('active_role') !== 'system_admin') {
        return redirect()
            ->route('login')
            ->with('error', 'Only System Admin can create user accounts.');
    }

    $scopedRoles = ['school_nurse', 'clinic_staff', 'class_adviser', 'school_head', 'feeding_coor', 'nutricor'];

    $validated = $request->validate([
        'name'                 => ['required', 'string', 'max:255'],
        'username'             => ['required', 'string', 'max:255'],
        'role'                 => ['required', 'in:school_nurse,clinic_staff,class_adviser,school_head,feeding_coor,nutricor'],
        'institution_id'       => ['nullable', 'integer', 'exists:institutions,id'],
        'assigned_grade_level' => ['required_if:role,class_adviser', 'nullable', 'string', 'max:50'],
        'assigned_section'     => ['required_if:role,class_adviser', 'nullable', 'string', 'max:100'],
    ]);

    $role = $validated['role'];

    if (in_array($role, $scopedRoles, true) && empty($validated['institution_id'])) {
        return back()
            ->withErrors(['institution_id' => 'Please select a school for this role.'])
            ->withInput();
    }

    $username = strtolower(trim($validated['username']));

    $alreadyExists = Schema::hasTable('accounts') && DB::table('accounts')
        ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
        ->exists();

    if ($alreadyExists) {
        return back()
            ->withErrors(['username' => 'An account with this username already exists.'])
            ->withInput();
    }

    $institutionId = in_array($role, $scopedRoles, true) ? ((int) $validated['institution_id']) : null;
    $institution   = $institutionId ? Institution::find($institutionId) : null;

    DB::table('accounts')->insert([
        'name'                 => $validated['name'],
        'username'             => $validated['username'],
        'password_hash'        => null,
        'role'                 => $role,
        'institution_id'       => $institutionId,
        'school_name'          => $institution?->name,
        'assigned_grade_level' => $role === 'class_adviser' ? ($validated['assigned_grade_level'] ?? null) : null,
        'assigned_section'     => $role === 'class_adviser' ? ($validated['assigned_section'] ?? null) : null,
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);

    return back()->with('success', 'User account created successfully.');
})->name('dashboard.system-admin.accounts.store');

Route::post('/dashboard/system-admin/requests/{requestId}/approve', function (Request $request, string $requestId) {
    if ($request->session()->get('active_role') !== 'system_admin') {
        return redirect()
            ->route('login')
            ->with('error', 'Only System Admin can approve account requests.');
    }

    $target = Schema::hasTable('account_requests')
        ? DB::table('account_requests')->where('id', $requestId)->first()
        : null;

    if (!$target) {
        return back()->with('error', 'Account request not found.');
    }

    $username = strtolower(trim((string) ($target->username ?? '')));
    $role = (string) ($target->role ?? '');

    $alreadyExists = Schema::hasTable('accounts') && DB::table('accounts')
        ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
        ->exists();

    if (!$alreadyExists && Schema::hasTable('accounts')) {
        DB::table('accounts')->insert([
            'name'                 => $target->name ?? '-',
            'username'             => $target->username ?? '-',
            'password_hash'        => $target->password_hash ?? null,
            'role'                 => $role,
            'institution_id'       => $target->institution_id ?? null,
            'school_name'          => $target->school_name ?? null,
            'assigned_grade_level' => $role === 'class_adviser' ? ($target->assigned_grade_level ?? null) : null,
            'assigned_section'     => $role === 'class_adviser' ? ($target->assigned_section ?? null) : null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    DB::table('account_requests')->where('id', $requestId)->update([
        'status'     => 'accepted',
        'decided_at' => now(),
        'updated_at' => now(),
    ]);

    return back()->with('success', 'Account request approved and account created.');
})->name('dashboard.system-admin.requests.approve');

Route::post('/dashboard/system-admin/requests/{requestId}/decline', function (Request $request, string $requestId) {
    if ($request->session()->get('active_role') !== 'system_admin') {
        return redirect()
            ->route('login')
            ->with('error', 'Only System Admin can decline account requests.');
    }

    $target = Schema::hasTable('account_requests')
        ? DB::table('account_requests')->where('id', $requestId)->first()
        : null;

    if (!$target) {
        return back()->with('error', 'Account request not found.');
    }

    DB::table('account_requests')->where('id', $requestId)->update([
        'status'     => 'declined',
        'decided_at' => now(),
        'updated_at' => now(),
    ]);

    return back()->with('success', 'Account request declined.');
})->name('dashboard.system-admin.requests.decline');

// Compatibility route names used by dashboard Blade templates.
Route::get('/dashboard', function () {
    return redirect()->route('dashboard.school-nurse');
})->name('dashboard');

Route::get('/health-records', function () {
    return redirect()->route('dashboard.student-health-records');
})->name('health-records.index');

Route::post('/health-records', function (Request $request) {
    // Placeholder submit target until records are persisted in the database.
    return back();
})->name('health-records.store');

Route::post('/logout', function (Request $request) {
    Auth::logout();

    $request->session()->forget(['assigned_grade_level', 'assigned_section', 'assigned_school_name', 'active_role', 'active_name', 'active_username', 'active_school_name', 'active_institution_id']);
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => ['required', 'string'],
        'password' => ['required', 'string'],
    ]);

    $username = strtolower(trim((string) $request->input('email')));
    $matchingAccounts = collect(
        Schema::hasTable('accounts')
            ? DB::table('accounts')->whereRaw('LOWER(TRIM(username)) = ?', [$username])->get()->map(fn ($r) => (array) $r)->values()->all()
            : []
    );

    if ($matchingAccounts->isEmpty()) {
        return back()
            ->withInput()
            ->with('error', 'Account was not found. Please submit a Create Account request first.');
    }

    if ($matchingAccounts->count() > 1) {
        return back()
            ->withInput()
            ->with('error', 'Multiple roles are linked to this username. Contact System Admin to keep one unique role per username.');
    }

    $account = $matchingAccounts->first();
    $role = (string) ($account['role'] ?? '');
    $passwordHash = (string) ($account['password_hash'] ?? '');

    if ($passwordHash !== '' && !Hash::check((string) $request->input('password'), $passwordHash)) {
        return back()
            ->withInput(['email' => $request->input('email')])
            ->with('error', 'Invalid username or password.');
    }

    if ($role === 'class_adviser') {
        $request->session()->put('assigned_grade_level', $account['assigned_grade_level'] ?? null);
        $request->session()->put('assigned_section', $account['assigned_section'] ?? null);
        $request->session()->put('assigned_school_name', $account['school_name'] ?? null);
    } else {
        $request->session()->forget(['assigned_grade_level', 'assigned_section', 'assigned_school_name']);
    }

    $request->session()->put('active_role', $role);
    $request->session()->put('active_name', (string) ($account['name'] ?? 'User'));
    $request->session()->put('active_username', (string) ($account['username'] ?? ''));
    $request->session()->put('active_school_name', $account['school_name'] ?? null);
    $request->session()->put('active_institution_id', $account['institution_id'] ?? null);

    $routeByRole = [
        'school_nurse' => 'dashboard.school-nurse',
        'clinic_staff' => 'dashboard.clinic-staff',
        'class_adviser' => 'dashboard.class-adviser',
        'school_head' => 'dashboard.school-head',
        'feeding_coor' => 'dashboard.feedingcor-dashboard',
        'nutricor' => 'dashboard.nutricor-dashboard',
        'system_admin' => 'dashboard.system-admin',
    ];

    return redirect()->route($routeByRole[$role] ?? 'dashboard.school-nurse');
});

Route::post('/admin-login', function (Request $request) {
    $validated = $request->validate([
        'username' => ['required', 'string'],
        'password' => ['required', 'string'],
    ]);

    $expectedUsername = (string) env('SYSTEM_ADMIN_USERNAME', 'systemadmin');
    $expectedPassword = (string) env('SYSTEM_ADMIN_PASSWORD', 'admin123');

    if ($validated['username'] !== $expectedUsername || $validated['password'] !== $expectedPassword) {
        return back()
            ->withInput(['username' => $validated['username']])
            ->with('error', 'Invalid System Admin credentials.');
    }

    $request->session()->put('active_role', 'system_admin');
    $request->session()->put('active_name', 'System Admin');
    $request->session()->put('active_username', $validated['username']);
    $request->session()->forget(['assigned_grade_level', 'assigned_section']);

    return redirect()->route('dashboard.system-admin');
})->name('admin.login.submit');
