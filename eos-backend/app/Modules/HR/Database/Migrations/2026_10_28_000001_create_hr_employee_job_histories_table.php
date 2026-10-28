<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_job_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->unsignedBigInteger('old_department_id')->nullable();
            $table->unsignedBigInteger('new_department_id')->nullable();
            $table->unsignedBigInteger('old_job_title_id')->nullable();
            $table->unsignedBigInteger('new_job_title_id')->nullable();
            $table->unsignedBigInteger('old_manager_id')->nullable();
            $table->unsignedBigInteger('new_manager_id')->nullable();
            $table->date('effective_date');
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_date'], 'hr_job_history_emp_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_job_histories');
    }
};
