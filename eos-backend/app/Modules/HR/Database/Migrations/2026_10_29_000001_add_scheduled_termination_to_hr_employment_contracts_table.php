<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employment_contracts', function (Blueprint $table) {
            $table->date('scheduled_termination_at')->nullable()->after('termination_reason');
            $table->string('scheduled_termination_reason', 500)->nullable()->after('scheduled_termination_at');
            $table->index(['status', 'scheduled_termination_at'], 'hr_contract_schedule_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employment_contracts', function (Blueprint $table) {
            $table->dropIndex('hr_contract_schedule_due_idx');
            $table->dropColumn(['scheduled_termination_at', 'scheduled_termination_reason']);
        });
    }
};
