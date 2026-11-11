<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * At most one non-voided payment per pay-run line. Application locks still apply; this blocks any missed path.
 * MySQL/MariaDB: functional unique (NULL for voided rows). PostgreSQL: partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('CREATE UNIQUE INDEX pay_payments_one_active_per_line ON pay_payments ((CASE WHEN voided_at IS NULL THEN pay_run_line_id END))');
        } elseif ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX pay_payments_one_active_per_line ON pay_payments (pay_run_line_id) WHERE voided_at IS NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX pay_payments_one_active_per_line ON pay_payments');
        } elseif ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS pay_payments_one_active_per_line');
        }
    }
};
