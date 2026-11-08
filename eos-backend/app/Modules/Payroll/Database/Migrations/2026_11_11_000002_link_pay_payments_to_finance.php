<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_payments', function (Blueprint $table) {
            // The finance cash operation (and journal entry) that took the salary out of a box.
            $table->foreignId('finance_transaction_id')->nullable()->after('paid_by')->constrained('fin_transactions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pay_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finance_transaction_id');
        });
    }
};
