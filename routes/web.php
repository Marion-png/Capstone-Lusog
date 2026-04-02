<?php

use App\Http\Controllers\ConsultationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('auth.login');
});

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

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

Route::get('/dashboard/clinic-staff', function () {
    return view('dashboard.clinic-staff');
})->name('dashboard.clinic-staff');

Route::get('/dashboard/school-head', function () {
    return view('dashboard.school-head');
})->name('dashboard.school-head');

Route::get('/dashboard/system-admin', function () {
    return view('dashboard.system-admin');
})->name('dashboard.system-admin');

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
    return redirect()->route('login');
})->name('logout');

Route::post('/login', function (Request $request) {
    $role = $request->input('role');

    $routeByRole = [
        'school_nurse' => 'dashboard.school-nurse',
        'clinic_staff' => 'dashboard.clinic-staff',
        'school_head' => 'dashboard.school-head',
        'administrator' => 'dashboard.system-admin',
    ];

    return redirect()->route($routeByRole[$role] ?? 'dashboard.school-nurse');
});
