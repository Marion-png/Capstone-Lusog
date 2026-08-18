<?php

use App\Http\Controllers\AdviserController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ClinicNoteController;
use App\Http\Controllers\ConditionController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FeedingAtRiskController;
use App\Http\Controllers\FeedingAttendanceController;
use App\Http\Controllers\FeedingCoordinatorController;
use App\Http\Controllers\FeedingEnrollmentController;
use App\Http\Controllers\FeedingMasterlistExportController;
use App\Http\Controllers\FeedingProgramController;
use App\Http\Controllers\HealthAssessmentController;
use App\Http\Controllers\HealthConsentFormController;
use App\Http\Controllers\MedicalCertificateController;
use App\Http\Controllers\MedicineDispenseController;
use App\Http\Controllers\MedicineInventoryController;
use App\Http\Controllers\NurseController;
use App\Http\Controllers\NutricorController;
use App\Http\Controllers\NutritionCoordinatorController;
use App\Http\Controllers\ParentalConsentFormController;
use App\Http\Controllers\SchoolHeadConsentController;
use App\Http\Controllers\SchoolHeadController;
use App\Http\Controllers\SchoolHeadHealthController;
use App\Http\Controllers\SchoolHeadInventoryController;
use App\Http\Controllers\SchoolHeadMasterlistController;
use App\Http\Controllers\SchoolHeadProgramController;
use App\Http\Controllers\SchoolHeadReportsController;
use App\Http\Controllers\StudentHealthRecordController;
use App\Http\Controllers\StudentMedicalDocumentController;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Institution;
use App\Models\InstitutionSection;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Models\StudentHealthRecord;
use App\Support\AuditTrail;
use App\Support\FeedingAtRiskRule;
use App\Support\StudentRosterSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/', function () {
    return view('auth.login', ['demoAccounts' => []]);
});

Route::get('/login', function () {
    return view('auth.login', ['demoAccounts' => []]);
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
        'name' => ['required', 'string', 'max:255'],
        'username' => ['required', 'string', 'max:255'],
        'password' => ['required', 'string', 'min:6', 'confirmed'],
        'role' => ['required', 'in:school_nurse,clinic_staff,class_adviser,school_head,feeding_coor,nutricor'],
        'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
        'assigned_grade_level' => ['required_if:role,class_adviser', 'nullable', 'string', 'max:50'],
        'assigned_section' => ['required_if:role,class_adviser', 'nullable', 'string', 'max:100'],
    ]);

    $role = $validated['role'];

    // Scoped roles must select a school
    if (in_array($role, $scopedRoles, true) && empty($validated['institution_id'])) {
        return back()
            ->withErrors(['institution_id' => 'Please select your school.'])
            ->withInput();
    }

    $username = strtolower(trim($validated['username']));

    // Usernames are unique per school: the same teacher may register a
    // separate account for each school they work in.
    $requestedInstitutionId = in_array($role, $scopedRoles, true) ? ((int) $validated['institution_id']) : null;

    // An adviser is scoped to one grade and one section, so those two strings
    // decide which learners they may enter and read for the rest of the year.
    // Where the school has published its sections, the answer must be one of
    // them — a typo would hand the adviser a class that does not exist and an
    // empty roster they cannot explain. A school with no catalogue is accepted
    // as typed: not every school has published its list, and refusing them a
    // registration would be worse than accepting a typed answer.
    if ($role === 'class_adviser' && InstitutionSection::hasCatalog($requestedInstitutionId)) {
        $gradeLevel = (string) ($validated['assigned_grade_level'] ?? '');
        $section = (string) ($validated['assigned_section'] ?? '');

        $canonical = InstitutionSection::canonical($requestedInstitutionId, $gradeLevel, $section);

        if ($canonical === null) {
            return back()
                ->withErrors(['assigned_section' => 'Select a grade level and section your school runs.'])
                ->withInput();
        }

        // Store the catalogue's own spelling, so every adviser at this school
        // is scoped by the same string the roster filters compare against.
        [$validated['assigned_grade_level'], $validated['assigned_section']] = $canonical;
    }

    $alreadyPending = Schema::hasTable('account_requests') && DB::table('account_requests')
        ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
        ->where('institution_id', $requestedInstitutionId)
        ->where('status', 'pending')
        ->exists();

    $alreadyApproved = Schema::hasTable('accounts') && DB::table('accounts')
        ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
        ->where('institution_id', $requestedInstitutionId)
        ->exists();

    if ($alreadyPending || $alreadyApproved) {
        return back()
            ->withErrors(['username' => 'A request or account with this username already exists for the selected school.'])
            ->withInput();
    }

    $institutionId = $requestedInstitutionId;
    $institution = $institutionId ? Institution::find($institutionId) : null;

    AuditTrail::record('created', 'AccountRequest', null, "Account request submitted for username '{$username}' ({$role})");

    DB::table('account_requests')->insert([
        'id' => (string) str()->uuid(),
        'name' => $validated['name'],
        'username' => $validated['username'],
        'password_hash' => Hash::make((string) $validated['password']),
        'role' => $role,
        'institution_id' => $institutionId,
        'school_name' => $institution?->name,
        'assigned_grade_level' => $role === 'class_adviser' ? ($validated['assigned_grade_level'] ?? null) : null,
        'assigned_section' => $role === 'class_adviser' ? ($validated['assigned_section'] ?? null) : null,
        'status' => 'pending',
        'decided_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
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
    $role = (string) $request->session()->get('active_role', '');
    if (! in_array($role, ['school_nurse', 'clinic_staff', 'system_admin'], true)) {
        $redirectByRole = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
            'system_admin' => 'dashboard.system-admin',
        ];

        return redirect()->route($redirectByRole[$role] ?? 'login');
    }

    $institutionId = $request->session()->get('active_institution_id');

    $totalRecords = 0;
    $consultationsToday = 0;
    $atRiskCount = 0;
    $lowStockCount = 0;
    $recentConsultations = collect();
    $topConditions = collect();
    $lowStockMedicines = collect();

    if (Schema::hasTable('student_health_records')) {
        $totalRecords = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->count();
        $atRiskCount = StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
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

        // `condition` is encrypted at rest, so this month's rows are fetched
        // and tallied in PHP — a SQL GROUP BY would group ciphertext, giving
        // one "condition" per row.
        $topConditions = Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereMonth('consulted_at', now()->month)
            ->whereYear('consulted_at', now()->year)
            ->get()
            ->groupBy(fn (Consultation $c) => strtolower(trim((string) $c->condition)))
            ->reject(fn ($group, $name) => $name === '')
            ->map(fn ($group, $name) => ['name' => $name, 'total' => $group->count()])
            ->sortByDesc('total')
            ->values()
            ->take(4);
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

    // Roster for the dashboard's learner search. Names are encrypted, so the
    // search cannot run in SQL — the list is embedded and filtered in the
    // browser, exactly as the adviser's topbar search does.
    StudentRosterSync::syncToSession($request);

    $learnerSearchRoster = collect($request->session()->get('school_health_card_records', []))
        ->map(function ($row) {
            $middle = trim((string) ($row['middle_name'] ?? ''));
            $name = trim(
                trim((string) ($row['last_name'] ?? '')).', '.
                trim((string) ($row['first_name'] ?? '')).
                ($middle !== '' ? ' '.strtoupper(substr($middle, 0, 1)).'.' : '')
            );

            return [
                'lrn' => (string) ($row['lrn'] ?? ''),
                'name' => trim($name, ' ,'),
                'section' => trim(trim((string) ($row['grade_level'] ?? '')).' - '.trim((string) ($row['section'] ?? '')), ' -'),
            ];
        })
        ->filter(fn (array $row) => $row['lrn'] !== '' && $row['name'] !== '')
        ->sortBy('name')
        ->values();

    return view('dashboard.school-nurse', compact(
        'totalRecords', 'consultationsToday', 'atRiskCount', 'lowStockCount',
        'recentConsultations', 'topConditions', 'lowStockMedicines',
        'learnerSearchRoster'
    ));
})->name('dashboard.school-nurse');

Route::get('/dashboard/student-health-records', function () {
    $role = (string) session('active_role', '');
    if (! in_array($role, ['school_nurse', 'clinic_staff', 'system_admin'], true)) {
        $redirectByRole = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
            'system_admin' => 'dashboard.system-admin',
        ];

        return redirect()->route($redirectByRole[$role] ?? 'login');
    }

    return view('dashboard.student-health-records');
})->name('dashboard.student-health-records');

Route::get('/dashboard/school-nurse/deworming', function (Request $request) {
    $role = (string) $request->session()->get('active_role', '');
    if (! in_array($role, ['school_nurse', 'clinic_staff', 'system_admin'], true)) {
        $redirectByRole = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
            'system_admin' => 'dashboard.system-admin',
        ];

        return redirect()->route($redirectByRole[$role] ?? 'login');
    }

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

    if (! in_array($activeRole, $allowedReviewerRoles, true)) {
        return redirect()->route('dashboard.school-nurse')->with('error', 'Only School Nurse can review deworming requests.');
    }

    if (Schema::hasTable('deworming_requests')) {
        $institutionId = $request->session()->get('active_institution_id');

        $exists = DB::table('deworming_requests')
            ->where('id', $requestId)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->exists();

        if (! $exists) {
            return back()->with('error', 'Deworming request not found.');
        }

        DB::table('deworming_requests')
            ->where('id', $requestId)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
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

    if (! in_array($activeRole, $allowedReviewerRoles, true)) {
        return redirect()->route('dashboard.school-nurse')->with('error', 'Only School Nurse can add comments to deworming requests.');
    }

    $validated = $request->validate([
        'nurse_comment' => ['required', 'string', 'max:500'],
    ]);

    if (Schema::hasTable('deworming_requests')) {
        $institutionId = $request->session()->get('active_institution_id');

        $exists = DB::table('deworming_requests')
            ->where('id', $requestId)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->exists();

        if (! $exists) {
            return back()->with('error', 'Deworming request not found.');
        }

        DB::table('deworming_requests')
            ->where('id', $requestId)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
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

// API: the sections one school runs, grouped by grade level, for the cascading
// grade/section dropdowns on the account-request form. School structure, not
// personal information — no session is needed to read it.
Route::get('/api/institutions/{institutionId}/sections', function (string $institutionId) {
    return response()->json([
        'grades' => (object) InstitutionSection::catalogFor((int) $institutionId),
    ]);
})->whereNumber('institutionId')->name('api.institutions.sections');

// API routes for condition search and creation
Route::get('/api/conditions', [ConditionController::class, 'index'])
    ->name('api.conditions.index');

Route::post('/api/conditions', [ConditionController::class, 'store'])
    ->name('api.conditions.store');

Route::get('/dashboard/data-visualization', function () {
    $role = (string) session('active_role', '');
    if (! in_array($role, ['school_nurse', 'clinic_staff', 'system_admin'], true)) {
        $redirectByRole = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
            'system_admin' => 'dashboard.system-admin',
        ];

        return redirect()->route($redirectByRole[$role] ?? 'login');
    }

    return view('dashboard.data-visualization');
})->name('dashboard.data-visualization');

Route::get('/dashboard/medicine-inventory', [MedicineInventoryController::class, 'index'])
    ->name('dashboard.medicine-inventory');

Route::get('/dashboard/medicine-inventory/new', [MedicineInventoryController::class, 'create'])
    ->name('medicine-inventory.create');

Route::post('/dashboard/medicine-inventory', [MedicineInventoryController::class, 'store'])
    ->name('medicine-inventory.store');

// Dispensing Log — School Nurse only. Unlike the rest of the clinic
// section, clinic_staff is not admitted: the guard lives in
// MedicineDispenseController::requireNurse().
Route::get('/dashboard/dispensing-log', [MedicineDispenseController::class, 'index'])
    ->name('dashboard.dispensing-log');

Route::post('/dashboard/dispensing-log', [MedicineDispenseController::class, 'store'])
    ->name('dispensing-log.store');

Route::get('/dashboard/clinic-staff', function (Request $request) {
    $role = (string) $request->session()->get('active_role', '');
    if (! in_array($role, ['clinic_staff', 'school_nurse', 'system_admin'], true)) {
        $redirectByRole = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
        ];

        return redirect()->route($redirectByRole[$role] ?? 'login');
    }

    $institutionId = session('active_institution_id');
    $atRiskStudents = collect();

    if (Schema::hasTable('student_health_records')) {
        $q = StudentHealthRecord::query()->where('is_at_risk', true);
        if ($institutionId) {
            $q->where('institution_id', $institutionId);
        }
        // student_name is encrypted at rest, so the tiebreak sort happens in PHP.
        $atRiskStudents = $q
            ->forCurrentSchoolYear()
            ->orderByDesc('attendance_sessions_count')
            ->get()
            ->sortBy([
                ['attendance_sessions_count', 'desc'],
                ['student_name', 'asc'],
            ])
            ->take(8)
            ->values();
    }

    // These four figures used to be hardcoded demo numbers. They are now
    // read from the same tables the nurse's pages use, so the workspace
    // reflects the school it is scoped to.
    $consultations = Schema::hasTable('consultations')
        ? Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->orderByDesc('consulted_at')
            ->limit(50)
            ->get()
        : collect();

    $dispensedToday = Schema::hasTable('medicine_dispenses')
        ? (int) MedicineDispense::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereDate('dispensed_at', now()->toDateString())
            ->sum('quantity')
        : 0;

    $medicines = Schema::hasTable('medicines')
        ? Medicine::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->orderBy('name')
            ->limit(6)
            ->get()
        : collect();

    // "Pending endorsements" = adviser cards the nurse has not examined yet,
    // the same count the nurse rail badges.
    $pendingEndorsements = collect(session('school_health_card_records', []))
        ->filter(fn ($row) => empty($row['examination']))
        ->count();

    return view('dashboard.clinic-staff', [
        'atRiskStudents' => $atRiskStudents,
        'consultations' => $consultations,
        'medicines' => $medicines,
        'stats' => [
            'walk_ins_today' => $consultations->filter(fn ($c) => $c->consulted_at?->isToday() ?? false)->count(),
            'encoded_total' => $consultations->count(),
            'dispensed_today' => $dispensedToday,
            'pending_endorsements' => $pendingEndorsements,
        ],
    ]);
})->name('dashboard.clinic-staff');

Route::get('/dashboard/class-adviser', [StudentHealthRecordController::class, 'classAdviserDashboard'])
    ->name('dashboard.class-adviser');

Route::get('/dashboard/class-adviser/students/{lrn}', [StudentHealthRecordController::class, 'studentProfile'])
    ->name('dashboard.class-adviser.student-profile');

Route::get('/dashboard/class-adviser/feeding-status', [StudentHealthRecordController::class, 'feedingStatus'])
    ->name('dashboard.class-adviser.feeding-status');

Route::get('/dashboard/class-adviser/activity', [StudentHealthRecordController::class, 'activityFeed'])
    ->name('dashboard.class-adviser.activity');

Route::get('/dashboard/class-adviser/activity/pulse', [StudentHealthRecordController::class, 'activityPulse'])
    ->name('dashboard.class-adviser.activity.pulse');

Route::get('/dashboard/school-head', [SchoolHeadController::class, 'index'])
    ->name('dashboard.school-head');

// The head's tabs. Everything here is a GET but the report decision: this role
// reads and decides while other roles write, and RestrictSchoolHeadWrites
// enforces that over every other endpoint in the app.
Route::get('/dashboard/school-head/program', [SchoolHeadProgramController::class, 'index'])
    ->name('dashboard.school-head.program');

// Clinic activity, aggregated. The clinical narrative stays with the nurse.
Route::get('/dashboard/school-head/health', [SchoolHeadHealthController::class, 'index'])
    ->name('dashboard.school-head.health');

// Whether the school holds the health-services consent it must hold.
Route::get('/dashboard/school-head/consent', [SchoolHeadConsentController::class, 'index'])
    ->name('dashboard.school-head.consent');

Route::get('/dashboard/school-head/consent/export', [SchoolHeadConsentController::class, 'export'])
    ->name('dashboard.school-head.consent.export');

// Stock levels only — receiving and dispensing belong to the clinic.
Route::get('/dashboard/school-head/inventory', [SchoolHeadInventoryController::class, 'index'])
    ->name('dashboard.school-head.inventory');

Route::get('/dashboard/school-head/inventory/export', [SchoolHeadInventoryController::class, 'export'])
    ->name('dashboard.school-head.inventory.export');

Route::get('/dashboard/school-head/reports', [SchoolHeadReportsController::class, 'index'])
    ->name('dashboard.school-head.reports');

// The report key travels as a parameter, never as a path segment: a monthly
// key is "monthly:2026-08", and a colon in a URL path is an encoding hazard
// that route matching would have to be taught to survive.
Route::get('/dashboard/school-head/reports/export', [SchoolHeadReportsController::class, 'export'])
    ->name('dashboard.school-head.reports.export');

// The one write the School Head has: approving, returning or locking a report.
// Named in RestrictSchoolHeadWrites::ALLOWED_ROUTES — nothing else is.
// One report, opened as the DepEd form it will be exported as. A read, like
// everything else this role does — the head no longer records a decision on a
// report, so there is no POST here at all.
Route::get('/dashboard/school-head/reports/view', [SchoolHeadReportsController::class, 'show'])
    ->name('dashboard.school-head.reports.view');

Route::get('/dashboard/school-head/masterlist', [SchoolHeadMasterlistController::class, 'index'])
    ->name('dashboard.school-head.masterlist');

Route::get('/dashboard/school-head/masterlist/export', [SchoolHeadMasterlistController::class, 'export'])
    ->name('dashboard.school-head.masterlist.export');

// Keeps the school head's dashboard current without a reload: the page polls
// the pulse (a stamp, no data) and only re-reads the metrics when it moves.
Route::get('/dashboard/school-head/metrics', [SchoolHeadController::class, 'metrics'])
    ->name('dashboard.school-head.metrics');

Route::get('/dashboard/school-head/metrics/pulse', [SchoolHeadController::class, 'pulse'])
    ->name('dashboard.school-head.metrics.pulse');

Route::get('/dashboard/feedingcor-dashboard', [FeedingCoordinatorController::class, 'dashboard'])
    ->name('dashboard.feedingcor-dashboard');

// Keeps the coordinator's status counts and today's attendance current without
// a reload: the page polls the pulse (a stamp, no data) and only re-reads the
// panels when it moves.
Route::get('/dashboard/feedingcor-dashboard/metrics', [FeedingCoordinatorController::class, 'metrics'])
    ->name('dashboard.feedingcor.metrics');

Route::get('/dashboard/feedingcor-dashboard/metrics/pulse', [FeedingCoordinatorController::class, 'pulse'])
    ->name('dashboard.feedingcor.metrics.pulse');

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

// Attendance: who was fed, on which day. Four views of one set of marks —
// the session sheet, the day-by-day history, the per-beneficiary standing and
// the month calendar — plus the school's own XLSX attendance template.
Route::get('/dashboard/feedingcor-attendance', [FeedingAttendanceController::class, 'index'])
    ->name('dashboard.feedingcor-attendance');

// Re-rendered panels for the tab's live refresh. Returns the same Blade
// partials the first paint used, so a live view cannot drift from a reloaded
// one. The unsaved sheet is deliberately not among them.
Route::get('/dashboard/feedingcor-attendance/metrics', [FeedingAttendanceController::class, 'metrics'])
    ->name('dashboard.feedingcor-attendance.metrics');

Route::get('/dashboard/feedingcor-attendance/export', [FeedingAttendanceController::class, 'export'])
    ->name('dashboard.feedingcor-attendance.export');

// At-Risk Students: which beneficiaries are below the school's own attendance
// threshold, why, and who needs following up. It reads the marks the Attendance
// tab wrote and never writes one of its own.
Route::get('/dashboard/feedingcor-at-risk', [FeedingAtRiskController::class, 'index'])
    ->name('dashboard.feedingcor-at-risk');

// The summary cards for the tab's live refresh — the same partial the first
// paint used. The list is deliberately not re-rendered: it holds the rows a
// coordinator has opened to read.
Route::get('/dashboard/feedingcor-at-risk/metrics', [FeedingAtRiskController::class, 'metrics'])
    ->name('dashboard.feedingcor-at-risk.metrics');

Route::get('/dashboard/feedingcor-at-risk/export', [FeedingAtRiskController::class, 'export'])
    ->name('dashboard.feedingcor-at-risk.export');

// The one thing on the tab a human enters: what was done about a learner who is
// not turning up. Scoped, audited, encrypted — and never an enrolment change.
Route::post('/dashboard/feedingcor-at-risk/follow-up', [FeedingAtRiskController::class, 'storeFollowUp'])
    ->name('feedingcor-at-risk.follow-up.store');

Route::get('/dashboard/feedingcor-sbfp-forms', [FeedingCoordinatorController::class, 'sbfpForms'])
    ->name('dashboard.feedingcor-sbfp-forms');

Route::get('/dashboard/feedingcor-health-records', [StudentHealthRecordController::class, 'feedingHealthRecords'])
    ->name('dashboard.feedingcor-health-records');

// The Beneficiaries tab's headline cards, re-rendered when the coordinator's
// metrics stamp moves. Same partial and same summary as the first paint.
Route::get('/dashboard/feedingcor-health-records/cards', [FeedingCoordinatorController::class, 'beneficiaryCards'])
    ->name('dashboard.feedingcor-health-records.cards');

// The masterlist as the school's own DepEd form, in a real workbook. POST
// because the page sends the ordered ids of the rows on screen — the roster the
// coordinator filtered, searched and sorted — which is more than a URL should
// carry and is a read of learner data either way.
Route::post('/dashboard/feedingcor-health-records/masterlist', FeedingMasterlistExportController::class)
    ->name('dashboard.feedingcor-health-records.masterlist');

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

// Medical documents on a learner's student profile — upload, list, preview,
// download, delete. Shared by the class adviser, school nurse, and clinic
// staff (roles and own-school scope enforced in the controller), so the path
// is deliberately role-neutral: an /adviser/* URL would make the prototype
// session middleware switch a nurse's session over to class_adviser.
Route::get('/health-records/students/{lrn}/documents', [StudentMedicalDocumentController::class, 'index'])
    ->name('student-documents.index');
Route::post('/health-records/students/{lrn}/documents', [StudentMedicalDocumentController::class, 'store'])
    ->name('student-documents.store');
// No-PII change signal an open panel polls, so each desk sees what the others
// filed without a reload. Exempt from the audit trail in AuditSensitiveAccess.
Route::get('/health-records/students/{lrn}/documents/pulse', [StudentMedicalDocumentController::class, 'pulse'])
    ->name('student-documents.pulse');
Route::get('/health-records/student-documents/{id}/view', [StudentMedicalDocumentController::class, 'view'])
    ->whereNumber('id')->name('student-documents.view');
Route::get('/health-records/student-documents/{id}/download', [StudentMedicalDocumentController::class, 'download'])
    ->whereNumber('id')->name('student-documents.download');
Route::delete('/health-records/student-documents/{id}', [StudentMedicalDocumentController::class, 'destroy'])
    ->whereNumber('id')->name('student-documents.destroy');

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

// Health Services Consent Form (Sulat-Pahibalo) — adviser prepares/sends,
// parent signs via unique link, nurse gets read-only access when completed.
Route::get('/dashboard/class-adviser/consent-forms', [HealthConsentFormController::class, 'index'])
    ->name('consent-forms.index');
Route::post('/dashboard/class-adviser/consent-forms/open', [HealthConsentFormController::class, 'open'])
    ->name('consent-forms.open');
Route::get('/dashboard/class-adviser/consent-forms/{form}', [HealthConsentFormController::class, 'show'])
    ->whereNumber('form')->name('consent-forms.show');
Route::post('/dashboard/class-adviser/consent-forms/{form}/draft', [HealthConsentFormController::class, 'saveDraft'])
    ->whereNumber('form')->name('consent-forms.draft');
Route::post('/dashboard/class-adviser/consent-forms/{form}/send', [HealthConsentFormController::class, 'send'])
    ->whereNumber('form')->name('consent-forms.send');
Route::post('/dashboard/class-adviser/consent-forms/{form}/review', [HealthConsentFormController::class, 'review'])
    ->whereNumber('form')->name('consent-forms.review');
Route::get('/dashboard/school-nurse/consent-forms', [HealthConsentFormController::class, 'nurseIndex'])
    ->name('consent-forms.nurse-index');
Route::get('/dashboard/school-nurse/consent-forms/{form}', [HealthConsentFormController::class, 'nurseShow'])
    ->whereNumber('form')->name('consent-forms.nurse-show');
// The School Head reads one signed letter: a click with a reason behind it,
// audited like every other read of a parent's answer.
Route::get('/dashboard/school-head/consent-forms/{form}', [HealthConsentFormController::class, 'headShow'])
    ->whereNumber('form')->name('consent-forms.head-show');
Route::get('/dashboard/consent-forms/{form}/print', [HealthConsentFormController::class, 'print'])
    ->whereNumber('form')->name('consent-forms.print');
Route::get('/consent/{token}', [HealthConsentFormController::class, 'parentShow'])
    ->name('consent-forms.parent');
Route::post('/consent/{token}', [HealthConsentFormController::class, 'parentSubmit'])
    ->name('consent-forms.parent-submit');

// Health Assessment (MLHAT) — class_adviser submit, nurse/staff read
Route::get('/dashboard/class-adviser/health-assessments', [HealthAssessmentController::class, 'index'])
    ->name('health-assessments.index');
Route::get('/dashboard/class-adviser/health-assessments/{lrn}', [HealthAssessmentController::class, 'form'])
    ->name('health-assessments.form');
Route::get('/dashboard/school-nurse/health-assessments', [HealthAssessmentController::class, 'nurseIndex'])
    ->name('health-assessments.nurse-index');
Route::get('/dashboard/health-assessments/{assessment}', [HealthAssessmentController::class, 'show'])
    ->whereNumber('assessment')->name('health-assessments.show');
Route::post('/adviser/health-assessment', [HealthAssessmentController::class, 'store'])
    ->name('health-assessment.store');
Route::get('/api/student-health-assessment', [HealthAssessmentController::class, 'status'])
    ->name('api.student-health-assessment');
Route::get('/api/student-health-history', [StudentHealthRecordController::class, 'history'])
    ->name('api.student-health-history');

// Clinic Notes + the per-learner consultation log on the student profile.
Route::get('/api/student-clinic-notes', [ClinicNoteController::class, 'index'])
    ->name('api.student-clinic-notes');
Route::post('/api/student-clinic-notes', [ClinicNoteController::class, 'store'])
    ->name('api.student-clinic-notes.store');
Route::get('/api/student-consultations', [ClinicNoteController::class, 'consultations'])
    ->name('api.student-consultations');

// Dashboard announcements — post/remove restricted to Announcement::POSTER_ROLES (school_nurse for now)
Route::post('/announcements', [AnnouncementController::class, 'store'])
    ->name('announcements.store');
Route::post('/announcements/{announcement}/delete', [AnnouncementController::class, 'destroy'])
    ->whereNumber('announcement')
    ->name('announcements.destroy');

// Dashboard upcoming events — create/remove restricted to Event::CREATOR_ROLES (school_nurse for now)
Route::post('/events', [EventController::class, 'store'])
    ->name('events.store');
Route::post('/events/{event}/delete', [EventController::class, 'destroy'])
    ->whereNumber('event')
    ->name('events.destroy');

// The Feeding Coordinator's Feeding Program tab is gone. Everything it showed
// now lives on a tab that owns it outright — the cycle and the session figures
// on Attendance, the threshold list on At-Risk Students, the roll on
// Beneficiaries — and the page was a fourth rendering of all three, which is how
// screens start disagreeing about the same programme. Its endpoints (import,
// scan, record, enrolment, the beneficiary record) are unaffected and keep their
// `feedingcor-program.*` names; only the page is retired, and old links to it
// land on Attendance rather than a 404.
Route::get('/dashboard/feedingcor-program', function (Request $request) {
    $activeRole = strtolower(trim((string) $request->session()->get('active_role', '')));

    if ($activeRole === 'school_nurse') {
        return redirect()->route('dashboard.school-nurse.feeding-program');
    }

    return redirect()->route('dashboard.feedingcor-attendance');
})->name('dashboard.feedingcor-program');

// The School Nurse keeps a read-only Feeding Program page: it is that role's
// only window onto the programme, not a second copy of a coordinator tab.
Route::get('/dashboard/school-nurse/feeding-program', [FeedingProgramController::class, 'index'])
    ->name('dashboard.school-nurse.feeding-program');

Route::post('/dashboard/feedingcor-program/attendance/import', [FeedingProgramController::class, 'importAttendance'])
    ->name('feedingcor-program.attendance.import');

// Photographed sheet → Claude vision → marks, with anything unreadable landing
// in the review queue below rather than being guessed.
Route::post('/dashboard/feedingcor-program/attendance/scan', [FeedingProgramController::class, 'scanAttendancePhoto'])
    ->name('feedingcor-program.attendance.scan');

// One screen, one tap per learner, one save — the fast path for a session the
// coordinator watched happen, instead of opening each learner in turn.
Route::get('/dashboard/feedingcor-program/attendance/record', [FeedingProgramController::class, 'recordAttendanceForm'])
    ->name('feedingcor-program.attendance.record');

Route::post('/dashboard/feedingcor-program/attendance/record', [FeedingProgramController::class, 'storeRecordedAttendance'])
    ->name('feedingcor-program.attendance.record.store');

// Enrolment: qualifying is the adviser's measurement, enrolling is the
// coordinator's decision. The candidates list returns learner names, so it is
// audited like every other read of personal data.
Route::get('/dashboard/feedingcor-program/enrollment/candidates', [FeedingEnrollmentController::class, 'candidates'])
    ->name('feedingcor-program.enrollment.candidates');

Route::post('/dashboard/feedingcor-program/enrollment', [FeedingEnrollmentController::class, 'store'])
    ->name('feedingcor-program.enrollment.store');

// One beneficiary's own page — what clicking a learner's name opens from the
// Beneficiaries tab. Keyed by record id, never by name.
Route::get('/dashboard/feedingcor-program/beneficiary/{record}', [FeedingProgramController::class, 'beneficiaryProfile'])
    ->whereNumber('record')
    ->name('feedingcor-program.beneficiary');

// Correcting one learner's mark for one session. Keyed by learner and date, so
// the same path fixes a wrong mark and fills one a sheet skipped; every
// correction is attributed and audited with the value it replaced.
Route::post('/dashboard/feedingcor-program/beneficiary/{record}/attendance', [FeedingProgramController::class, 'correctAttendance'])
    ->whereNumber('record')
    ->name('feedingcor-program.beneficiary.attendance.correct');

// One learner's session-by-session attendance — what "View" opens from the
// dashboard's at-risk list. Keyed by record id, never by name.
Route::get('/dashboard/feedingcor-program/attendance/learner/{record}', [FeedingProgramController::class, 'learnerAttendance'])
    ->whereNumber('record')
    ->name('feedingcor-program.attendance.learner');

Route::get('/dashboard/feedingcor-program/attendance/review', [FeedingProgramController::class, 'attendanceReviewQueue'])
    ->name('feedingcor-program.attendance.review');

Route::post('/dashboard/feedingcor-program/attendance/review/{attendance}', [FeedingProgramController::class, 'resolveAttendanceReview'])
    ->whereNumber('attendance')
    ->name('feedingcor-program.attendance.review.resolve');

Route::get('/dashboard/system-admin', function () {
    $activeRole = session('active_role');
    if ($activeRole !== 'system_admin') {
        if (! $activeRole) {
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

    // Per-school feeding policy. The threshold is the one figure the SRS
    // requires to be school-configurable, so it is administered here rather
    // than living in config alone.
    $institutions = Schema::hasTable('institutions')
        ? Institution::query()->orderBy('name')->get(array_merge(
            ['id', 'name', 'feeding_at_risk_threshold'],
            Schema::hasColumn('institutions', 'feeding_min_observation_days') ? ['feeding_min_observation_days'] : []
        ))
        : collect();

    return view('dashboard.system-admin', [
        'accounts' => $accounts,
        'pendingRequests' => $pendingRequests,
        'requestHistory' => $requestHistory,
        'institutions' => $institutions,
        'defaultAtRiskThreshold' => FeedingAtRiskRule::fromConfig()->thresholdPercent(),
        'defaultMinObservationDays' => FeedingAtRiskRule::fromConfig()->minimumObservationDays(),
    ]);
})->name('dashboard.system-admin');

/**
 * Sets one school's feeding at-risk policy: the attendance threshold, and the
 * minimum observation period the threshold only applies after. Clearing either
 * field returns that school to the app default rather than pinning it to
 * today's number.
 *
 * The observation period is only touched when the form actually submits it, so
 * a caller that posts a threshold alone cannot silently wipe a school's window.
 *
 * Raw DB write, so the audit entry is recorded explicitly — and it carries the
 * value it replaced, because "who moved the threshold and from what" is exactly
 * the question an audit of a re-flagged learner has to answer.
 */
Route::post('/dashboard/system-admin/institutions/{institution}/at-risk-threshold', function (Request $request, int $institution) {
    if ($request->session()->get('active_role') !== 'system_admin') {
        return redirect()->route('login')->with('error', 'Please sign in as System Admin to change feeding policy.');
    }

    $validated = $request->validate([
        'threshold' => ['nullable', 'numeric', 'min:1', 'max:100'],
        // 0 is a real answer: classify from the very first confirmed session.
        'minimum_observation_days' => ['nullable', 'integer', 'min:0', 'max:120'],
    ]);

    $school = Institution::query()->find($institution);
    if (! $school) {
        return back()->with('error', 'That school is no longer on file.');
    }

    $previous = $school->feeding_at_risk_threshold;
    $threshold = $validated['threshold'] === null || $validated['threshold'] === ''
        ? null
        : (int) round((float) $validated['threshold']);

    $changes = ['feeding_at_risk_threshold' => $threshold];
    $notes = ['at-risk threshold for '.$school->name.' set to '
        .($threshold === null ? 'the app default' : $threshold.'%')
        .' (was '.($previous === null ? 'the app default' : $previous.'%').')'];

    if ($request->has('minimum_observation_days')) {
        $previousDays = $school->feeding_min_observation_days;
        $days = $validated['minimum_observation_days'] === null || $validated['minimum_observation_days'] === ''
            ? null
            : (int) $validated['minimum_observation_days'];

        $changes['feeding_min_observation_days'] = $days;
        $notes[] = 'minimum observation period set to '
            .($days === null ? 'the app default' : $days.' feeding days')
            .' (was '.($previousDays === null ? 'the app default' : $previousDays.' feeding days').')';
    }

    $school->update($changes);

    AuditTrail::record('updated', 'Institution', $school->id, 'Feeding '.implode('; ', $notes));

    return back()->with('success', 'Feeding at-risk policy updated for '.$school->name.'.');
})->whereNumber('institution')->name('dashboard.system-admin.institutions.at-risk-threshold');

Route::get('/dashboard/system-admin/audit-logs', function (Request $request) {
    if ($request->session()->get('active_role') !== 'system_admin') {
        return redirect()
            ->route('login')
            ->with('error', 'Only System Admin can view the audit trail.');
    }

    $filterAction = trim((string) $request->query('action', ''));
    $filterUsername = trim((string) $request->query('username', ''));

    $hasTable = Schema::hasTable('audit_logs');

    $logs = $hasTable
        ? AuditLog::query()
            ->when($filterAction !== '', fn ($q) => $q->where('action', $filterAction))
            ->when($filterUsername !== '', fn ($q) => $q->where('actor_username', 'like', "%{$filterUsername}%"))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
        : collect();

    $actions = $hasTable
        ? AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action')
        : collect();

    return view('dashboard.system-admin-audit-logs', [
        'logs' => $logs,
        'actions' => $actions,
        'filterAction' => $filterAction,
        'filterUsername' => $filterUsername,
    ]);
})->name('dashboard.system-admin.audit-logs');

Route::post('/dashboard/system-admin/accounts', function (Request $request) {
    if ($request->session()->get('active_role') !== 'system_admin') {
        return redirect()
            ->route('login')
            ->with('error', 'Only System Admin can create user accounts.');
    }

    $scopedRoles = ['school_nurse', 'clinic_staff', 'class_adviser', 'school_head', 'feeding_coor', 'nutricor'];

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'username' => ['required', 'string', 'max:255'],
        'role' => ['required', 'in:school_nurse,clinic_staff,class_adviser,school_head,feeding_coor,nutricor'],
        'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
        'assigned_grade_level' => ['required_if:role,class_adviser', 'nullable', 'string', 'max:50'],
        'assigned_section' => ['required_if:role,class_adviser', 'nullable', 'string', 'max:100'],
    ]);

    $role = $validated['role'];

    if (in_array($role, $scopedRoles, true) && empty($validated['institution_id'])) {
        return back()
            ->withErrors(['institution_id' => 'Please select a school for this role.'])
            ->withInput();
    }

    $username = strtolower(trim($validated['username']));
    $institutionId = in_array($role, $scopedRoles, true) ? ((int) $validated['institution_id']) : null;

    // Usernames are unique per school, not globally — one account per school.
    $alreadyExists = Schema::hasTable('accounts') && DB::table('accounts')
        ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
        ->where('institution_id', $institutionId)
        ->exists();

    if ($alreadyExists) {
        return back()
            ->withErrors(['username' => 'An account with this username already exists for the selected school.'])
            ->withInput();
    }

    $institution = $institutionId ? Institution::find($institutionId) : null;

    AuditTrail::record('created', 'Account', null, "System Admin created account '{$username}' ({$role})");

    DB::table('accounts')->insert([
        'name' => $validated['name'],
        'username' => $validated['username'],
        'password_hash' => null,
        'role' => $role,
        'institution_id' => $institutionId,
        'school_name' => $institution?->name,
        'assigned_grade_level' => $role === 'class_adviser' ? ($validated['assigned_grade_level'] ?? null) : null,
        'assigned_section' => $role === 'class_adviser' ? ($validated['assigned_section'] ?? null) : null,
        'created_at' => now(),
        'updated_at' => now(),
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

    if (! $target) {
        return back()->with('error', 'Account request not found.');
    }

    $username = strtolower(trim((string) ($target->username ?? '')));
    $role = (string) ($target->role ?? '');

    $alreadyExists = Schema::hasTable('accounts') && DB::table('accounts')
        ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
        ->where('institution_id', $target->institution_id ?? null)
        ->exists();

    if (! $alreadyExists && Schema::hasTable('accounts')) {
        DB::table('accounts')->insert([
            'name' => $target->name ?? '-',
            'username' => $target->username ?? '-',
            'password_hash' => $target->password_hash ?? null,
            'role' => $role,
            'institution_id' => $target->institution_id ?? null,
            'school_name' => $target->school_name ?? null,
            'assigned_grade_level' => $role === 'class_adviser' ? ($target->assigned_grade_level ?? null) : null,
            'assigned_section' => $role === 'class_adviser' ? ($target->assigned_section ?? null) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('account_requests')->where('id', $requestId)->update([
        'status' => 'accepted',
        'decided_at' => now(),
        'updated_at' => now(),
    ]);

    AuditTrail::record('approved', 'AccountRequest', null, "Approved account request for username '{$username}' ({$role})");

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

    if (! $target) {
        return back()->with('error', 'Account request not found.');
    }

    DB::table('account_requests')->where('id', $requestId)->update([
        'status' => 'declined',
        'decided_at' => now(),
        'updated_at' => now(),
    ]);

    AuditTrail::record('declined', 'AccountRequest', null, "Declined account request for username '".strtolower(trim((string) ($target->username ?? '')))."'");

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
    AuditTrail::record('logout', null, null, 'Logged out');

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
        AuditTrail::record('login_failed', null, null, "Failed login: username '{$username}' not found");

        return back()
            ->withInput()
            ->with('error', 'Account was not found. Please submit a Create Account request first.');
    }

    // The same username may exist in several schools (one separate account per
    // school). Keep only the accounts whose password matches, then require a
    // school choice if more than one remains.
    $candidates = $matchingAccounts
        ->filter(function (array $account) use ($request) {
            $passwordHash = (string) ($account['password_hash'] ?? '');

            return $passwordHash === '' || Hash::check((string) $request->input('password'), $passwordHash);
        })
        ->values();

    if ($candidates->isEmpty()) {
        AuditTrail::record('login_failed', null, null, "Failed login: wrong password for username '{$username}'");

        return back()
            ->withInput(['email' => $request->input('email')])
            ->with('error', 'Invalid username or password.');
    }

    if ($candidates->count() > 1) {
        $selectedInstitutionId = $request->input('institution_id');

        if ($selectedInstitutionId) {
            $candidates = $candidates
                ->filter(fn (array $a) => (string) ($a['institution_id'] ?? '') === (string) $selectedInstitutionId)
                ->values();
        }

        if ($candidates->count() !== 1) {
            $schoolChoices = $matchingAccounts
                ->filter(fn (array $a) => ! empty($a['institution_id']))
                ->mapWithKeys(fn (array $a) => [(string) $a['institution_id'] => (string) ($a['school_name'] ?? 'School #'.$a['institution_id'])])
                ->all();

            return back()
                ->withInput(['email' => $request->input('email')])
                ->with('school_choices', $schoolChoices)
                ->with('error', 'This username has an account in more than one school. Please select your school and sign in again.');
        }
    }

    $account = $candidates->first();
    $role = (string) ($account['role'] ?? '');

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

    AuditTrail::record('login', null, null, "Logged in as '{$username}' ({$role})");

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
})->name('login.submit');

Route::post('/admin-login', function (Request $request) {
    $validated = $request->validate([
        'username' => ['required', 'string'],
        'password' => ['required', 'string'],
    ]);

    $expectedUsername = (string) env('SYSTEM_ADMIN_USERNAME', 'systemadmin');
    $expectedPassword = (string) env('SYSTEM_ADMIN_PASSWORD', 'admin123');

    if ($validated['username'] !== $expectedUsername || $validated['password'] !== $expectedPassword) {
        AuditTrail::record('login_failed', null, null, "Failed System Admin login attempt for username '{$validated['username']}'");

        return back()
            ->withInput(['username' => $validated['username']])
            ->with('error', 'Invalid System Admin credentials.');
    }

    $request->session()->put('active_role', 'system_admin');
    $request->session()->put('active_name', 'System Admin');
    $request->session()->put('active_username', $validated['username']);
    $request->session()->forget(['assigned_grade_level', 'assigned_section']);

    AuditTrail::record('login', null, null, 'System Admin logged in');

    return redirect()->route('dashboard.system-admin');
})->name('admin.login.submit');
