<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening balances (spec §12, step 7) carry a contractor agreement (paid to date) or a custody (open balance) on their
 * lines; the opening-balance poster passes every voucher-line dimension on to the journal line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_voucher_lines', function (Blueprint $table) {
            $table->foreignId('contractor_agreement_id')->nullable()->after('employee_id')->constrained('fin_contractor_agreements')->restrictOnDelete();
            $table->foreignId('custody_id')->nullable()->after('contractor_agreement_id')->constrained('fin_custodies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fin_voucher_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custody_id');
            $table->dropConstrainedForeignId('contractor_agreement_id');
        });
    }
};
