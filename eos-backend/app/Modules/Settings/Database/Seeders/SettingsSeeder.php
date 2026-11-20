<?php

namespace App\Modules\Settings\Database\Seeders;

use App\Modules\Settings\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['company.name', 'UFUQ', 'company'],
            ['attendance.work_start', '08:00', 'attendance'],
            ['attendance.work_end', '16:00', 'attendance'],
            ['attendance.grace_minutes', '15', 'attendance'],
            ['currency.default', 'USD', 'finance'],
            ['finance.contractor_tracking_mode_default', 'agreement', 'finance'],
            ['finance.budget_overrun_threshold_pct', '90', 'finance'],
            ['finance.count_difference_approver_role', 'company_manager', 'finance'],
            ['finance.consolidated_report_rate_mode', 'report_date', 'finance'],
            ['finance.custody_overdue_days', '30', 'finance'],
            ['finance.exchange_rate_tolerance_pct', '5', 'finance'],
            ['finance.project_code_start', '100100', 'finance'],
        ];

        foreach ($defaults as [$key, $value, $group]) {
            // firstOrCreate: لا يكتب فوق قيمة عدّلها المدير
            Setting::firstOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value, 'setting_group' => $group]
            );
        }
        // Payroll rounding now comes from each currency's rounding_step.
        Setting::query()->whereKey('payroll.rounding_decimals')->delete();
    }
}
