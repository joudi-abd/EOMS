<?php

namespace App\Modules\Settings\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** USD is the base currency; salaries are paid in USD or SYP (D20). SYP rounds payments to 1,000 and USD to 1. */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        DB::table('sys_currencies')->upsert([
            ['code' => 'USD', 'name' => 'الدولار الأمريكي', 'symbol' => '$', 'decimals' => 2, 'rounding_step' => 1, 'is_base' => true,
                'is_payroll_currency' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SYP', 'name' => 'الليرة السورية', 'symbol' => 'ل.س', 'decimals' => 0, 'rounding_step' => 1000, 'is_base' => false,
                'is_payroll_currency' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'TRY', 'name' => 'الليرة التركية', 'symbol' => '₺', 'decimals' => 2, 'rounding_step' => 1, 'is_base' => false,
                'is_payroll_currency' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'symbol', 'updated_at']);
    }
}
