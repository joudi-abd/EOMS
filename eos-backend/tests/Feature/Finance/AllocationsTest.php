<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PayrollTimeShare;
use App\Modules\Finance\Services\PayrollFinanceService;
use App\Modules\HR\Models\Employee;
use App\Modules\Settings\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\Feature\Finance\Concerns\ProjectFixture;
use Tests\TestCase;

/** Phases 7–8: payroll time shares, payroll allocation (#17) and overhead allocation (#18), D13 / §6.3. */
class AllocationsTest extends TestCase
{
    use FinanceFixture, ProjectFixture, RefreshDatabase;

    private Employee $employee;

    private ?Party $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->setUpProjects();
        $this->employee = Employee::create(['employee_no' => 'E-1', 'first_name' => 'Ahmad', 'father_name' => 'Omar', 'last_name' => 'Ali',
            'national_id' => 'E-1-NID', 'national_id_hash' => hash('sha256', 'E-1'), 'hire_date' => '2026-01-01']);
    }

    /** A project in the branch (head office by default) with a client; returns its id. */
    private function prj(string $name, ?int $branchId = null): int
    {
        $this->client ??= $this->party('عميل', ['client']);

        return $this->project(['name' => $name, 'client_party_id' => $this->client->id, 'branch_id' => $branchId ?? $this->hq->id])['project_id'];
    }

    private function overheadExpense(string|int $amount, string $date = '2026-11-10'): void
    {
        $category = ExpenseCategory::where('name', 'كهرباء وإنترنت')->value('id');
        $this->login($this->finance)->voucher(['type' => 'expense', 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'voucher_date' => $date,
            'lines' => [['expense_category_id' => $category, 'debit' => $amount]]]);
    }

    private function overhead(array $payload, int $status = 201): ?array
    {
        $response = $this->login($this->finance)->postJson('/api/v1/finance/allocations', $payload + ['kind' => 'overhead', 'year' => 2026, 'month' => 11,
            'branch_id' => $this->hq->id])->assertStatus($status);

        return $status < 300 ? $response->json('data') : null;
    }

    private function shares(int $month, array $employees, int $status = 200): void
    {
        $this->login($this->finance)->putJson('/api/v1/finance/allocations/time-shares', ['year' => 2026, 'month' => $month, 'employees' => $employees])
            ->assertStatus($status);
    }

    public function test_time_shares_stay_within_100_percent_and_copy_from_the_previous_month(): void
    {
        $a = $this->prj('A');
        $b = $this->prj('B');
        $this->login($this->director)->getJson('/api/v1/finance/allocations/time-shares?year=2026&month=10')->assertForbidden();

        $this->shares(10, [['employee_id' => $this->employee->id, 'shares' => [['project_id' => $a, 'percentage' => 70], ['project_id' => $b, 'percentage' => 40]]]], 422);
        $this->shares(10, [['employee_id' => $this->employee->id, 'shares' => [['project_id' => $a, 'percentage' => 50], ['project_id' => $a, 'percentage' => 10]]]], 422);
        $this->shares(10, [['employee_id' => $this->employee->id, 'shares' => [['project_id' => $a, 'percentage' => 70], ['project_id' => $b, 'percentage' => 30]]]]);
        $this->getJson('/api/v1/finance/allocations/time-shares?year=2026&month=10')->assertOk()
            ->assertJsonPath('data.employees.0.total', '100')->assertJsonPath('data.employees.0.shares.1.percentage', '30');

        $this->postJson('/api/v1/finance/allocations/time-shares/copy-previous', ['year' => 2026, 'month' => 9])->assertUnprocessable();
        $this->postJson('/api/v1/finance/allocations/time-shares/copy-previous', ['year' => 2026, 'month' => 11])->assertOk()
            ->assertJsonPath('data.copied', 1)->assertJsonPath('data.employees.0.total', '100');
        // Employees who already have shares in the month keep them.
        $this->shares(11, [['employee_id' => $this->employee->id, 'shares' => [['project_id' => $b, 'percentage' => 100]]]]);
        $this->postJson('/api/v1/finance/allocations/time-shares/copy-previous', ['year' => 2026, 'month' => 11])->assertOk()->assertJsonPath('data.copied', 0);
        $this->assertSame([$b], PayrollTimeShare::where('month', 11)->pluck('project_id')->all());
    }

    public function test_payroll_allocation_spreads_the_general_salary_cost_by_time_shares(): void
    {
        $a = $this->prj('A');
        $b = $this->prj('B');
        // October accrual: 1,000 general + 300 on project A (already a project cost) − 100 general deduction.
        app(PayrollFinanceService::class)->accrue('2026-10', [['employee_id' => $this->employee->id, 'currency' => 'USD', 'items' => [
            ['category' => 'earning', 'amount' => '1000'],
            ['category' => 'earning', 'amount' => '300', 'project_id' => $a],
            ['category' => 'deduction', 'amount' => '100'],
        ]]], $this->finance);
        $this->shares(10, [['employee_id' => $this->employee->id, 'shares' => [['project_id' => $a, 'percentage' => 33.3333], ['project_id' => $b, 'percentage' => 66.6667]]]]);

        $payload = ['kind' => 'payroll', 'year' => 2026, 'month' => 10, 'method' => 'time_shares'];
        $preview = $this->postJson('/api/v1/finance/allocations/preview', $payload)->assertOk()->json('data');
        $this->assertSame([['currency' => 'USD', 'amount' => '900']], array_map(fn ($p) => ['currency' => $p['currency'], 'amount' => $p['amount']], $preview['pool']));
        $this->assertSame(['300', '600'], array_column($preview['lines'], 'amount'));
        $this->assertSame('0', $preview['unallocated'][0]['amount']);

        $allocation = $this->postJson('/api/v1/finance/allocations', $payload)->assertCreated()->assertJsonPath('data.status', 'approved')->json('data');
        $this->assertSame($this->employee->id, $allocation['lines'][0]['employee_id']);
        $this->assertSame('-900', $this->roleBalance('payroll_allocation_contra', 'USD'));
        // Shares are frozen while the allocation stands.
        $this->shares(10, [['employee_id' => $this->employee->id, 'shares' => []]], 409);
    }

    public function test_proportional_overhead_sums_exactly_to_the_pool_with_the_remainder_on_the_last_project(): void
    {
        $projects = array_map(fn ($name) => $this->prj($name), ['A', 'B', 'C']);
        foreach ($projects as $project) {
            $this->cost($project, 'materials', 300);
        }
        $this->loadedCost($projects[0], 500); // not billable: no weight
        $this->overheadExpense(60);
        $this->overheadExpense(40);

        $preview = $this->login($this->finance)->postJson('/api/v1/finance/allocations/preview', ['kind' => 'overhead', 'year' => 2026, 'month' => 11,
            'branch_id' => $this->hq->id, 'method' => 'proportional_direct_cost'])->assertOk()->json('data');
        $this->assertSame('100', $preview['pool'][0]['amount']);
        $this->assertSame(['33.33', '33.33', '33.34'], array_column($preview['lines'], 'amount'));

        $allocation = $this->overhead(['method' => 'proportional_direct_cost']);
        $this->assertSame(['USD' => '100'], $allocation['totals']);
        $loaded = $this->categoryId('loaded_overhead');
        $last = JournalLine::where('account_id', $this->roleId('project_costs'))->where('project_id', $projects[2])->where('project_cost_category_id', $loaded)->sole();
        $this->assertSame('33.3400', $last->debit);
        $this->assertSame('-100', $this->roleBalance('overhead_allocation_contra', 'USD'));
    }

    public function test_a_second_allocation_for_the_period_waits_for_the_first_to_be_reversed(): void
    {
        $a = $this->prj('A');
        $this->overheadExpense(100);
        $first = $this->overhead(['method' => 'percentages', 'lines' => [['project_id' => $a, 'percentage' => 40]]]);
        $this->assertSame(['USD' => '40'], $first['totals']);
        $this->overhead(['method' => 'percentages', 'lines' => [['project_id' => $a, 'percentage' => 50]]], 409);
        $this->postJson('/api/v1/finance/vouchers', ['type' => 'allocation', 'voucher_date' => '2026-11-30', 'approve' => true])->assertUnprocessable();

        $this->postJson("/api/v1/finance/vouchers/{$first['voucher_id']}/reverse", ['date' => '2026-11-30', 'reason' => 'Wrong share'])->assertOk();
        $this->assertSame('reversed', Allocation::findOrFail($first['id'])->status);
        $this->overhead(['method' => 'percentages', 'lines' => [['project_id' => $a, 'percentage' => 50]]]);
        $this->assertSame('-50', $this->roleBalance('overhead_allocation_contra', 'USD'));
    }

    public function test_overhead_inputs_are_checked_against_the_pool_and_the_branch(): void
    {
        $a = $this->prj('A');
        $other = Branch::create(['code' => 'B2', 'name' => 'فرع حلب', 'is_active' => true]);
        $elsewhere = $this->prj('Elsewhere', $other->id);
        $this->overheadExpense(100);

        $this->overhead(['method' => 'percentages', 'lines' => [['project_id' => $a, 'percentage' => 80], ['project_id' => $elsewhere, 'percentage' => 30]]], 422);
        $this->overhead(['method' => 'percentages', 'lines' => [['project_id' => $elsewhere, 'percentage' => 10]]], 422);
        $this->overhead(['method' => 'manual_amounts', 'lines' => [['project_id' => $a, 'currency' => 'USD', 'amount' => 100.01]]], 422);
        $this->overhead(['method' => 'manual_amounts', 'lines' => [['project_id' => $a, 'currency' => 'TRY', 'amount' => 10]]], 422);
        $this->overhead(['method' => 'time_shares'], 422);
        $allocation = $this->overhead(['method' => 'manual_amounts', 'lines' => [['project_id' => $a, 'currency' => 'USD', 'amount' => 75.5]]]);
        $this->assertSame(['USD' => '75.5'], $allocation['totals']);
    }

    public function test_period_close_warns_until_the_months_allocations_are_done(): void
    {
        $a = $this->prj('A');
        $this->overheadExpense(100);
        $this->travelTo('2026-12-02 10:00:00');
        $warnings = $this->login($this->finance)->getJson('/api/v1/finance/periods/2026-11/checks')->assertOk()->json('data.warnings');
        $this->assertContains('لم يُنفَّذ توزيع المصاريف العامة على المشاريع لهذا الشهر.', $warnings);

        $this->overhead(['method' => 'percentages', 'lines' => [['project_id' => $a, 'percentage' => 100]]]);
        $warnings = $this->getJson('/api/v1/finance/periods/2026-11/checks')->json('data.warnings');
        $this->assertNotContains('لم يُنفَّذ توزيع المصاريف العامة على المشاريع لهذا الشهر.', $warnings);
        $this->assertContains('لم يُنفَّذ توزيع الرواتب على المشاريع لهذا الشهر.', $warnings);
    }
}
