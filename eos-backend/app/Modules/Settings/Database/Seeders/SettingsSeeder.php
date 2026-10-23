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
            ['payroll.rounding_decimals', '2', 'payroll'],
        ];

        foreach ($defaults as [$key, $value, $group]) {
            // firstOrCreate: لا يكتب فوق قيمة عدّلها المدير
            Setting::firstOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value, 'setting_group' => $group]
            );
        }
    }
}