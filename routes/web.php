<?php

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

Route::get('/dashboard/consultation-log', function () {
    return view('dashboard.consultation-log');
})->name('dashboard.consultation-log');

Route::post('/login', function (Request $request) {
    return redirect()->route('dashboard.school-nurse');
});
