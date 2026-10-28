<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employment_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('contract_number', 50)->unique();
            $table->string('type', 30); // fixed_term / indefinite (provisional)
            $table->string('status', 25)->default('draft'); // draft / active / terminated
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('terminated_at')->nullable();
            $table->string('termination_reason', 500)->nullable();
            $table->foreignId('renewed_from_id')->nullable()->constrained('hr_employment_contracts')->restrictOnDelete();
            $table->decimal('base_salary', 15, 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'start_date'], 'hr_contracts_employee_status_start_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employment_contracts');
    }
};
