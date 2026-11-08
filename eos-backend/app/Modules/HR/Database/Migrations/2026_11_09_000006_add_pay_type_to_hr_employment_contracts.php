<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employment_contracts', function (Blueprint $table) {
            // base_salary means a monthly salary, a daily wage or an hourly wage according to this type.
            $table->string('pay_type', 10)->default('monthly')->after('base_salary');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employment_contracts', function (Blueprint $table) {
            $table->dropColumn('pay_type');
        });
    }
};
