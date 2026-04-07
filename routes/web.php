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
        'role' => ['required', 'in:school_nurse,clinic_staff,class_adviser,school_head,feeding_coor'],
        'assigned_grade_level' => ['required', 'string', 'max:50'],
        'assigned_section' => ['required', 'string', 'max:100'],
    ]);

    $pendingRequests = $request->session()->get('pending_account_requests', []);
    $username = strtolower(trim($validated['username']));
    $role = $validated['role'];

    $alreadyPending = collect($pendingRequests)->contains(function (array $item) use ($username, $role): bool {
        $existingUsername = strtolower(trim((string) ($item['username'] ?? '')));
        $existingRole = (string) ($item['role'] ?? '');
        return $existingUsername === $username && $existingRole === $role;
    });

    $alreadyApproved = collect($request->session()->get('user_accounts', []))->contains(function (array $item) use ($username, $role): bool {
        $existingUsername = strtolower(trim((string) ($item['username'] ?? '')));
        $existingRole = (string) ($item['role'] ?? '');
        return $existingUsername === $username && $existingRole === $role;
    });

    if ($alreadyPending || $alreadyApproved) {
        return back()
            ->withErrors(['username' => 'A request or account with this username and role already exists.'])
            ->withInput();
    }

    $pendingRequests[] = [
        'id' => (string) str()->uuid(),
        'name' => $validated['name'],
        'username' => $validated['username'],
        'role' => $role,
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

Route::get('/dashboard/school-head', [SchoolHeadController::class, 'index'])
    ->name('dashboard.school-head');

Route::get('/dashboard/school-head/reports', [SchoolHeadController::class, 'reports'])
    ->name('dashboard.school-head.reports');

Route::get('/dashboard/feedingcor-dashboard', [FeedingCoordinatorController::class, 'dashboard'])
    ->name('dashboard.feedingcor-dashboard');

Route::get('/dashboard/feedingcor-health-records', [StudentHealthRecordController::class, 'feedingHealthRecords'])
    ->name('dashboard.feedingcor-health-records');

Route::post('/dashboard/class-adviser/health-records/baseline', [StudentHealthRecordController::class, 'storeBaseline'])
    ->name('class-adviser.health-records.baseline.store');

Route::post('/dashboard/class-adviser/health-records/{record}/endline', [StudentHealthRecordController::class, 'storeEndline'])
    ->name('class-adviser.health-records.endline.store');

Route::get('/dashboard/feedingcor-program', [FeedingProgramController::class, 'index'])
    ->name('dashboard.feedingcor-program');

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
        'role' => ['required', 'in:school_nurse,clinic_staff,class_adviser,school_head,feeding_coor'],
        'assigned_grade_level' => ['required', 'string', 'max:50'],
        'assigned_section' => ['required', 'string', 'max:100'],
    ]);

    $accounts = $request->session()->get('user_accounts', []);
    $username = strtolower(trim($validated['username']));
    $role = $validated['role'];

    $alreadyExists = collect($accounts)->contains(function (array $account) use ($username, $role): bool {
        $existingUsername = strtolower(trim((string) ($account['username'] ?? '')));
        $existingRole = (string) ($account['role'] ?? '');
        return $existingUsername === $username && $existingRole === $role;
    });

    if ($alreadyExists) {
        return back()
            ->withErrors(['username' => 'An account with this username and role already exists.'])
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

    $alreadyExists = collect($accounts)->contains(function (array $account) use ($username, $role): bool {
        $existingUsername = strtolower(trim((string) ($account['username'] ?? '')));
        $existingRole = (string) ($account['role'] ?? '');
        return $existingUsername === $username && $existingRole === $role;
    });

    if (!$alreadyExists) {
        $accounts[] = [
            'name' => $target['name'] ?? '-',
            'username' => $target['username'] ?? '-',
            'role' => $role,
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
    session()->forget(['assigned_grade_level', 'assigned_section', 'active_role']);
    return redirect()->route('login');
})->name('logout');

Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => ['required', 'string'],
        'password' => ['required', 'string'],
        'role' => ['required', 'string'],
    ]);

    $username = strtolower(trim((string) $request->input('email')));
    $role = $request->input('role');
    $accounts = collect($request->session()->get('user_accounts', []));

    if ($role === 'class_adviser') {
        $account = $accounts->first(function (array $item) use ($username): bool {
            $itemUsername = strtolower(trim((string) ($item['username'] ?? '')));
            return $itemUsername === $username && ($item['role'] ?? null) === 'class_adviser';
        });

        if (!$account) {
            return back()
                ->withInput()
                ->with('error', 'Class Adviser account was not found. Ask System Admin to create your account and assign your grade/section first.');
        }

        $request->session()->put('assigned_grade_level', $account['assigned_grade_level'] ?? null);
        $request->session()->put('assigned_section', $account['assigned_section'] ?? null);
    } else {
        $request->session()->forget(['assigned_grade_level', 'assigned_section']);
    }

    $request->session()->put('active_role', $role);

    $routeByRole = [
        'school_nurse' => 'dashboard.school-nurse',
        'clinic_staff' => 'dashboard.clinic-staff',
        'class_adviser' => 'dashboard.class-adviser',
        'school_head' => 'dashboard.school-head',
        'feeding_coor' => 'dashboard.feedingcor-dashboard',
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
    $request->session()->forget(['assigned_grade_level', 'assigned_section']);

    return redirect()->route('dashboard.system-admin');
})->name('admin.login.submit');
