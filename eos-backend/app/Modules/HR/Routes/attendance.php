<?php

use App\Modules\HR\Http\Controllers\AdmsController;
use App\Modules\HR\Http\Controllers\AttendanceController;
use App\Modules\HR\Http\Controllers\AttendanceIngestController;
use App\Modules\HR\Http\Controllers\AttendanceSettingsController;
use App\Modules\HR\Http\Middleware\AuthenticateAdmsDevice;
use App\Modules\HR\Http\Middleware\AuthenticateAttendanceDevice;
use Illuminate\Support\Facades\Route;

// Native PUSH routes deliberately have no /api prefix or web/CSRF middleware.
Route::middleware([AuthenticateAdmsDevice::class, 'throttle:600,1'])->group(function () {
    foreach (['cdata', 'devicecmd', 'ping', 'registry'] as $action) {
        Route::match(['GET', 'POST'], 'iclock/'.$action, [AdmsController::class, 'device']);
        Route::match(['GET', 'POST'], 'iclock/'.$action.'.aspx', [AdmsController::class, 'device']);
    }
    Route::get('iclock/getrequest', [AdmsController::class, 'device']);
    Route::get('iclock/getrequest.aspx', [AdmsController::class, 'device']);
});

Route::post('api/v1/attendance/device-ingest', [AttendanceIngestController::class, 'store'])
    ->middleware(['api', 'throttle:120,1', AuthenticateAttendanceDevice::class]);

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('employees/{employee}/attendance', [AttendanceController::class, 'employee']);
    Route::get('employees/{employee}/attendance-report', [AttendanceController::class, 'report']);
    Route::get('employees/{employee}/payroll-inputs', [AttendanceController::class, 'payrollInputs']);
    Route::get('attendance/days/{day}', [AttendanceController::class, 'show']);
    Route::post('attendance/days/{day}/corrections', [AttendanceController::class, 'requestCorrection']);

    Route::middleware('perm:attendance.view')->group(function () {
        Route::get('attendance/days', [AttendanceController::class, 'index']);
        Route::get('attendance/policies', [AttendanceSettingsController::class, 'policies']);
    });
    Route::middleware('perm:attendance.manage')->group(function () {
        Route::get('attendance/quarantined-punches', [AdmsController::class, 'quarantined']);
        Route::post('attendance/quarantined-punches/{quarantine}/review', [AdmsController::class, 'reviewQuarantined'])->middleware('perm:attendance.approve');
        Route::post('attendance/policies', [AttendanceSettingsController::class, 'storePolicy']);
        Route::patch('attendance/devices/{device}/connection', [AdmsController::class, 'configure']);
        Route::post('attendance/bindings/{binding}/enroll-fingerprint', [AdmsController::class, 'enroll']);
        Route::post('attendance/fingerprint-enrollments/{enrollment}/confirm', [AdmsController::class, 'confirm']);
        Route::get('attendance/bindings/{binding}/fingerprint-enrollment', [AdmsController::class, 'latest']);
        Route::post('attendance/fingerprint-enrollments/{enrollment}/cancel', [AdmsController::class, 'cancel']);
        Route::get('attendance/fingerprint-enrollments/{enrollment}', [AdmsController::class, 'status']);
        Route::get('attendance/devices', [AttendanceSettingsController::class, 'devices']);
        Route::post('attendance/devices', [AttendanceSettingsController::class, 'storeDevice']);
        Route::patch('attendance/devices/{device}', [AttendanceSettingsController::class, 'updateDevice']);
        Route::post('attendance/devices/{device}/rotate-token', [AttendanceSettingsController::class, 'rotateToken']);
        Route::get('attendance/devices/{device}/bindings', [AttendanceSettingsController::class, 'bindings']);
        Route::post('attendance/devices/{device}/bindings', [AttendanceSettingsController::class, 'storeBinding']);
        Route::post('attendance/bindings/{binding}/close', [AttendanceSettingsController::class, 'closeBinding']);
        Route::get('attendance/unmatched-punches', [AttendanceController::class, 'unmatched']);
    });
    Route::middleware('perm:attendance.approve')->group(function () {
        Route::post('attendance/corrections/{correction}/review', [AttendanceController::class, 'reviewCorrection']);
        Route::post('attendance/days/{day}/approve', [AttendanceController::class, 'approve']);
        Route::post('attendance/days/{day}/reopen', [AttendanceController::class, 'reopen']);
    });
});
