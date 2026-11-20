<?php

namespace Tests\Feature\Payroll\Concerns;

use App\Modules\Finance\Database\Seeders\FinanceChartSeeder;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Services\Core\LedgerService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Finance\Services\Treasury\CashBoxService;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\HR\Services\Attendance\AttendanceReviewService;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\PayRunItem;
use App\Modules\Payroll\Models\PayRunLine;
use App\Modules\Projects\Models\Project;
use App\Modules\Settings\Database\Seeders\BranchSeeder;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\HR\Concerns\LeaveAttendanceFixture;

/**
 * The October 2026 payroll world of PayrollTest (26 working days, SYP 2,600,000 monthly contract; 5 Oct late 60 min,
 * 6 Oct absent, 7 Oct 120 min overtime, 8 Oct unpaid leave, 12 Oct holiday) with the finance starter chart, an SYP rate
 * from 1 Oct, HR preparing runs and a second finance account reviewing and approving them.
 * Approving every proposed item gives a net of 2,442,857.15 SYP (2,657,142.86 − 100,000 − 14,285.71 − 100,000).
 */
trait PayrollFixture
{
    use LeaveAttendanceFixture {
        LeaveAttendanceFixture::setUp as setUpLeaveAttendance;
    }

    protected User $finance;

    protected User $reviewer;

    protected EmploymentContract $contract;

    protected function setUp(): void
    {
        $this->setUpLeaveAttendance();
        $this->seed([CurrencySeeder::class, BranchSeeder::class, FinanceChartSeeder::class]);
        app(ExchangeRateService::class)->setRates('2026-10-01', ['SYP' => 13000]);
        $this->finance = $this->actor('finance_manager');
        $this->reviewer = $this->actor('finance_manager');
        $this->policy->update(['annual_days' => 0, 'retroactive_days' => 60]);
        $this->contract = EmploymentContract::create(['employee_id' => $this->employee->id, 'contract_number' => 'C-PAY-1',
            'type' => 'indefinite', 'status' => 'active', 'start_date' => '2025-01-01',
            'base_salary' => 2_600_000, 'currency_code' => 'SYP', 'pay_type' => 'monthly']);
    }

    protected function october(): void
    {
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-10-12', 'name' => 'Office holiday'])->assertCreated();
        $this->approvedLeave(['start_date' => '2026-10-08'], consent: true);
        $special = ['2026-10-05' => ['10:00', '16:00'], '2026-10-06' => [], '2026-10-07' => ['09:00', '18:00'],
            '2026-10-08' => [], '2026-10-12' => [], '2026-10-13' => ['08:00', '16:00']];
        for ($day = CarbonImmutable::parse('2026-10-01'); $day->month === 10; $day = $day->addDay()) {
            if ($day->dayOfWeek === 5) {
                continue;
            }
            $this->punches($day->toDateString(), $special[$day->toDateString()] ?? ['09:00', '16:00']);
        }
        $this->punches('2026-10-31', [], '2026-10-31T16:00:00');
        app(AttendanceReviewService::class)->approveMany(collect([$this->employee]),
            CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), $this->hr);
    }

    /** HR creates the October run. */
    protected function createRun(): array
    {
        Sanctum::actingAs($this->hr);

        return $this->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])->assertCreated()->json('data');
    }

    /** HR submits; the reviewer approves every remaining item and the run. */
    protected function submitAndApprove(int $runId): void
    {
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/payroll/runs/{$runId}/submit")->assertOk();
        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/v1/payroll/runs/{$runId}/review-remaining", ['decision' => 'approve'])->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$runId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    protected function items(int $runId): Collection
    {
        return PayRunItem::query()->whereIn('pay_run_line_id', PayRunLine::where('pay_run_id', $runId)->select('id'))->orderBy('id')->get();
    }

    /** A finance box in one currency funded with a posted receipt from the opening balances account. */
    protected function box(string $currency, float $funds, string $date = '2026-10-01'): CashBox
    {
        $box = app(CashBoxService::class)->create(['name' => 'Main '.$currency, 'type' => 'cash_safe', 'branch_id' => Branch::value('id'),
            'currencies' => [['currency' => $currency]]], $this->finance);
        app(VoucherService::class)->create(['type' => 'receipt', 'purpose' => 'other', 'voucher_date' => $date, 'cash_box_id' => $box->id,
            'currency' => $currency, 'amount' => $funds, 'account_id' => app(SystemAccounts::class)->id('opening_balance_equity'),
            'description' => 'Opening cash', 'approve' => true], $this->finance);

        return $box;
    }

    /** The employee's finance party (advances need one). */
    protected function employeeParty(): Party
    {
        $party = Party::create(['name' => 'Employee LA-1', 'employee_id' => $this->employee->id]);
        PartyRole::create(['party_id' => $party->id, 'role' => 'employee']);

        return $party;
    }

    /** A posted employee advance paid on 1 Oct from a box in its currency, repaid from `$startMonth`. */
    protected function advance(string $currency, float $amount, int $installments, string $startMonth = '2026-10'): void
    {
        $box = $this->box($currency, $amount);
        app(VoucherService::class)->create(['type' => 'advance_issue', 'voucher_date' => '2026-10-01', 'employee_id' => $this->employee->id,
            'cash_box_id' => $box->id, 'currency' => $currency, 'amount' => $amount, 'approve' => true,
            'meta' => ['installments_count' => $installments, 'start_month' => $startMonth]], $this->finance);
    }

    protected function project(string $name = 'Project A'): Project
    {
        return Project::create(['name' => $name, 'status' => 'active', 'branch_id' => Branch::value('id')]);
    }

    protected function roleId(string $role): int
    {
        return app(SystemAccounts::class)->id($role);
    }

    protected function roleBalance(string $role, string $currency, ?int $partyId = null): string
    {
        return app(LedgerService::class)->balance($this->roleId($role), Currency::where('code', $currency)->value('id'), $partyId);
    }
}
