<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R17: the day the overdue-custody notice last went out, so `finance:custody-overdue` notifies once per custody per day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_custodies', function (Blueprint $table) {
            $table->date('overdue_notified_on')->nullable()->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('fin_custodies', function (Blueprint $table) {
            $table->dropColumn('overdue_notified_on');
        });
    }
};
