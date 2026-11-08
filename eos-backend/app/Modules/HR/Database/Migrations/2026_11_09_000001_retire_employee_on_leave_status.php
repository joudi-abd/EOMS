<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Leave is now tracked per day by approved leave requests, never as an employee status. */
    public function up(): void
    {
        DB::table('hr_employees')->where('status', 'on_leave')->update(['status' => 'active']);
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->enum('status', ['active', 'terminated'])->default('active')->change();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->enum('status', ['active', 'on_leave', 'terminated'])->default('active')->change();
        });
    }
};
