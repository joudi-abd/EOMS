<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sys_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_currency_id')->constrained('sys_currencies');
            $table->foreignId('to_currency_id')->constrained('sys_currencies');
            $table->decimal('rate', 18, 6);
            $table->date('rate_date');
            $table->timestamps();
            $table->unique(['from_currency_id', 'to_currency_id', 'rate_date'], 'sys_exchange_rates_currency_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sys_exchange_rates');
    }
};
