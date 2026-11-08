<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_departments', function (Blueprint $table) {
            // The employee who manages this department (and its sub-departments) for scoped access.
            $table->foreignId('manager_employee_id')->nullable()->after('code')
                ->constrained('hr_employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_employee_id');
        });
    }
};
