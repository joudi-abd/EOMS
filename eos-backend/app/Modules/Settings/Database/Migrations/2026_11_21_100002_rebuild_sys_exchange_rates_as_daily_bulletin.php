<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pair rates (from → to) become a daily bulletin against the base currency: one row per currency and day holding
 * how many units of the currency buy one USD. Old pair rows are converted where one side is USD
 * (USD→X keeps the rate, X→USD is inverted); any other pair is dropped. A direct USD→X row wins over an inverted one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = DB::table('sys_currencies')->where('code', 'USD')->value('id');
        $converted = [];
        if ($base !== null) {
            foreach (DB::table('sys_exchange_rates')->orderBy('id')->get() as $row) {
                $date = substr((string) $row->rate_date, 0, 10);
                if ((int) $row->from_currency_id === (int) $base && (int) $row->to_currency_id !== (int) $base && (float) $row->rate > 0) {
                    $converted[$row->to_currency_id.'|'.$date] = ['currency_id' => $row->to_currency_id, 'rate_date' => $date,
                        'rate_to_base' => bcadd((string) $row->rate, '0', 8), 'direct' => true];
                } elseif ((int) $row->to_currency_id === (int) $base && (int) $row->from_currency_id !== (int) $base && (float) $row->rate > 0) {
                    $key = $row->from_currency_id.'|'.$date;
                    if (! ($converted[$key]['direct'] ?? false)) {
                        $converted[$key] = ['currency_id' => $row->from_currency_id, 'rate_date' => $date,
                            'rate_to_base' => bcdiv('1', (string) $row->rate, 8), 'direct' => false];
                    }
                }
            }
        }

        Schema::drop('sys_exchange_rates');
        Schema::create('sys_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->date('rate_date');
            $table->decimal('rate_to_base', 20, 8); // units of the currency per 1 base (USD)
            $table->string('source', 20)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('usr_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['currency_id', 'rate_date']);
            $table->index('rate_date');
        });

        $now = now();
        foreach (array_chunk(array_values($converted), 200) as $chunk) {
            DB::table('sys_exchange_rates')->insert(array_map(fn (array $r) => ['currency_id' => $r['currency_id'], 'rate_date' => $r['rate_date'],
                'rate_to_base' => $r['rate_to_base'], 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_exchange_rates');
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
};
