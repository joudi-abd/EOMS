<?php

namespace Tests\Feature\Payroll;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\EmployeeAdvanceInstallment;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Payroll\Models\PayRun;
use App\Modules\Payroll\Models\PayRunLine;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Payroll\Concerns\PayrollFixture;
use Tests\TestCase;

/** Phase 7: salary components (D19, R21), advance installments (R18), the accrual (#15), payment (#16) and allocation (#17). */
class PayrollComponentsTest extends TestCase
{
    use PayrollFixture, RefreshDatabase;

    private function salaryComponent(string $code): SalaryComponent
    {
        return SalaryComponent::where('code', $code)->firstOrFail();
    }

    public function test_finance_settings_manage_components_and_system_components_keep_their_rules(): void
    {
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/payroll/components')->assertOk()->assertJsonFragment(['code' => 'advance_deduction', 'is_system' => true])
            ->assertJsonFragment(['code' => 'supervision_salary', 'is_project_linked' => true]);
        $this->postJson('/api/v1/payroll/components', ['code' => 'transport', 'name' => 'بدل نقل', 'kind' => 'earning'])->assertForbidden();

        Sanctum::actingAs($this->finance);
        $transport = $this->postJson('/api/v1/payroll/components', ['code' => 'transport', 'name' => 'بدل نقل', 'kind' => 'earning', 'is_recurring' => true])
            ->assertCreated()->assertJsonPath('data.is_system', false)->json('data');
        $this->postJson('/api/v1/payroll/components', ['code' => 'transport', 'name' => 'x', 'kind' => 'earning'])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/payroll/components', ['code' => 'pct', 'name' => 'نسبة', 'kind' => 'earning', 'calc_method' => 'percentage'])
            ->assertJsonValidationErrors('percentage_base');
        // A component never posts to another role's account (here the salaries payable).
        $this->patchJson('/api/v1/payroll/components/'.$transport['id'], ['expense_account_id' => $this->roleId('salaries_payable')])
            ->assertJsonValidationErrors('expense_account_id');
        $fuel = Account::where('code', '6104')->value('id');
        $this->patchJson('/api/v1/payroll/components/'.$transport['id'], ['expense_account_id' => $fuel, 'code' => 'other'])->assertJsonValidationErrors('code');
        $this->patchJson('/api/v1/payroll/components/'.$transport['id'], ['expense_account_id' => $fuel])->assertOk()->assertJsonPath('data.expense_account_id', $fuel);

        $salary = $this->salaryComponent('salary');
        $this->patchJson('/api/v1/payroll/components/'.$salary->id, ['kind' => 'deduction'])->assertJsonValidationErrors('kind');
        $this->patchJson('/api/v1/payroll/components/'.$salary->id, ['is_active' => false])->assertJsonValidationErrors('is_active');
        $this->patchJson('/api/v1/payroll/components/'.$salary->id, ['name' => 'الراتب'])->assertOk()->assertJsonPath('data.name', 'الراتب');
    }

    public function test_a_project_linked_component_is_never_saved_without_a_project(): void
    {
        $this->october();
        $run = $this->createRun();
        $project = $this->project();
        $supervision = $this->salaryComponent('supervision_salary');
        $item = ['employee_id' => $this->employee->id, 'component_id' => $supervision->id, 'amount' => 200000, 'reason' => 'Site supervision'];

        $this->postJson("/api/v1/payroll/runs/{$run['id']}/items", $item)->assertJsonValidationErrors('project_id');
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/items", [...$item, 'component_id' => $this->salaryComponent('tasks')->id, 'project_id' => $project->id])
            ->assertJsonValidationErrors('project_id');
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/items", [...$item, 'component_id' => $this->salaryComponent('salary')->id])
            ->assertJsonValidationErrors('component_id');
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/items", [...$item, 'project_id' => $project->id])->assertCreated()
            ->assertJsonPath('data.kind', 'supervision_salary')->assertJsonPath('data.project_id', $project->id)->assertJsonPath('data.status', 'proposed');

        $this->postJson('/api/v1/payroll/employee-components', ['employee_id' => $this->employee->id, 'component_id' => $supervision->id,
            'amount' => 50000, 'start_month' => '2026-10'])->assertJsonValidationErrors('project_id');
    }

    public function test_recurring_components_are_added_for_their_months_and_listed_in_the_export(): void
    {
        $this->october();
        Sanctum::actingAs($this->finance);
        $transport = $this->postJson('/api/v1/payroll/components', ['code' => 'transport', 'name' => 'بدل نقل', 'kind' => 'earning', 'is_recurring' => true])->json('data');
        $bonusPct = $this->postJson('/api/v1/payroll/components', ['code' => 'seniority', 'name' => 'علاوة أقدمية', 'kind' => 'earning', 'is_recurring' => true,
            'calc_method' => 'percentage', 'percentage_base' => 'basic_salary', 'percentage' => 10])->json('data');
        $project = $this->project();
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/employee-components', ['employee_id' => $this->employee->id, 'component_id' => $transport['id'],
            'amount' => 75000, 'start_month' => '2026-10'])->assertCreated();
        $this->postJson('/api/v1/payroll/employee-components', ['employee_id' => $this->employee->id, 'component_id' => $bonusPct['id'],
            'start_month' => '2026-09'])->assertCreated();
        $this->postJson('/api/v1/payroll/employee-components', ['employee_id' => $this->employee->id, 'component_id' => $this->salaryComponent('supervision_salary')->id,
            'amount' => 40000, 'project_id' => $project->id, 'start_month' => '2026-09', 'end_month' => '2026-09'])->assertCreated();

        $run = $this->createRun();
        $recurring = $this->items($run['id'])->where('origin', 'recurring')->keyBy('kind');
        $this->assertSame(['seniority', 'transport'], $recurring->keys()->sort()->values()->all(), 'The September-only supervision is not added');
        $this->assertSame('75000.00', $recurring['transport']->amount);
        // 10% of the basic pay earned: 2,600,000 salary − 100,000 unpaid leave.
        $this->assertSame('250000.00', $recurring['seniority']->amount);
        $this->assertSame('applied', $recurring['transport']->status);

        // Recalculation rebuilds them without duplicates.
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/recalculate")->assertOk();
        $this->assertCount(2, $this->items($run['id'])->where('origin', 'recurring'));

        Sanctum::actingAs($this->finance);
        $csv = $this->get("/api/v1/payroll/runs/{$run['id']}/export")->assertOk()->streamedContent();
        $header = str_getcsv(strtok(ltrim($csv, "\u{FEFF}"), "\n"));
        $this->assertContains('الراتب الأساسي', $header);
        $this->assertContains('بدل نقل', $header);
        $this->assertContains('علاوة أقدمية', $header);
        $this->assertContains('إجازة غير مدفوعة', $header);
        $this->assertNotContains('salary_or_base', $header);
        $this->assertNotContains('مكافأة', $header, 'Only components used in the run get a column');
        $this->assertLessThan(array_search('إجازة غير مدفوعة', $header, true), array_search('بدل نقل', $header, true), 'Earnings come before deductions');
    }

    public function test_advance_installment_is_deducted_and_posted_to_employee_advances(): void
    {
        $party = $this->employeeParty();
        $this->advance('SYP', 300000, 3);
        $this->october();
        $run = $this->createRun();
        $deduction = $this->items($run['id'])->firstWhere('kind', 'advance_deduction');
        $this->assertSame(['advance', 'deduction', 'applied', '100000.00'], [$deduction->origin, $deduction->category, $deduction->status, $deduction->amount]);
        $installment = EmployeeAdvanceInstallment::findOrFail($deduction->details['installment_id']);
        $this->assertSame('2026-10', $installment->due_month);

        $this->submitAndApprove($run['id']);

        $this->assertSame(['deducted', $run['id']], [$installment->refresh()->status, $installment->pay_run_id]);
        $accrual = Voucher::findOrFail(PayRun::findOrFail($run['id'])->accrual_voucher_id);
        $credit = JournalLine::where('journal_entry_id', $accrual->journal_entry_id)->where('account_id', $this->roleId('employee_advances'))->sole();
        $this->assertSame(['100000.0000', $this->employee->id, $party->id], [$credit->credit, $credit->employee_id, $credit->party_id]);
        $this->assertSame('200000', $this->roleBalance('employee_advances', 'SYP', $party->id), 'Two installments remain');
        // Net 2,442,857.15 − 100,000 installment.
        $this->assertSame('2342857.15', PayRunLine::firstOrFail()->net);
        $this->assertSame('-2342857', $this->roleBalance('salaries_payable', 'SYP'));
    }

    public function test_installment_in_another_currency_is_skipped_with_a_note(): void
    {
        $this->employeeParty();
        $this->advance('USD', 300, 3);
        $this->october();
        $run = $this->createRun();

        $this->assertNull($this->items($run['id'])->firstWhere('kind', 'advance_deduction'));
        $this->assertContains('advance_currency', array_column($run['lines'][0]['issues'], 'code'));
    }

    public function test_installments_over_the_net_salary_warn_and_finance_postpones_one(): void
    {
        $this->employeeParty();
        $this->advance('SYP', 3000000, 1);
        $this->october();
        $run = $this->createRun();
        $issue = collect($run['lines'][0]['issues'])->firstWhere('code', 'advance_exceeds_net');
        $this->assertFalse($issue['blocking']);
        $deduction = $this->items($run['id'])->firstWhere('kind', 'advance_deduction');

        $this->postJson("/api/v1/payroll/runs/{$run['id']}/submit")->assertOk();
        $this->postJson("/api/v1/payroll/items/{$deduction->id}/review", ['decision' => 'postpone', 'reason' => 'Too much'])->assertForbidden();
        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/review-remaining", ['decision' => 'approve'])->assertOk();
        $errors = $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertUnprocessable()->json('errors.run');
        $this->assertStringContainsString('R18', $errors[0]);
        $this->postJson("/api/v1/payroll/items/{$deduction->id}/review", ['decision' => 'postpone'])->assertJsonValidationErrors('reason');
        $line = $this->postJson("/api/v1/payroll/items/{$deduction->id}/review", ['decision' => 'postpone', 'reason' => 'Exceeds the salary'])
            ->assertOk()->json('data');

        $this->assertSame('2442857.15', $line['net']);
        $this->assertNotContains('advance_exceeds_net', array_column($line['issues'], 'code'));
        $installments = EmployeeAdvanceInstallment::orderBy('id')->get();
        $this->assertSame([['2026-10', 'postponed'], ['2026-11', 'pending']], $installments->map(fn ($i) => [$i->due_month, $i->status])->all());
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertOk();
        $this->assertSame('pending', $installments[1]->refresh()->status);
    }

    public function test_project_linked_item_posts_to_project_costs_and_payment_in_another_currency_clears_through_fx(): void
    {
        $this->october();
        $run = $this->createRun();
        $project = $this->project();
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/items", ['employee_id' => $this->employee->id,
            'component_id' => $this->salaryComponent('supervision_salary')->id, 'project_id' => $project->id, 'amount' => 200000, 'reason' => 'Supervision'])->assertCreated();
        $this->submitAndApprove($run['id']);

        // #15: Dr salaries_expense 2,657,143 + Dr project_costs 200,000 / Cr salaries_expense 214,286 + Cr salaries_payable 2,642,857.
        $accrual = Voucher::findOrFail(PayRun::findOrFail($run['id'])->accrual_voucher_id);
        $this->assertSame('2026-10-31', $accrual->voucher_date->toDateString());
        $lines = JournalLine::where('journal_entry_id', $accrual->journal_entry_id)->get();
        $loaded = ProjectCostCategory::where('system_key', 'loaded_payroll')->value('id');
        $cost = $lines->where('account_id', $this->roleId('project_costs'))->sole();
        $this->assertSame(['200000.0000', $project->id, $loaded, $this->employee->id], [$cost->debit, $cost->project_id, $cost->project_cost_category_id, $cost->employee_id]);
        $expense = $lines->where('account_id', $this->roleId('salaries_expense'));
        $this->assertSame([['2657143.0000', '0.0000'], ['0.0000', '214286.0000']], $expense->map(fn ($l) => [$l->debit, $l->credit])->values()->all());
        $this->assertSame('2642857.0000', $lines->where('account_id', $this->roleId('salaries_payable'))->sole()->credit);
        $this->assertTrue($lines->every(fn ($l) => $l->employee_id === $this->employee->id));

        // #16 in USD for an SYP salary: 2,642,857.15 ÷ 13,000 = 203.30 converted, 203 paid.
        Sanctum::actingAs($this->finance);
        app(ExchangeRateService::class)->setRates('2026-11-03', ['SYP' => 13000]);
        $usd = $this->box('USD', 1000, '2026-11-01');
        $line = PayRunLine::firstOrFail();
        $payment = $this->postJson("/api/v1/payroll/lines/{$line->id}/payment", ['currency' => 'USD', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $usd->id])
            ->assertCreated()->json('data');
        $entry = Voucher::findOrFail($payment['finance_voucher_id'])->journal_entry_id;
        $syp = Currency::where('code', 'SYP')->value('id');
        $usdId = Currency::where('code', 'USD')->value('id');
        $legs = JournalLine::where('journal_entry_id', $entry)->orderBy('line_no')->get()
            ->map(fn ($l) => [$l->account_id, $l->currency_id, $l->debit, $l->credit])->all();
        $this->assertSame([
            [$this->roleId('salaries_payable'), $syp, '2642857.0000', '0.0000'],
            [$this->roleId('fx_position'), $syp, '0.0000', '2642857.0000'],
            [$this->roleId('fx_position'), $usdId, '203.3000', '0.0000'],
            [$this->roleId('rounding_differences'), $usdId, '0.0000', '0.3000'],
            [$usd->account_id, $usdId, '0.0000', '203.0000'],
        ], $legs);
        $this->assertSame('0', $this->roleBalance('salaries_payable', 'SYP'));
    }

    public function test_sample_month_posts_and_its_payroll_allocation_reaches_project_costs(): void
    {
        $this->october();
        $run = $this->createRun();
        $this->submitAndApprove($run['id']);
        $projectA = $this->project('A');
        $projectB = $this->project('B');

        Sanctum::actingAs($this->finance);
        $this->putJson('/api/v1/finance/allocations/time-shares', ['year' => 2026, 'month' => 10, 'employees' => [
            ['employee_id' => $this->employee->id, 'shares' => [['project_id' => $projectA->id, 'percentage' => 60], ['project_id' => $projectB->id, 'percentage' => 30]]],
        ]])->assertOk()->assertJsonPath('data.employees.0.total', '90');
        $preview = $this->postJson('/api/v1/finance/allocations/preview', ['kind' => 'payroll', 'year' => 2026, 'month' => 10, 'method' => 'time_shares'])
            ->assertOk()->json('data');
        // General salary cost on the accrual: 2,657,143 − 214,286 = 2,442,857; 90% is allocated, the rest stays general.
        $this->assertSame('2442857', $preview['pool'][0]['amount']);
        $this->assertSame(['1465714', '732857'], array_column($preview['lines'], 'amount'));
        $this->assertSame('244286', $preview['unallocated'][0]['amount']);

        $this->postJson('/api/v1/finance/allocations', ['kind' => 'payroll', 'year' => 2026, 'month' => 10, 'method' => 'time_shares'])->assertCreated();
        $loaded = ProjectCostCategory::where('system_key', 'loaded_payroll')->value('id');
        $onProject = fn (int $id) => JournalLine::where('account_id', $this->roleId('project_costs'))->where('project_id', $id)
            ->where('project_cost_category_id', $loaded)->sum('debit');
        $this->assertEquals(1465714, $onProject($projectA->id));
        $this->assertEquals(732857, $onProject($projectB->id));
        $this->assertSame('-2198571', $this->roleBalance('payroll_allocation_contra', 'SYP'));
    }
}
