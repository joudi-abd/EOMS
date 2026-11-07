<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        Schema::create('hr_leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->date('effective_from');
            $table->unsignedTinyInteger('pay_percent');
            $table->decimal('annual_days', 10, 6)->nullable();
            $table->string('grant_method', 12)->nullable();
            $table->string('year_basis', 16)->nullable();
            $table->string('year_start', 5)->nullable();
            $table->string('first_year_method', 12)->nullable();
            $table->unsignedSmallInteger('retroactive_days')->default(0);
            $table->timestamps();
            $table->unique(['leave_type_id', 'effective_from']);
        });

        Schema::create('hr_office_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('hr_leave_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_period_id')->constrained('hr_employee_service_periods')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->foreignId('leave_policy_id')->constrained('hr_leave_policies')->restrictOnDelete();
            $table->date('cycle_start');
            $table->string('kind', 20);
            $table->bigInteger('day_units');
            $table->string('reason', 500);
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->timestamps();
            $table->index(['service_period_id', 'leave_type_id', 'cycle_start'], 'hr_leave_balance_scope');
        });

        foreach (['إجازة سنوية', 'إجازة مرضية', 'إجازة طارئة', 'إجازة بدون راتب'] as $name) {
            DB::table('hr_leave_types')->insert(['name' => $name, 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_balance_entries');
        Schema::dropIfExists('hr_office_holidays');
        Schema::dropIfExists('hr_leave_policies');
        Schema::dropIfExists('hr_leave_types');
    }
};
