<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll on the rebuilt finance: a salary payment is a posted `payroll_payment` voucher, an approved run is a posted
 * `payroll_accrual` voucher, and a Sham Cash file is paid from a cash box.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        // SQLite rebuilds the table to add a foreign key and would lose the partial index's WHERE clause.
        if ($sqlite) {
            DB::statement('DROP INDEX IF EXISTS pay_payments_one_active_per_line');
        }
        Schema::table('pay_payments', function (Blueprint $table) {
            $table->foreignId('finance_voucher_id')->nullable()->after('paid_by')->constrained('fin_vouchers')->restrictOnDelete();
        });
        if ($sqlite) {
            DB::statement('CREATE UNIQUE INDEX pay_payments_one_active_per_line ON pay_payments (pay_run_line_id) WHERE voided_at IS NULL');
        }
        Schema::table('pay_exports', function (Blueprint $table) {
            $table->foreign('box_id')->references('id')->on('fin_cash_boxes')->restrictOnDelete();
        });
        Schema::table('pay_runs', function (Blueprint $table) {
            $table->foreignId('accrual_voucher_id')->nullable()->after('approved_at')->constrained('fin_vouchers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        Schema::table('pay_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accrual_voucher_id');
        });
        Schema::table('pay_exports', function (Blueprint $table) {
            $table->dropForeign(['box_id']);
        });
        if ($sqlite) {
            DB::statement('DROP INDEX IF EXISTS pay_payments_one_active_per_line');
        }
        Schema::table('pay_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finance_voucher_id');
        });
        if ($sqlite) {
            DB::statement('CREATE UNIQUE INDEX pay_payments_one_active_per_line ON pay_payments (pay_run_line_id) WHERE voided_at IS NULL');
        }
    }
};
