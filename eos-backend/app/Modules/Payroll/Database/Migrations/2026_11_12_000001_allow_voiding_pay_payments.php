<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A wrong payment is voided (kept for audit) and the line becomes payable again. */
    public function up(): void
    {
        Schema::table('pay_payments', function (Blueprint $table) {
            // Plain index first: MySQL keeps the foreign key on it once the unique index is dropped.
            $table->index('pay_run_line_id', 'pay_payments_line_idx');
        });
        Schema::table('pay_payments', function (Blueprint $table) {
            $table->dropUnique(['pay_run_line_id']);
            $table->timestamp('voided_at')->nullable()->after('note');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('usr_users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('pay_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
            $table->unique('pay_run_line_id');
        });
        Schema::table('pay_payments', function (Blueprint $table) {
            $table->dropIndex('pay_payments_line_idx');
        });
    }
};
