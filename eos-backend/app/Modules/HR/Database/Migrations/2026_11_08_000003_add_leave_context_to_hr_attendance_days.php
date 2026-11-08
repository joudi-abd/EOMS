<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_days', function (Blueprint $table) {
            // Snapshot of the approved leave the day was calculated with; approved days keep it frozen.
            $table->foreignId('leave_request_day_id')->nullable()->after('policy_id')
                ->constrained('hr_leave_request_days')->restrictOnDelete();
            $table->unsignedInteger('required_minutes')->nullable()->after('worked_minutes');
            $table->unsignedInteger('leave_minutes')->nullable()->after('required_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_request_day_id');
            $table->dropColumn(['required_minutes', 'leave_minutes']);
        });
    }
};
