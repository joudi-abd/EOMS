<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The finance rebuild (FINANCE_SYSTEM_SPEC, OP-7: the old finance data is test data only). Drops the old single-currency
 * ledger, boxes, operations, invoices and stock, and creates the whole new schema for every phase: core ledger,
 * treasury, parties, expenses, project finance, advances and custody, assets and allocation. The old migrations stay
 * in place: they create the old tables and this one removes them.
 *
 * Payroll's links to the old tables (pay_payments.finance_transaction_id, pay_exports.box_id) are dropped here; the
 * Payroll module re-links them to the new tables in its own migration.
 */
return new class extends Migration
{
    /** Old tables, children first. */
    private const OLD = ['fin_transactions', 'fin_stock_moves', 'fin_invoice_attachments', 'fin_invoice_lines', 'fin_invoices', 'fin_items',
        'fin_approval_limits', 'fin_boxes', 'fin_journal_lines', 'fin_journal_entries', 'fin_parties', 'fin_periods', 'fin_sequences', 'fin_accounts'];

    /** New tables, children first (for down()). */
    private const NEW = ['fin_payroll_time_shares', 'fin_allocation_lines', 'fin_allocations', 'fin_asset_disposals', 'fin_asset_maintenance',
        'fin_asset_custody_history', 'fin_employee_advance_installments', 'fin_employee_advances', 'fin_site_requests', 'fin_user_branches',
        'fin_attachments', 'fin_cash_count_lines', 'fin_cash_counts', 'fin_voucher_lines', 'fin_vouchers', 'fin_account_balances',
        'fin_journal_lines', 'fin_assets', 'fin_journal_entries', 'fin_cash_box_currencies', 'fin_cash_boxes', 'fin_asset_categories',
        'fin_custodies', 'fin_contractor_agreements', 'fin_project_budget_lines', 'fin_project_profiles', 'fin_project_cost_categories',
        'fin_expense_categories', 'fin_partners', 'fin_party_roles', 'fin_parties', 'fin_sequences', 'fin_periods', 'fin_system_accounts',
        'fin_accounts'];

    public function up(): void
    {
        $this->detachPayroll();
        foreach (self::OLD as $table) {
            Schema::dropIfExists($table);
        }

        $this->core();
        $this->parties();
        $this->expensesAndProjects();
        $this->treasury();
        $this->vouchers();
        $this->operations();
    }

    /**
     * Drops the new schema and puts the old one back by replaying the old migrations, so a full rollback (and every
     * older migration's down()) still works. Old data is not restored.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::NEW as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        $finance = __DIR__;
        $payroll = dirname(__DIR__, 3).'/Payroll/Database/Migrations';
        (require $finance.'/2026_11_11_000001_create_finance_tables.php')->up();
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        if ($sqlite) {
            DB::statement('DROP INDEX IF EXISTS pay_payments_one_active_per_line');
        }
        (require $payroll.'/2026_11_11_000002_link_pay_payments_to_finance.php')->up();
        if ($sqlite) {
            DB::statement('CREATE UNIQUE INDEX pay_payments_one_active_per_line ON pay_payments (pay_run_line_id) WHERE voided_at IS NULL');
        }
        (require $finance.'/2026_11_13_000001_finance_requested_changes.php')->up();
        (require $finance.'/2026_11_14_000001_add_submitted_by_to_fin_invoices.php')->up();
        Schema::table('pay_exports', function (Blueprint $table) {
            $table->foreign('box_id')->references('id')->on('fin_boxes')->restrictOnDelete();
        });
    }

    private function detachPayroll(): void
    {
        // SQLite rebuilds the table to drop a foreign key and would turn the partial "one active payment per line"
        // index into a plain unique one, so it is dropped first and recreated afterwards.
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        if ($sqlite) {
            DB::statement('DROP INDEX IF EXISTS pay_payments_one_active_per_line');
        }
        if (Schema::hasColumn('pay_payments', 'finance_transaction_id')) {
            Schema::table('pay_payments', function (Blueprint $table) {
                $table->dropForeign(['finance_transaction_id']);
            });
            Schema::table('pay_payments', function (Blueprint $table) {
                $table->dropColumn('finance_transaction_id');
            });
        }
        if ($sqlite) {
            DB::statement('CREATE UNIQUE INDEX pay_payments_one_active_per_line ON pay_payments (pay_run_line_id) WHERE voided_at IS NULL');
        }
        if (Schema::hasColumn('pay_exports', 'box_id')) {
            Schema::table('pay_exports', function (Blueprint $table) {
                $table->dropForeign(['box_id']);
            });
            DB::table('pay_exports')->update(['box_id' => null]); // the old boxes are gone
        }
    }

    private function users(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('usr_users')->restrictOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('usr_users')->restrictOnDelete();
    }

    private function core(): void
    {
        Schema::create('fin_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('fin_accounts')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('type', 10); // asset | liability | equity | revenue | expense
            $table->boolean('is_postable')->default(true);
            $table->boolean('requires_party')->default(false);
            $table->boolean('requires_project')->default(false);
            $table->boolean('is_system')->default(false); // box accounts: never deactivated or deleted
            $table->boolean('is_active')->default(true);
            $this->users($table);
            $table->timestamps();
            $table->index(['type', 'is_active']);
        });

        // Spec "system_account_mappings": which chart account plays each fixed role.
        Schema::create('fin_system_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('role_key', 40)->unique();
            $table->foreignId('account_id')->constrained('fin_accounts')->restrictOnDelete();
            $this->users($table);
            $table->timestamps();
        });

        Schema::create('fin_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 10)->default('open'); // open | closed
            $table->foreignId('closed_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        // Gapless numbers: the row is locked while a number is taken. year 0 = not yearly, branch_id 0 = company-wide.
        Schema::create('fin_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 20);
            $table->unsignedSmallInteger('year')->default(0);
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->string('prefix', 10);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['doc_type', 'year', 'branch_id']);
        });
    }

    private function parties(): void
    {
        Schema::create('fin_parties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('employee_id')->nullable()->unique()->constrained('hr_employees')->restrictOnDelete();
            $table->string('contractor_tracking_mode', 20)->nullable(); // agreement | payments_only; null = the setting
            $table->string('trade', 100)->nullable();
            $table->string('sham_cash_wallet', 16)->nullable();
            $table->string('sham_cash_name', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $this->users($table);
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('fin_party_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('fin_parties')->cascadeOnDelete();
            $table->string('role', 20); // client | supplier | contractor | employee | partner | other
            $table->timestamps();
            $table->unique(['party_id', 'role']);
            $table->index('role');
        });

        Schema::create('fin_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->unique()->constrained('fin_parties')->restrictOnDelete();
            $table->decimal('share_pct', 9, 4)->nullable();
            $table->date('joined_at')->nullable();
            $table->boolean('is_active')->default(true);
            $this->users($table);
            $table->timestamps();
        });
    }

    private function expensesAndProjects(): void
    {
        Schema::create('fin_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('fin_expense_categories')->restrictOnDelete();
            $table->string('name', 150);
            $table->foreignId('account_id')->nullable()->constrained('fin_accounts')->restrictOnDelete();
            $table->boolean('is_overhead_allocatable')->default(false);
            $table->boolean('is_active')->default(true);
            $this->users($table);
            $table->timestamps();
        });

        Schema::create('fin_project_cost_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('system_key', 30)->nullable()->unique();
            $table->boolean('is_billable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $this->users($table);
            $table->timestamps();
        });

        Schema::create('fin_project_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained('prj_projects')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->foreignId('client_party_id')->nullable()->constrained('fin_parties')->restrictOnDelete();
            $table->string('contract_type', 30); // cost_plus | percentage_of_receipts | lump_sum | internal
            $table->foreignId('contract_currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->decimal('contract_value', 20, 4)->nullable();
            $table->decimal('fee_pct', 9, 4)->nullable();
            $table->json('fee_base_category_ids')->nullable(); // null = every billable category
            $this->users($table);
            $table->timestamps();
        });

        Schema::create('fin_project_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('prj_projects')->restrictOnDelete();
            $table->foreignId('project_cost_category_id')->constrained('fin_project_cost_categories')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->decimal('budget_amount', 20, 4);
            $table->string('notes', 500)->nullable();
            $table->string('alert_level', 10)->default('none'); // none | warning | exceeded
            $this->users($table);
            $table->timestamps();
            $table->unique(['project_id', 'project_cost_category_id', 'currency_id'], 'fin_budget_lines_project_category_currency_unique');
        });

        Schema::create('fin_contractor_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('prj_projects')->restrictOnDelete();
            $table->foreignId('contractor_party_id')->constrained('fin_parties')->restrictOnDelete();
            $table->string('scope', 300);
            $table->foreignId('project_cost_category_id')->constrained('fin_project_cost_categories')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->decimal('agreed_value', 20, 4);
            $table->decimal('discount', 20, 4)->default(0);
            $table->decimal('net_value', 20, 4);
            $table->string('status', 12)->default('open'); // open | completed | cancelled
            $table->string('notes', 1000)->nullable();
            $this->users($table);
            $table->timestamps();
            $table->index(['project_id', 'contractor_party_id']);
        });

        Schema::create('fin_custodies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holder_party_id')->constrained('fin_parties')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('prj_projects')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $table->string('status', 10)->default('open'); // open | closed
            $table->date('opened_on');
            $table->date('closed_on')->nullable();
            $table->decimal('issued_total', 20, 4)->default(0);
            $table->decimal('settled_total', 20, 4)->default(0);
            $table->decimal('returned_total', 20, 4)->default(0);
            $table->decimal('balance', 20, 4)->default(0); // cached: recomputed from posted vouchers on every post/reverse
            $this->users($table);
            $table->timestamps();
            $table->index(['holder_party_id', 'status']);
        });

        Schema::create('fin_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('parent_id')->nullable()->constrained('fin_asset_categories')->restrictOnDelete();
            $this->users($table);
            $table->timestamps();
        });
    }

    private function treasury(): void
    {
        Schema::create('fin_cash_boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('type', 20); // cash_safe | exchange_office | bank | e_wallet
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $table->foreignId('account_id')->unique()->constrained('fin_accounts')->restrictOnDelete();
            $table->foreignId('custodian_user_id')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->text('contact_info')->nullable();
            $table->boolean('is_active')->default(true);
            $this->users($table);
            $table->timestamps();
            $table->index('branch_id');
        });

        Schema::create('fin_cash_box_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_box_id')->constrained('fin_cash_boxes')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->boolean('allow_negative')->default(false);
            $table->decimal('negative_limit', 20, 4)->nullable(); // null = unlimited when negative is allowed
            $table->timestamps();
            $table->unique(['cash_box_id', 'currency_id']);
        });

        Schema::create('fin_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('entry_date');
            $table->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 500);
            $table->string('status', 10)->default('posted'); // posted | reversed
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('fin_journal_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index('entry_date');
        });

        Schema::create('fin_assets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 200);
            $table->foreignId('category_id')->nullable()->constrained('fin_asset_categories')->restrictOnDelete();
            $table->date('purchase_date')->nullable();
            $table->decimal('quantity', 20, 4)->default(1);
            $table->decimal('unit_cost', 20, 4)->default(0);
            $table->decimal('total_cost', 20, 4)->default(0);
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->unsignedBigInteger('purchase_voucher_id')->nullable(); // FK added once fin_vouchers exists
            $table->foreignId('current_holder_employee_id')->nullable()->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $table->string('status', 20)->default('in_service'); // in_service | under_maintenance | sold | scrapped | lost
            $table->string('serial_no', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $this->users($table);
            $table->timestamps();
            $table->index(['status', 'branch_id']);
        });

        // The source of truth: one row per debit or credit, in the line's own currency, with its base equivalent.
        Schema::create('fin_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('fin_journal_entries')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('fin_accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->decimal('rate_to_base', 20, 8);
            $table->decimal('base_debit', 20, 4)->default(0);
            $table->decimal('base_credit', 20, 4)->default(0);
            $table->decimal('deal_rate', 20, 8)->nullable();
            $table->date('entry_date');
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('fin_parties')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('prj_projects')->restrictOnDelete();
            $table->foreignId('project_cost_category_id')->nullable()->constrained('fin_project_cost_categories')->restrictOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained('fin_expense_categories')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('fin_assets')->restrictOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable(); // HR id, no foreign key
            $table->foreignId('contractor_agreement_id')->nullable()->constrained('fin_contractor_agreements')->restrictOnDelete();
            $table->foreignId('custody_id')->nullable()->constrained('fin_custodies')->restrictOnDelete();
            $table->string('memo', 500)->nullable();
            $table->index(['account_id', 'currency_id', 'entry_date']);
            $table->index('party_id');
            $table->index('project_id');
            $table->index('asset_id');
            $table->index('employee_id');
            $table->index('custody_id');
            $table->index('contractor_agreement_id');
        });

        // Running balance per (account, currency, party): debit − credit. party_key 0 = no party.
        Schema::create('fin_account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('fin_accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->unsignedBigInteger('party_key')->default(0);
            $table->decimal('balance', 20, 4)->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['account_id', 'currency_id', 'party_key']);
        });
    }

    private function vouchers(): void
    {
        Schema::create('fin_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('purpose', 30)->nullable();
            $table->string('number', 30)->nullable()->unique();
            $table->date('voucher_date');
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $table->string('status', 12)->default('draft'); // draft | submitted | approved | rejected | reversed
            $table->foreignId('cash_box_id')->nullable()->constrained('fin_cash_boxes')->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('sys_currencies')->restrictOnDelete();
            $table->decimal('amount', 20, 4)->nullable();
            $table->foreignId('counter_cash_box_id')->nullable()->constrained('fin_cash_boxes')->restrictOnDelete();
            $table->foreignId('counter_currency_id')->nullable()->constrained('sys_currencies')->restrictOnDelete();
            $table->decimal('counter_amount', 20, 4)->nullable();
            $table->decimal('rate', 20, 8)->nullable(); // deal rate: counter_amount ÷ amount
            $table->decimal('fee_amount', 20, 4)->nullable();
            $table->foreignId('fee_currency_id')->nullable()->constrained('sys_currencies')->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('fin_parties')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('prj_projects')->restrictOnDelete();
            $table->foreignId('project_cost_category_id')->nullable()->constrained('fin_project_cost_categories')->restrictOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained('fin_expense_categories')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('fin_assets')->restrictOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable(); // HR id
            $table->foreignId('contractor_agreement_id')->nullable()->constrained('fin_contractor_agreements')->restrictOnDelete();
            $table->foreignId('custody_id')->nullable()->constrained('fin_custodies')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('fin_accounts')->restrictOnDelete();
            $table->string('classification', 20)->nullable(); // partner_personal | company_expense (D15)
            $table->string('debt_direction', 12)->nullable(); // we_owe | owed_to_us
            $table->decimal('advance_deduction', 20, 4)->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('reference_no', 60)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('fin_journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('fin_journal_entries')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $this->users($table);
            $table->timestamps();
            $table->index(['type', 'status']);
            $table->index('voucher_date');
            $table->index('cash_box_id');
            $table->index('party_id');
            $table->index('project_id');
            $table->index('custody_id');
        });

        Schema::table('fin_assets', function (Blueprint $table) {
            $table->foreign('purchase_voucher_id')->references('id')->on('fin_vouchers')->restrictOnDelete();
        });

        // Lines of multi-line vouchers: expense, custody settlement, manual journal, opening balance, allocation, payroll.
        Schema::create('fin_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('fin_vouchers')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->nullable()->constrained('fin_accounts')->restrictOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained('fin_expense_categories')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('prj_projects')->restrictOnDelete();
            $table->foreignId('project_cost_category_id')->nullable()->constrained('fin_project_cost_categories')->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('fin_parties')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('fin_assets')->restrictOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->string('memo', 500)->nullable();
            $table->timestamps();
            $table->index('voucher_id');
        });
    }

    private function operations(): void
    {
        Schema::create('fin_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_box_id')->constrained('fin_cash_boxes')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $table->date('count_date');
            $table->string('status', 12)->default('draft'); // draft | submitted | approved | rejected
            $table->foreignId('counted_by')->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('snapshot_entry_id')->nullable(); // last entry on the box at submit (R23)
            $table->foreignId('approved_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('fin_vouchers')->restrictOnDelete();
            $table->string('notes', 1000)->nullable();
            $this->users($table);
            $table->timestamps();
            $table->index(['cash_box_id', 'status']);
        });

        Schema::create('fin_cash_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_count_id')->constrained('fin_cash_counts')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->decimal('system_balance', 20, 4)->nullable();
            $table->decimal('counted_amount', 20, 4);
            $table->decimal('difference', 20, 4)->nullable();
            $table->string('explanation', 500)->nullable();
            $table->timestamps();
            $table->unique(['cash_count_id', 'currency_id']);
        });

        Schema::create('fin_attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('path', 300);
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('usr_users')->restrictOnDelete();
            $table->timestamps();
        });

        // Branch accountants' reach (OP-12): finance permissions held at "own" scope cover these branches.
        Schema::create('fin_user_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('usr_users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'branch_id']);
        });

        // Site expense requests drafted by supervisors (R24/R25); the invoice image is an attachment.
        Schema::create('fin_site_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('prj_projects')->restrictOnDelete();
            $table->foreignId('project_cost_category_id')->constrained('fin_project_cost_categories')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->foreignId('supplier_party_id')->nullable()->constrained('fin_parties')->restrictOnDelete();
            $table->string('description', 500);
            $table->string('payment_source', 20); // custody | own_money
            $table->foreignId('custody_id')->nullable()->constrained('fin_custodies')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('usr_users')->restrictOnDelete();
            $table->foreignId('holder_party_id')->nullable()->constrained('fin_parties')->restrictOnDelete();
            $table->string('status', 12)->default('submitted'); // submitted | approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('fin_vouchers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $this->users($table);
            $table->timestamps();
            $table->index(['status', 'project_id']);
        });

        Schema::create('fin_employee_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('fin_parties')->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('fin_vouchers')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->unsignedSmallInteger('installments_count');
            $table->char('start_month', 7);
            $table->string('status', 12)->default('active'); // active | settled | cancelled
            $table->string('notes', 1000)->nullable();
            $this->users($table);
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('fin_employee_advance_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advance_id')->constrained('fin_employee_advances')->cascadeOnDelete();
            $table->char('due_month', 7);
            $table->decimal('amount', 20, 4);
            $table->string('status', 12)->default('pending'); // pending | deducted | postponed
            $table->unsignedBigInteger('pay_run_id')->nullable(); // payroll run id (Payroll owns the table)
            $table->string('postponed_reason', 500)->nullable();
            $this->users($table);
            $table->timestamps();
            $table->index(['due_month', 'status']);
        });

        Schema::create('fin_asset_custody_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('fin_assets')->cascadeOnDelete();
            $table->foreignId('from_employee_id')->nullable()->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('hr_employees')->restrictOnDelete();
            $table->date('transferred_at');
            $table->string('notes', 1000)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('fin_asset_maintenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('fin_assets')->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('fin_vouchers')->restrictOnDelete();
            $table->string('description', 500);
            $table->date('date');
            $table->foreignId('project_id')->nullable()->constrained('prj_projects')->restrictOnDelete();
            $this->users($table);
            $table->timestamps();
        });

        Schema::create('fin_asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('fin_assets')->restrictOnDelete();
            $table->string('type', 10); // sale | scrap | loss
            $table->date('date');
            $table->decimal('sale_amount', 20, 4)->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('sys_currencies')->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('fin_vouchers')->restrictOnDelete();
            $table->string('notes', 1000)->nullable();
            $this->users($table);
            $table->timestamps();
        });

        Schema::create('fin_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // payroll | overhead
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('branch_id')->constrained('sys_branches')->restrictOnDelete();
            $table->string('method', 30); // manual_amounts | percentages | proportional_direct_cost | time_shares
            $table->string('status', 10)->default('draft'); // draft | approved | reversed
            $table->foreignId('voucher_id')->nullable()->constrained('fin_vouchers')->restrictOnDelete();
            $table->string('notes', 1000)->nullable();
            $this->users($table);
            $table->timestamps();
            $table->index(['kind', 'year', 'month', 'branch_id', 'status'], 'fin_allocations_period_status_index');
        });

        Schema::create('fin_allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->constrained('fin_allocations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('prj_projects')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('sys_currencies')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->restrictOnDelete();
            $table->decimal('percentage', 9, 4)->nullable();
            $table->decimal('amount', 20, 4);
            $table->timestamps();
        });

        Schema::create('fin_payroll_time_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('prj_projects')->restrictOnDelete();
            $table->decimal('percentage', 9, 4);
            $this->users($table);
            $table->timestamps();
            $table->unique(['year', 'month', 'employee_id', 'project_id'], 'fin_time_shares_month_employee_project_unique');
        });
    }
};
