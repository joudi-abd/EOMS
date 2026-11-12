<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Access\AccessSync;
use Illuminate\Database\Seeder;

/**
 * Kept for `db:seed` and the tests: the catalogue and the shipped roles live in code
 * (PermissionCatalog, DefaultRoles) and `permissions:sync` writes them.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(AccessSync::class)->run();
    }
}
