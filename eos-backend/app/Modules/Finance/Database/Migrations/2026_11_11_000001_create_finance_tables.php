<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chart of accounts, built by finance. system_key marks the accounts automatic postings rely on.
        Schema::create('fin_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('fin_accounts')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('type', 10); // asset | liability | equity | revenue | expense
            $table->string('system_key', 30)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // A journal entry has one currency and must balance; posted entries are never edited, only reversed.
        Schema::create('fin_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->date('date');
            $table->string('currency_code', 3);
            $table->string('description', 500);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('fin_journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('usr_users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['currency_code', 'date']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('fin_parties', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // customer | supplier
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('fin_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('fin_journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('fin_accounts')->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('fin_parties')->restrictOnDelete();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('memo', 500)->nullable();
            $table->index(['account_id', 'party_id']);
        });

        // A month with no row is open. close_requested waits for the company manager.
        Schema::create('fin_periods', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique();
            $table->string('status', 16);
            $table->foreignId('requested_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('fin_boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('kind', 12); // cash | sham_cash
            $table->string('currency_code', 3);
            $table->foreignId('account_id')->unique()->constrained('fin_accounts')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Above this amount a payment or invoice waits for the company manager. No row: everything waits.
        Schema::create('fin_approval_limits', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 3)->unique();
            $table->decimal('amount', 18, 2);
            $table->foreignId('updated_by')->constrained('usr_users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('fin_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('unit', 20);
            $table->string('currency_code', 3); // valuation currency: purchases only in this currency
            $table->foreignId('revenue_account_id')->nullable()->constrained('fin_accounts')->restrictOnDelete();
            $table->decimal('quantity', 16, 3)->default(0);
            $table->decimal('average_cost', 18, 6)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('fin_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // sale | purchase
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('party_id')->constrained('fin_parties')->restrictOnDelete();
            $table->date('date');
            $table->date('due_date');
            $table->string('currency_code', 3);
            $table->string('status', 16)->default('draft'); // draft | pending | posted | rejected | cancelled
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid', 18, 2)->default(0);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->constrained('usr_users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->string('decision_note', 1000)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('fin_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('fin_invoices')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('fin_items')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('fin_accounts')->restrictOnDelete();
            $table->string('description', 300);
            $table->decimal('quantity', 16, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->decimal('cost_amount', 18, 2)->nullable(); // sale of a stock item: cost at sale time
        });

        Schema::create('fin_stock_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('fin_items')->restrictOnDelete();
            $table->date('date');
            $table->decimal('quantity', 16, 3); // + in, − out
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('quantity_after', 16, 3);
            $table->decimal('average_cost_after', 18, 6);
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('usr_users')->restrictOnDelete();
            $table->timestamps();
        });

        // Cash operations: receipt, payment, transfer, exchange, payroll. Each posts one entry (exchange: two).
        Schema::create('fin_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);
            $table->date('date');
            $table->foreignId('box_id')->constrained('fin_boxes')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->foreignId('counter_box_id')->nullable()->constrained('fin_boxes')->restrictOnDelete();
            $table->decimal('counter_amount', 18, 2)->nullable();
            $table->decimal('rate', 18, 6)->nullable();
            $table->foreignId('party_id')->nullable()->constrained('fin_parties')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('fin_invoices')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('fin_accounts')->restrictOnDelete();
            $table->string('description', 500);
            $table->string('status', 12); // pending | posted | rejected | reversed
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->constrained('usr_users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('fin_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last')->default(0);
            $table->unique(['prefix', 'year']);
        });

        $now = now();
        DB::table('fin_accounts')->insert(collect([
            ['1200', 'ذمم العملاء', 'asset', 'receivables'], ['1300', 'المخزون', 'asset', 'inventory'],
            ['1900', 'وسيط صرف العملات', 'asset', 'fx_clearing'], ['2100', 'ذمم الموردين', 'liability', 'payables'],
            ['5100', 'تكلفة البضاعة المباعة', 'expense', 'cogs'], ['5200', 'الرواتب والأجور', 'expense', 'salaries'],
        ])->map(fn ($a) => ['code' => $a[0], 'name' => $a[1], 'type' => $a[2], 'system_key' => $a[3],
            'active' => true, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    public function down(): void
    {
        foreach (['fin_sequences', 'fin_transactions', 'fin_stock_moves', 'fin_invoice_lines', 'fin_invoices', 'fin_items',
            'fin_approval_limits', 'fin_boxes', 'fin_periods', 'fin_journal_lines', 'fin_parties', 'fin_journal_entries', 'fin_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
