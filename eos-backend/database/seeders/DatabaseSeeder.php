<?php

namespace Database\Seeders;

use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Database\Seeders\SystemAdminSeeder;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            RolePermissionSeeder::class,
            SystemAdminSeeder::class,
        ]);
    }
}
