<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies are managed from settings (D3): decimals for storage/display, the payroll rounding step, the one base
 * currency every amount is also kept in (USD), which currencies salaries may be paid in (D20), and activation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sys_currencies', function (Blueprint $table) {
            $table->unsignedTinyInteger('decimals')->default(2)->after('symbol');
            $table->decimal('rounding_step', 20, 4)->default(1)->after('decimals');
            $table->boolean('is_base')->default(false)->after('rounding_step');
            $table->boolean('is_payroll_currency')->default(false)->after('is_base');
            $table->boolean('is_active')->default(true)->after('is_payroll_currency');
        });

        DB::table('sys_currencies')->where('code', 'USD')->update(['decimals' => 2, 'rounding_step' => 1, 'is_base' => true, 'is_payroll_currency' => true]);
        DB::table('sys_currencies')->where('code', 'SYP')->update(['decimals' => 0, 'rounding_step' => 1000, 'is_payroll_currency' => true]);
    }

    public function down(): void
    {
        Schema::table('sys_currencies', function (Blueprint $table) {
            $table->dropColumn(['decimals', 'rounding_step', 'is_base', 'is_payroll_currency', 'is_active']);
        });
    }
};
