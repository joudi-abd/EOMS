<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salary components (D19, spec §5.9): every pay run item belongs to a component. The calculator's own kinds are
 * system components (code = item kind); finance adds its own (e.g. راتب نسبة، راتب إشراف) and HR assigns recurring
 * ones to employees. Project-linked items carry their project (R21) and post to project costs.
 */
return new class extends Migration
{
    /** code => [name, kind] — the calculator's built-in kinds. */
    private const SYSTEM = [
        'salary' => ['الراتب الأساسي', 'earning'],
        'days_worked' => ['أيام العمل', 'earning'],
        'hours_worked' => ['ساعات العمل', 'earning'],
        'holiday_pay' => ['أجر العطل الرسمية', 'earning'],
        'paid_leave' => ['إجازة مدفوعة', 'earning'],
        'overtime' => ['ساعات إضافية', 'earning'],
        'bonus' => ['مكافأة', 'earning'],
        'adjustment' => ['تسوية شهر مقفل', 'earning'],
        'unpaid_leave' => ['إجازة غير مدفوعة', 'deduction'],
        'partial_pay_leave' => ['إجازة بأجر جزئي', 'deduction'],
        'late' => ['تأخير', 'deduction'],
        'early_exit' => ['خروج مبكر', 'deduction'],
        'absence' => ['غياب', 'deduction'],
        'advance_deduction' => ['خصم سلفة', 'deduction'],
        'manual_deduction' => ['خصم', 'deduction'],
    ];

    /** code => [name, calc_method, percentage_base, is_project_linked, is_recurring] — the spec's examples (§5.9). */
    private const EXAMPLES = [
        'percentage_salary' => ['راتب نسبة', 'percentage', 'project_fee', true, false],
        'study_salary' => ['راتب دراسة', 'fixed', null, true, true],
        'supervision_salary' => ['راتب إشراف', 'fixed', null, true, true],
        'tasks' => ['مهام', 'fixed', null, false, false],
        'incentives' => ['حوافز', 'fixed', null, false, false],
        'rewards' => ['مكافآت', 'fixed', null, false, false],
    ];

    public function up(): void
    {
        Schema::create('pay_salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('kind', 10); // earning | deduction
            $table->string('calc_method', 12)->default('fixed'); // fixed | percentage
            $table->string('percentage_base', 15)->nullable(); // basic_salary | project_fee
            $table->decimal('percentage', 9, 4)->nullable();
            $table->boolean('is_project_linked')->default(false);
            $table->boolean('is_recurring')->default(false);
            $table->foreignId('expense_account_id')->nullable()->constrained('fin_accounts')->restrictOnDelete(); // null = salaries_expense
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamps();
        });

        // A recurring component given to one employee: a fixed amount (contract currency) or a percentage of basic pay.
        Schema::create('pay_employee_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('component_id')->constrained('pay_salary_components')->restrictOnDelete();
            $table->decimal('amount', 20, 4)->nullable();
            $table->decimal('percentage', 9, 4)->nullable();
            $table->foreignId('project_id')->nullable()->constrained('prj_projects')->restrictOnDelete();
            $table->char('start_month', 7);
            $table->char('end_month', 7)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('usr_users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'start_month']);
        });

        Schema::table('pay_run_items', function (Blueprint $table) {
            $table->foreignId('component_id')->nullable()->after('kind')->constrained('pay_salary_components')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->after('component_id')->constrained('prj_projects')->restrictOnDelete();
        });

        $now = now();
        $sort = 0;
        $rows = [];
        foreach (self::SYSTEM as $code => [$name, $kind]) {
            $rows[] = ['code' => $code, 'name' => $name, 'kind' => $kind, 'calc_method' => 'fixed', 'percentage_base' => null, 'percentage' => null,
                'is_project_linked' => false, 'is_recurring' => false, 'is_system' => true, 'is_active' => true, 'sort' => ++$sort,
                'created_at' => $now, 'updated_at' => $now];
        }
        foreach (self::EXAMPLES as $code => [$name, $method, $base, $linked, $recurring]) {
            $rows[] = ['code' => $code, 'name' => $name, 'kind' => 'earning', 'calc_method' => $method, 'percentage_base' => $base, 'percentage' => null,
                'is_project_linked' => $linked, 'is_recurring' => $recurring, 'is_system' => false, 'is_active' => true, 'sort' => ++$sort,
                'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('pay_salary_components')->insert($rows);

        // Existing items: their kind is a system component's code.
        DB::table('pay_run_items')->whereNull('component_id')->update([
            'component_id' => DB::raw('(SELECT c.id FROM pay_salary_components c WHERE c.code = pay_run_items.kind)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('pay_run_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('component_id');
        });
        Schema::dropIfExists('pay_employee_components');
        Schema::dropIfExists('pay_salary_components');
    }
};
