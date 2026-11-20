<?php

namespace App\Modules\Settings\Database\Seeders;

use App\Modules\Settings\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->firstOrCreate(['code' => 'HQ'], ['name' => 'المكتب الرئيسي', 'is_head_office' => true, 'is_active' => true]);
    }
}
