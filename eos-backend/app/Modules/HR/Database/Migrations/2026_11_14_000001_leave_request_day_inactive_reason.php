<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_request_days', function (Blueprint $table) {
            // Why the day was deactivated, and the request status at that moment (for holiday undo).
            $table->string('inactive_reason', 40)->nullable()->after('active');
            $table->string('inactive_from_status', 20)->nullable()->after('inactive_reason');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_request_days', function (Blueprint $table) {
            $table->dropColumn(['inactive_reason', 'inactive_from_status']);
        });
    }
};
