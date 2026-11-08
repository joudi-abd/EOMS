<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            // HR user who entered the request on the employee's behalf; null when the employee submitted it.
            $table->foreignId('entered_by')->nullable()->after('submitted_by')->constrained('usr_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entered_by');
        });
    }
};
