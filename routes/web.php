<?php

use App\Http\Controllers\AdviserController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\FeedingCoordinatorController;
use App\Http\Controllers\FeedingProgramController;
use App\Http\Controllers\MedicineInventoryController;
use App\Http\Controllers\NurseController;
use App\Http\Controllers\SchoolHeadController;
use App\Http\Controllers\StudentHealthRecordController;
use App\Models\StudentHealthRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('auth.login');
});

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::get('/admin-login', function () {
    return view('auth.admin-login');
})->name('admin.login');

Route::get('/account-request', function () {
    return view('auth.account-request');
})->name('account.request');

Route::post('/account-request', function (Request $request) {
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'username' => ['required', 'string', 'max:255'],
        'password' => ['required', 'string', 'min:6', 'confirmed'],
        'role' => ['required', 'in:school_nurse,clinic_staff,class_adviser,school_head,feeding_coor,nutricor'],
        'school_name' => ['required_if:role,school_nurse,clinic_staff,school_head,class_adviser,nutricor', 'nullable', 'string', 'max:255'],
        'assigned_grade_level' => ['required_if:role,class_adviser', 'nullable', 'string', 'max:50'],
        'assigned_section' => ['required_if:role,class_adviser', 'nullable', 'string', 'max:100'],
    ]);

    $pendingRequests = $request->session()->get('pending_account_requests', []);
    $username = strtolower(trim($validated['username']));
    $role = $validated['role'];

    $alreadyPending = collect($pendingRequests)->contains(function (array $item) use ($username): bool {
        $existingUsername = strtolower(trim((string) ($item['username'] ?? '')));
        return $existingUsername === $username;
    });

    $alreadyApproved = collect($request->session()->get('user_accounts', []))->contains(function (array $item) use ($username): bool {
        $existingUsername = strtolower(trim((string) ($item['username'] ?? '')));
        return $existingUsername === $username;
    });

    if ($alreadyPending || $alreadyApproved) {
        return back()
            ->withErrors(['username' => 'A request or account with this username already exists.'])
            ->withInput();
    }

    $pendingRequests[] = [
        'id' => (string) str()->uuid(),
        'name' => $validated['name'],
        'username' => $validated['username'],
        'password_hash' => Hash::make((string) $validated['password']),
        'role' => $role,
        'school_name' => in_array($role, ['school_nurse', 'clinic_staff', 'school_head', 'class_adviser', 'nutricor'], true) ? ($validated['school_name'] ?? null) : null,
        'assigned_grade_level' => $role === 'class_adviser' ? ($validated['assigned_grade_level'] ?? null) : null,
        'assigned_section' => $role === 'class_adviser' ? ($validated['assigned_section'] ?? null) : null,
        'created_at' => now()->toIso8601String(),
    ];

    $request->session()->put('pending_account_requests', $pendingRequests);

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

Route::get('/dashboard/school-nurse', function () {
    return view('dashboard.school-nurse');
})->name('dashboard.school-nurse');

Route::get('/dashboard/student-health-records', function () {
    return view('dashboard.student-health-records');
})->name('dashboard.student-health-records');

Route::get('/dashboard/school-nurse/deworming', function (Request $request) {
    $requests = collect($request->session()->get('deworming_requests', []))
        ->sortByDesc('submitted_at')
        ->values();

    return view('dashboard.school-nurse-deworming', [
        'dewormingRequests' => $requests,
    ]);
})->name('dashboard.school-nurse.deworming');

Route::post('/dashboard/school-nurse/deworming/{requestId}/{decision}', function (Request $request, string $requestId, string $decision) {
    if ($request->session()->get('active_role') !== 'school_nurse') {
        return redirect()->route('login')->with('error', 'Only School Nurse can review deworming requests.');
    }

    $requests = collect($request->session()->get('deworming_requests', []));
    $index = $requests->search(fn (array $item): bool => (string) ($item['id'] ?? '') === $requestId);

    if ($index === false) {
        return back()->with('error', 'Deworming request not found.');
    }

    $status = $decision === 'accept' ? 'approved' : 'declined';
    $requests[$index]['status'] = $status;
    $requests[$index]['reviewed_at'] = now()->toIso8601String();
    $requests[$index]['reviewed_by'] = (string) $request->session()->get('active_name', 'School Nurse');
    $requests[$index]['released_date'] = $decision === 'accept' ? now()->toDateString() : null;

    $request->session()->put('deworming_requests', $requests->values()->all());

    return back()->with('success', 'Deworming request ' . ($decision === 'accept' ? 'accepted' : 'declined') . ' successfully.');
})->whereIn('decision', ['accept', 'decline'])->name('dashboard.school-nurse.deworming.decide');

Route::get('/dashboard/consultation-log', [ConsultationController::class, 'index'])
    ->name('dashboard.consultation-log');

Route::get('/dashboard/consultation-log/new', [ConsultationController::class, 'create'])
    ->name('consultations.create');

Route::post('/dashboard/consultation-log', [ConsultationController::class, 'store'])
    ->name('consultations.store');

Route::get('/dashboard/data-visualization', function () {
    return view('dashboard.data-visualization');
})->name('dashboard.data-visualization');

Route::get('/dashboard/medicine-inventory', [MedicineInventoryController::class, 'index'])
    ->name('dashboard.medicine-inventory');

Route::post('/dashboard/medicine-inventory', [MedicineInventoryController::class, 'store'])
    ->name('medicine-inventory.store');

Route::get('/dashboard/clinic-staff', function () {
    $atRiskStudents = collect();

    if (Schema::hasTable('student_health_records')) {
        $atRiskStudents = StudentHealthRecord::query()
            ->where('is_at_risk', true)
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

    $requests = $request->session()->get('deworming_requests', []);
    $requests[] = [
        'id' => (string) str()->uuid(),
        'submitted_at' => now()->toIso8601String(),
        'submitted_by' => (string) $request->session()->get('active_name', 'Class Adviser'),
        'submitted_by_role' => $submittedByRole,
        'campaign' => $validated['campaign'],
        'total_students' => (int) $validated['total_students'],
        'consenting_students' => (int) $validated['consenting_students'],
        'tablets_requested' => (int) $validated['consenting_students'],
        'status' => 'pending',
        'released_date' => null,
        'grade_level' => (string) $request->session()->get('assigned_grade_level', ''),
        'section' => (string) $request->session()->get('assigned_section', ''),
    ];

    $request->session()->put('deworming_requests', $requests);

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

Route::get('/dashboard/nutricor-dashboard', function () {
    return view('nutricor.nutricor-dashboard');
})->name('dashboard.nutricor-dashboard');

Route::get('/dashboard/nutricor-beneficiaries', function () {
    return view('nutricor.beneficiaries');
})->name('dashboard.nutricor-beneficiaries');

Route::get('/dashboard/nutricor-analytics', function () {
    return view('nutricor.analytics');
})->name('dashboard.nutricor-analytics');

Route::get('/dashboard/nutricor-atrisk', function () {
    return view('nutricor.atrisk');
})->name('dashboard.nutricor-atrisk');

Route::get('/dashboard/nutricor-reports', function () {
    return view('nutricor.reports');
})->name('dashboard.nutricor-reports');

Route::get('/dashboard/nutricor-comparison', function () {
    return view('nutricor.comparison');
})->name('dashboard.nutricor-comparison');

Route::get('/dashboard/feedingcor-sbfp-forms', [FeedingCoordinatorController::class, 'sbfpForms'])
    ->name('dashboard.feedingcor-sbfp-forms');

Route::get('/dashboard/feedingcor-health-records', [StudentHealthRecordController::class, 'feedingHealthRecords'])
    ->name('dashboard.feedingcor-health-records');

Route::post('/dashboard/class-adviser/health-records/baseline', [StudentHealthRecordController::class, 'storeBaseline'])
    ->name('class-adviser.health-records.baseline.store');

Route::post('/dashboard/class-adviser/health-records/{record}/endline', [StudentHealthRecordController::class, 'storeEndline'])
    ->name('class-adviser.health-records.endline.store');

Route::get('/dashboard/feedingcor-program', [FeedingProgramController::class, 'index'])
    ->name('dashboard.feedingcor-program');

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

    $accounts = collect(session('user_accounts', []))
        ->sortByDesc('created_at')
        ->values()
        ->all();

    $pendingRequests = collect(session('pending_account_requests', []))
        ->sortByDesc('created_at')
        ->values()
        ->all();

    $requestHistory = collect(session('account_request_history', []))
        ->sortByDesc('decided_at')
        ->values()
        ->all();

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

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'username' => ['required', 'string', 'max:255'],
        'role' => ['required', 'in:school_nurse,clinic_staff,class_adviser,school_head,feeding_coor,nutricor'],
        'assigned_grade_level' => ['required', 'string', 'max:50'],
        'assigned_section' => ['required', 'string', 'max:100'],
    ]);

    $accounts = $request->session()->get('user_accounts', []);
    $username = strtolower(trim($validated['username']));
    $role = $validated['role'];

    $alreadyExists = collect($accounts)->contains(function (array $account) use ($username): bool {
        $existingUsername = strtolower(trim((string) ($account['username'] ?? '')));
        return $existingUsername === $username;
    });

    if ($alreadyExists) {
        return back()
            ->withErrors(['username' => 'An account with this username already exists.'])
            ->withInput();
    }

    $accounts[] = [
        'name' => $validated['name'],
        'username' => $validated['username'],
        'role' => $role,
        'assigned_grade_level' => $validated['assigned_grade_level'],
        'assigned_section' => $validated['assigned_section'],
        'created_at' => now()->toIso8601String(),
    ];

    $request->session()->put('user_accounts', $accounts);

    return back()->with('success', 'User account created successfully.');
})->name('dashboard.system-admin.accounts.store');

Route::post('/dashboard/system-admin/requests/{requestId}/approve', function (Request $request, string $requestId) {
    if ($request->session()->get('active_role') !== 'system_admin') {
        return redirect()
            ->route('login')
            ->with('error', 'Only System Admin can approve account requests.');
    }

    $pendingRequests = collect($request->session()->get('pending_account_requests', []));
    $target = $pendingRequests->first(function (array $item) use ($requestId): bool {
        return (string) ($item['id'] ?? '') === $requestId;
    });

    if (!$target) {
        return back()->with('error', 'Account request not found.');
    }

    $accounts = $request->session()->get('user_accounts', []);
    $username = strtolower(trim((string) ($target['username'] ?? '')));
    $role = (string) ($target['role'] ?? '');

    $alreadyExists = collect($accounts)->contains(function (array $account) use ($username): bool {
        $existingUsername = strtolower(trim((string) ($account['username'] ?? '')));
        return $existingUsername === $username;
    });

    if (!$alreadyExists) {
        $accounts[] = [
            'name' => $target['name'] ?? '-',
            'username' => $target['username'] ?? '-',
            'password_hash' => $target['password_hash'] ?? null,
            'role' => $role,
            'school_name' => in_array($role, ['school_nurse', 'clinic_staff', 'school_head', 'class_adviser', 'nutricor'], true) ? ($target['school_name'] ?? null) : null,
            'assigned_grade_level' => $role === 'class_adviser' ? ($target['assigned_grade_level'] ?? null) : null,
            'assigned_section' => $role === 'class_adviser' ? ($target['assigned_section'] ?? null) : null,
            'created_at' => now()->toIso8601String(),
        ];
        $request->session()->put('user_accounts', $accounts);
    }

    $remaining = $pendingRequests
        ->reject(function (array $item) use ($requestId): bool {
            return (string) ($item['id'] ?? '') === $requestId;
        })
        ->values()
        ->all();

    $history = $request->session()->get('account_request_history', []);
    $history[] = [
        'id' => $target['id'] ?? (string) str()->uuid(),
        'name' => $target['name'] ?? '-',
        'username' => $target['username'] ?? '-',
        'role' => $target['role'] ?? '-',
        'school_name' => $target['school_name'] ?? null,
        'assigned_grade_level' => $target['assigned_grade_level'] ?? null,
        'assigned_section' => $target['assigned_section'] ?? null,
        'submitted_at' => $target['created_at'] ?? null,
        'status' => 'accepted',
        'decided_at' => now()->toIso8601String(),
    ];

    $request->session()->put('account_request_history', $history);
    $request->session()->put('pending_account_requests', $remaining);

    return back()->with('success', 'Account request approved and account created.');
})->name('dashboard.system-admin.requests.approve');

Route::post('/dashboard/system-admin/requests/{requestId}/decline', function (Request $request, string $requestId) {
    if ($request->session()->get('active_role') !== 'system_admin') {
        return redirect()
            ->route('login')
            ->with('error', 'Only System Admin can decline account requests.');
    }

    $pendingRequests = collect($request->session()->get('pending_account_requests', []));
    $target = $pendingRequests->first(function (array $item) use ($requestId): bool {
        return (string) ($item['id'] ?? '') === $requestId;
    });

    if (!$target) {
        return back()->with('error', 'Account request not found.');
    }

    $remaining = $pendingRequests
        ->reject(function (array $item) use ($requestId): bool {
            return (string) ($item['id'] ?? '') === $requestId;
        })
        ->values()
        ->all();

    $history = $request->session()->get('account_request_history', []);
    $history[] = [
        'id' => $target['id'] ?? (string) str()->uuid(),
        'name' => $target['name'] ?? '-',
        'username' => $target['username'] ?? '-',
        'role' => $target['role'] ?? '-',
        'school_name' => $target['school_name'] ?? null,
        'assigned_grade_level' => $target['assigned_grade_level'] ?? null,
        'assigned_section' => $target['assigned_section'] ?? null,
        'submitted_at' => $target['created_at'] ?? null,
        'status' => 'declined',
        'decided_at' => now()->toIso8601String(),
    ];

    $request->session()->put('account_request_history', $history);
    $request->session()->put('pending_account_requests', $remaining);

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

Route::post('/logout', function () {
    session()->forget(['assigned_grade_level', 'assigned_section', 'assigned_school_name', 'active_role', 'active_name', 'active_username']);
    return redirect()->route('login');
})->name('logout');

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => ['required', 'string'],
        'password' => ['required', 'string'],
    ]);

    $username = strtolower(trim((string) $request->input('email')));
    $accounts = collect($request->session()->get('user_accounts', []));
    $matchingAccounts = $accounts->filter(function (array $item) use ($username): bool {
        $itemUsername = strtolower(trim((string) ($item['username'] ?? '')));
        return $itemUsername === $username;
    })->values();

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
