<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employee_job_histories', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->constrained('hr_employment_contracts')->restrictOnDelete();
            $table->json('contract_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_job_histories', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropColumn(['contract_id', 'contract_context']);
        });
    }
};
