<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * At most one non-voided payment per pay-run line. Application locks still apply; this blocks any missed path.
 * MySQL/MariaDB: a generated column holding the line id while the payment is active (NULL once voided) with a
 * unique index on it. MariaDB has no index-on-expression syntax, so the column is spelled out for both.
 * PostgreSQL and SQLite: a partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('pay_payments', function (Blueprint $table) {
                $table->unsignedBigInteger('active_line_id')->nullable()
                    ->storedAs('CASE WHEN voided_at IS NULL THEN pay_run_line_id END');
                $table->unique('active_line_id', 'pay_payments_one_active_per_line');
            });
        } else {
            DB::statement('CREATE UNIQUE INDEX pay_payments_one_active_per_line ON pay_payments (pay_run_line_id) WHERE voided_at IS NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('pay_payments', function (Blueprint $table) {
                $table->dropUnique('pay_payments_one_active_per_line');
                $table->dropColumn('active_line_id');
            });
        } else {
            DB::statement('DROP INDEX IF EXISTS pay_payments_one_active_per_line');
        }
    }
};
