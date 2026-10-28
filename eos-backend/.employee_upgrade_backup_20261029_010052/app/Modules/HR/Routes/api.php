<?php

use App\Modules\HR\Http\Controllers\DepartmentController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\EmployeeJobHistoryController;
use App\Modules\HR\Http\Controllers\EmploymentContractController;
use App\Modules\HR\Http\Controllers\JobTitleController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {

    // الأقسام والمسميات
    Route::middleware('perm:employees.view')->group(function () {
        Route::get('departments', [DepartmentController::class, 'index']);
        Route::get('departments/{department}', [DepartmentController::class, 'show']);
        Route::get('job-titles', [JobTitleController::class, 'index']);
        Route::get('job-titles/{jobTitle}', [JobTitleController::class, 'show']);
    });

    Route::middleware('perm:employees.manage')->group(function () {
        Route::post('departments', [DepartmentController::class, 'store']);
        Route::put('departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy']);
        Route::post('job-titles', [JobTitleController::class, 'store']);
        Route::put('job-titles/{jobTitle}', [JobTitleController::class, 'update']);
        Route::delete('job-titles/{jobTitle}', [JobTitleController::class, 'destroy']);
    });

    // الموظفون
    Route::middleware('perm:employees.view')->group(function () {
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        Route::get('employees/{employee}/job-history', [EmployeeJobHistoryController::class, 'index']);
        Route::get('employees/{employee}/contracts', [EmploymentContractController::class, 'index']);
        Route::get('employees/{employee}/contracts/{contract}', [EmploymentContractController::class, 'show']);
    });

    Route::middleware('perm:employees.manage')->group(function () {
        Route::post('employees', [EmployeeController::class, 'store']);
        Route::put('employees/{employee}', [EmployeeController::class, 'update']);
        Route::post('employees/{employee}/contracts', [EmploymentContractController::class, 'store']);
        Route::post('employees/{employee}/contracts/{contract}/renew', [EmploymentContractController::class, 'renew']);
        Route::post('employees/{employee}/contracts/{contract}/activate', [EmploymentContractController::class, 'activate']);
        Route::post('employees/{employee}/contracts/{contract}/terminate', [EmploymentContractController::class, 'terminate']);
        Route::post('employees/{employee}/contracts/{contract}/schedule-termination', [EmploymentContractController::class, 'scheduleTermination']);
        Route::post('employees/{employee}/contracts/{contract}/cancel-scheduled-termination', [EmploymentContractController::class, 'cancelScheduledTermination']);
    });
});
