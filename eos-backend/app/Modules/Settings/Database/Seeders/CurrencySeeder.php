<?php

namespace App\Modules\Settings\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('sys_currencies')->upsert([
            ['code' => 'SYP', 'name' => 'الليرة السورية', 'symbol' => 'ل.س', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => 'الدولار الأمريكي', 'symbol' => '$', 'created_at' => now(), 'updated_at' => now()],
        ], ['code'], ['name', 'symbol', 'updated_at']);
    }
}