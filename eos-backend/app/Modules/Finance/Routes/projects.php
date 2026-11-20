<?php

use App\Modules\Finance\Http\Controllers\BudgetLineController;
use App\Modules\Finance\Http\Controllers\ContractorAgreementController;
use App\Modules\Finance\Http\Controllers\ProjectBillingController;
use App\Modules\Finance\Http\Controllers\ProjectCostCategoryController;
use App\Modules\Finance\Http\Controllers\ProjectFinanceController;
use Illuminate\Support\Facades\Route;

// Project finance (phase 5): profiles, cost categories, budgets, contractor agreements, billings, profitability, statement.
// Reading: finance.projects.view (own = projects I manage; plus my branches' projects with finance.view / finance.projects).
// Writing: finance.projects (own = my branches); cost categories: finance.settings.
Route::prefix('api/v1/finance')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::middleware('perm:finance.projects.view')->group(function () {
        Route::get('projects', [ProjectFinanceController::class, 'index']);
        Route::get('projects/summary', [ProjectFinanceController::class, 'summary']);
        Route::get('projects/{project}', [ProjectFinanceController::class, 'show'])->whereNumber('project');
        Route::get('projects/{project}/profitability', [ProjectFinanceController::class, 'profitability'])->whereNumber('project');
        Route::get('projects/{project}/statement', [ProjectFinanceController::class, 'statement'])->whereNumber('project');
        Route::get('projects/{project}/budget', [BudgetLineController::class, 'index'])->whereNumber('project');
        Route::get('projects/{project}/billings', [ProjectBillingController::class, 'index'])->whereNumber('project');
        Route::get('projects/{project}/billing-suggestion', [ProjectBillingController::class, 'suggestion'])->whereNumber('project');
        Route::get('contractor-agreements', [ContractorAgreementController::class, 'index']);
        Route::get('contractor-agreements/{agreement}', [ContractorAgreementController::class, 'show'])->whereNumber('agreement');
        Route::get('project-cost-categories', [ProjectCostCategoryController::class, 'index']);
    });

    Route::middleware('perm:finance.projects')->group(function () {
        Route::post('projects', [ProjectFinanceController::class, 'store']);
        Route::patch('projects/{project}', [ProjectFinanceController::class, 'update'])->whereNumber('project');
        Route::post('projects/{project}/status', [ProjectFinanceController::class, 'status'])->whereNumber('project');
        Route::post('projects/{project}/budget-lines', [BudgetLineController::class, 'store'])->whereNumber('project');
        Route::patch('budget-lines/{line}', [BudgetLineController::class, 'update'])->whereNumber('line');
        Route::delete('budget-lines/{line}', [BudgetLineController::class, 'destroy'])->whereNumber('line');
        Route::post('projects/{project}/billings', [ProjectBillingController::class, 'store'])->whereNumber('project');
        Route::post('contractor-agreements', [ContractorAgreementController::class, 'store']);
        Route::patch('contractor-agreements/{agreement}', [ContractorAgreementController::class, 'update'])->whereNumber('agreement');
        Route::delete('contractor-agreements/{agreement}', [ContractorAgreementController::class, 'destroy'])->whereNumber('agreement');
    });

    Route::middleware('perm:finance.settings')->group(function () {
        Route::post('project-cost-categories', [ProjectCostCategoryController::class, 'store']);
        Route::patch('project-cost-categories/{category}', [ProjectCostCategoryController::class, 'update'])->whereNumber('category');
    });
});
