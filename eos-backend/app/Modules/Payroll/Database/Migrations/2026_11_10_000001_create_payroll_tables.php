<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One run per calendar month: draft (finance) → submitted → approved (company manager, locked).
        Schema::create('pay_runs', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique();
            $table->string('status', 12)->default('draft');
            $table->foreignId('prepared_by')->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('return_note', 1000)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        // One line per employee, in the employee's contract currency.
        Schema::create('pay_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_id')->constrained('pay_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('hr_employment_contracts')->restrictOnDelete();
            $table->string('pay_type', 10)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->decimal('rate', 15, 2)->nullable();
            $table->unsignedSmallInteger('working_days')->default(0);
            $table->boolean('attendance_ready')->default(false);
            $table->unsignedSmallInteger('blocked_days')->default(0);
            $table->json('issues');
            $table->decimal('earnings', 15, 2)->default(0);
            $table->decimal('deductions', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->char('source_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['pay_run_id', 'employee_id']);
        });

        // Amounts are positive; category gives the sign. origin: auto | proposed | manual | adjustment.
        Schema::create('pay_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_line_id')->constrained('pay_run_lines')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('category', 10);
            $table->string('origin', 12);
            $table->string('unit', 10)->nullable();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->decimal('proposed_amount', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->string('status', 12);
            $table->string('reason', 1000)->nullable();
            $table->json('details')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['pay_run_line_id', 'origin']);
        });

        // Exactly one payment per line, recorded after approval.
        Schema::create('pay_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_line_id')->unique()->constrained('pay_run_lines')->restrictOnDelete();
            $table->string('contract_currency', 3);
            $table->decimal('net_amount', 15, 2);
            $table->string('paid_currency', 3);
            $table->decimal('exchange_rate', 18, 6);
            $table->date('rate_date');
            $table->decimal('converted_amount', 18, 2);
            $table->decimal('paid_amount', 18, 2);
            $table->decimal('rounding_difference', 18, 2);
            $table->string('method', 12);
            $table->string('wallet', 30)->nullable();
            $table->string('reference', 100)->nullable();
            $table->date('paid_at');
            $table->foreignId('paid_by')->constrained('usr_users')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_payments');
        Schema::dropIfExists('pay_run_items');
        Schema::dropIfExists('pay_run_lines');
        Schema::dropIfExists('pay_runs');
    }
};
