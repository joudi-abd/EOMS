<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave review is routed by permission, not by role name:
 * - staff: decided by holders of leaves.approve (was "hr_manager");
 * - reviewers: the requester approves leave themselves, so holders of leaves.approve_reviewers decide
 *   (was "company_manager").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->string('approval_level', 12)->default('staff')->after('approver_role');
        });
        DB::table('hr_leave_requests')->where('approver_role', 'company_manager')->update(['approval_level' => 'reviewers']);
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->dropColumn('approver_role');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->string('approver_role', 30)->default('hr_manager')->after('approval_level');
        });
        DB::table('hr_leave_requests')->where('approval_level', 'reviewers')->update(['approver_role' => 'company_manager']);
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->dropColumn('approval_level');
        });
    }
};
