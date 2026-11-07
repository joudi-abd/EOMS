<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_service_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'start_date'], 'hr_service_employee_start');
        });
        Schema::table('hr_attendance_bindings', function (Blueprint $table) {
            $table->date('revoked_from')->nullable();
        });
        // Preserve only the dates actually known for legacy employees; never invent past service cycles.
        DB::table('hr_employees')->orderBy('id')->chunkById(200, function ($employees) {
            $now = now();
            DB::table('hr_employee_service_periods')->insert($employees->map(fn ($employee) => [
                'employee_id' => $employee->id, 'start_date' => $employee->hire_date,
                'end_date' => $employee->termination_date, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_bindings', fn (Blueprint $table) => $table->dropColumn('revoked_from'));
        Schema::dropIfExists('hr_employee_service_periods');
    }
};
