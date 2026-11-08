<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_period_id')->constrained('hr_employee_service_periods');
            $table->foreignId('leave_type_id')->constrained('hr_leave_types');
            $table->foreignId('leave_policy_id')->constrained('hr_leave_policies');
            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->date('grant_start');
            $table->bigInteger('annual_units');
            $table->bigInteger('granted_units')->default(0);
            $table->boolean('manual')->default(false);
            $table->json('policy');
            $table->timestamps();
            $table->unique(['service_period_id', 'leave_type_id', 'cycle_start'], 'hr_leave_cycle_unique');
        });
        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees');
            $table->foreignId('service_period_id')->constrained('hr_employee_service_periods');
            $table->foreignId('leave_type_id')->constrained('hr_leave_types');
            $table->foreignId('leave_policy_id')->constrained('hr_leave_policies');
            $table->unsignedBigInteger('submitted_by');
            $table->uuid('submission_key')->unique();
            $table->string('status', 30)->default('pending');
            $table->string('approver_role', 30);
            $table->json('policy');
            $table->json('input');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('unpaid_consent')->default(false);
            $table->date('expires_on');
            $table->json('change_request')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });
        Schema::create('hr_leave_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('hr_leave_requests');
            $table->foreignId('employee_id')->constrained('hr_employees');
            $table->date('date');
            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('day_minutes');
            $table->bigInteger('day_units');
            $table->bigInteger('covered_units');
            $table->bigInteger('unpaid_units');
            $table->unsignedTinyInteger('pay_percent');
            $table->boolean('active')->default(true);
            $table->json('schedule');
            $table->timestamps();
            $table->index(['employee_id', 'date', 'active'], 'hr_leave_day_collision');
        });
        Schema::table('hr_leave_balance_entries', function (Blueprint $table) {
            $table->foreignId('leave_request_day_id')->nullable()->constrained('hr_leave_request_days');
        });
        Schema::create('hr_leave_half_days', function (Blueprint $table) {
            $table->id();
            $table->string('first_start', 5);
            $table->string('first_end', 5);
            $table->string('second_start', 5);
            $table->string('second_end', 5);
        });
        DB::table('hr_leave_half_days')->insert(['id' => 1, 'first_start' => '09:00', 'first_end' => '12:30', 'second_start' => '12:30', 'second_end' => '16:00']);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_half_days');
        Schema::table('hr_leave_balance_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_request_day_id');
        });
        Schema::dropIfExists('hr_leave_request_days');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_leave_cycles');
    }
};
