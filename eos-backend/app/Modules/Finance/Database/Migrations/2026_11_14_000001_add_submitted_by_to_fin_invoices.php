<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who sent the invoice for posting: the approver must be neither its author nor the person who submitted it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('created_by')->constrained('usr_users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
        });
    }
};
