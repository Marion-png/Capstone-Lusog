<?php

use App\Http\Controllers\AdviserController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\CsvUploadController;
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

Route::get('/csv/upload', function () {
    return view('dashboard.csv-upload');
})->name('csv.upload.form');

Route::post('/csv/upload', [CsvUploadController::class, 'upload'])
    ->name('csv.upload');

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
        'class_adviser' => 'dashboard.class-adviser',
        'school_head' => 'dashboard.school-head',
        'administrator' => 'dashboard.system-admin',
    ];

    return redirect()->route($routeByRole[$role] ?? 'dashboard.school-nurse');
});
