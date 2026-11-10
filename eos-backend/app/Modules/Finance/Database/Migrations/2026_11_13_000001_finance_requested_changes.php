<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_parties', function (Blueprint $table) {
            $table->string('sham_cash_wallet', 16)->nullable()->after('phone');
            $table->string('sham_cash_name', 150)->nullable()->after('sham_cash_wallet');
        });
        Schema::table('fin_invoice_lines', function (Blueprint $table) {
            $table->string('unit', 20)->nullable()->after('description'); // متر، كيلو، قطعة...
        });
        Schema::table('fin_journal_entries', function (Blueprint $table) {
            // Manual entries may be unbalanced: the difference is posted to the differences account.
            // > 0 in the company's favour (debit exceeded credit), < 0 in the customer's favour.
            $table->decimal('difference', 18, 2)->nullable()->after('description');
        });
        Schema::create('fin_invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('fin_invoices')->restrictOnDelete();
            $table->string('path', 300);
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('usr_users')->restrictOnDelete();
            $table->timestamps();
        });

        $now = now();
        foreach ([['3900', 'أرصدة افتتاحية', 'equity', 'opening_balances'], ['4900', 'فروقات القيود', 'revenue', 'entry_differences']] as [$code, $name, $type, $key]) {
            if (DB::table('fin_accounts')->where('system_key', $key)->exists()) {
                continue;
            }
            // Finance builds its own chart: never take a code it already uses.
            $free = $code;
            for ($i = 1; DB::table('fin_accounts')->where('code', $free)->exists(); $i++) {
                $free = $code.'-'.$i;
            }
            DB::table('fin_accounts')->insert(['code' => $free, 'name' => $name, 'type' => $type, 'system_key' => $key,
                'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_invoice_attachments');
        Schema::table('fin_journal_entries', fn (Blueprint $t) => $t->dropColumn('difference'));
        Schema::table('fin_invoice_lines', fn (Blueprint $t) => $t->dropColumn('unit'));
        Schema::table('fin_parties', fn (Blueprint $t) => $t->dropColumn(['sham_cash_wallet', 'sham_cash_name']));
        DB::table('fin_accounts')->whereIn('system_key', ['opening_balances', 'entry_differences'])
            ->whereNotIn('id', DB::table('fin_journal_lines')->select('account_id'))->delete();
    }
};
