<?php

namespace Tests\Feature\Payroll;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Box;
use App\Modules\Finance\Services\CashService;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\HR\Services\Attendance\AttendanceReviewService;
use App\Modules\HR\Services\EmployeeTerminationService;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\PayPayment;
use App\Modules\Payroll\Models\PayRunItem;
use App\Modules\Payroll\Models\PayRunLine;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use App\Modules\Settings\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\HR\Concerns\LeaveAttendanceFixture;
use Tests\TestCase;

/**
 * October 2026: 26 working days (Fridays off), 09:00–16:00. Scenario used by every pay type:
 *  5 Oct 10:00–16:00 (late, charged 60 min) · 6 Oct absent · 7 Oct 09:00–18:00 (120 min overtime)
 *  8 Oct full-day unpaid leave · 12 Oct official holiday · 13 Oct 08:00–16:00 (early arrival, unpaid) · other days 09:00–16:00.
 */
class PayrollTest extends TestCase
{
    use LeaveAttendanceFixture, RefreshDatabase {
        LeaveAttendanceFixture::setUp as setUpFixture;
    }

    private User $finance;

    private User $director;

    private EmploymentContract $contract;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->seed(CurrencySeeder::class);
        $this->finance = $this->actor('finance_manager');
        $this->director = $this->actor('company_manager');
        // All leave of this type is unpaid, and October is still inside the retroactive window.
        $this->policy->update(['annual_days' => 0, 'retroactive_days' => 60]);
        $this->contract = EmploymentContract::create(['employee_id' => $this->employee->id, 'contract_number' => 'C-PAY-1',
            'type' => 'indefinite', 'status' => 'active', 'start_date' => '2025-01-01',
            'base_salary' => 2_600_000, 'currency_code' => 'SYP', 'pay_type' => 'monthly']);
    }

    private function october(): void
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
        $result = app(AttendanceReviewService::class)->approveMany(collect([$this->employee]),
            CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), $this->hr);
        $this->assertSame([], $result['skipped']);
    }

    /** A finance box holding enough money, funded from a capital account. */
    private function box(string $currency, float $funds): Box
    {
        $box = app(CashService::class)->createBox(['name' => 'Main '.$currency, 'kind' => 'cash', 'currency_code' => $currency], $this->finance);
        $capital = Account::firstOrCreate(['code' => '3100'], ['name' => 'Capital', 'type' => 'equity']);
        app(CashService::class)->receipt(['box_id' => $box->id, 'amount' => $funds, 'date' => '2026-11-01',
            'description' => 'Opening cash', 'account_id' => $capital->id], $this->finance);

        return $box;
    }

    private function createRun(string $month = '2026-10'): array
    {
        Sanctum::actingAs($this->finance);

        return $this->postJson('/api/v1/payroll/runs', ['month' => $month])->assertCreated()->json('data');
    }

    private function items(int $runId): Collection
    {
        return PayRunItem::query()->whereIn('pay_run_line_id', PayRunLine::where('pay_run_id', $runId)->select('id'))->get()->keyBy('kind');
    }

    public function test_monthly_salary_prices_the_month_with_automatic_and_proposed_items(): void
    {
        $this->october();
        $run = $this->createRun();
        $items = $this->items($run['id']);

        $this->assertSame('2600000.00', $items['salary']->amount, '26 working days × 100,000, holiday included');
        $this->assertSame('57142.86', $items['overtime']->amount, '120 min × (100,000 ÷ 7 h) × 2');
        $this->assertSame('100000.00', $items['unpaid_leave']->amount);
        $this->assertSame(['proposed', '14285.71'], [$items['late']->status, $items['late']->proposed_amount]);
        $this->assertSame(['proposed', '100000.00'], [$items['absence']->status, $items['absence']->proposed_amount]);
        $this->assertArrayNotHasKey('early_exit', $items->all());
        $this->assertTrue($run['lines'][0]['attendance_ready']);
        $this->assertSame(26, $run['lines'][0]['working_days']);
    }

    public function test_daily_wage_pays_present_days_and_leave_but_not_holidays_or_absence(): void
    {
        $this->contract->update(['pay_type' => 'daily', 'base_salary' => 100_000]);
        $this->october();
        $items = $this->items($this->createRun()['id']);

        $this->assertSame('2400000.00', $items['days_worked']->amount, '23 present days + the leave day');
        $this->assertSame('100000.00', $items['unpaid_leave']->amount);
        $this->assertSame('57142.86', $items['overtime']->amount);
        $this->assertSame('14285.71', $items['late']->proposed_amount);
        $this->assertArrayNotHasKey('absence', $items->all());
        $this->assertArrayNotHasKey('salary', $items->all());
    }

    public function test_hourly_wage_pays_time_inside_the_schedule_holidays_and_double_overtime(): void
    {
        $this->contract->update(['pay_type' => 'hourly', 'base_salary' => 10_000]);
        $this->october();
        $items = $this->items($this->createRun()['id']);

        // 20 normal days × 420 + 5 Oct 360 + 7 Oct 420 (overtime separate) + 13 Oct 420 (arrival before 09:00 unpaid).
        $this->assertSame('9600.0000', $items['hours_worked']->quantity);
        $this->assertSame('1600000.00', $items['hours_worked']->amount);
        $this->assertSame('40000.00', $items['overtime']->amount, '120 min at twice 10,000/h');
        $this->assertSame('70000.00', $items['holiday_pay']->amount);
        $this->assertArrayNotHasKey('late', $items->all());
        $this->assertArrayNotHasKey('paid_leave', $items->all(), 'Unpaid leave pays nothing');
    }

    public function test_full_workflow_from_draft_to_payment(): void
    {
        $this->october();
        $run = $this->createRun();
        $line = PayRunLine::query()->firstOrFail();
        $items = $this->items($run['id']);

        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/items', ['employee_id' => $this->employee->id,
            'category' => 'earning', 'amount' => 50000, 'reason' => 'Project bonus'])->assertCreated();
        // Finance cannot review deductions; the company manager cannot edit the draft.
        $this->postJson('/api/v1/payroll/items/'.$items['late']->id.'/review', ['decision' => 'waive', 'reason' => 'x'])->assertForbidden();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertSame(1, $this->director->notifications()->count());

        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/items', ['employee_id' => $this->employee->id,
            'category' => 'earning', 'amount' => 1, 'reason' => 'nope'])->assertForbidden();
        $this->postJson('/api/v1/payroll/items/'.$items['late']->id.'/review', ['decision' => 'waive', 'reason' => 'Traffic accident'])
            ->assertOk()->assertJsonPath('data.amount', '0.00');
        $this->postJson('/api/v1/payroll/items/'.$items['absence']->id.'/review', ['decision' => 'modify', 'amount' => 200000, 'reason' => 'Too high'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'approved');

        $line->refresh();
        // 2,600,000 + 57,142.86 + 50,000 − 100,000 unpaid − 100,000 absence (accepted at approval) − 0 late.
        $this->assertSame('2507142.86', $line->net);
        $this->assertSame('approved', $items['absence']->refresh()->status);

        Sanctum::actingAs($this->finance);
        $usd = $this->box('USD', 1000);
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'USD', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $usd->id])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        app(ExchangeRateService::class)->setRate('USD', 'SYP', 13000, '2026-11-03');
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'USD', 'method' => 'sham_cash', 'paid_at' => '2026-11-03', 'box_id' => $usd->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
        $syp = $this->box('SYP', 10_000_000);
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'USD', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $syp->id])
            ->assertUnprocessable()->assertJsonValidationErrors('box_id');
        $payment = $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'USD', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $usd->id])
            ->assertCreated()->json('data');
        // The salary left the USD box through a posted journal entry against the salaries account.
        $this->assertSame(807.0, app(CashService::class)->balance($usd));
        $this->assertNotNull($payment['finance_transaction_id']);
        $this->assertSame('192.86', $payment['converted_amount']);
        $this->assertSame('193.00', $payment['paid_amount'], 'USD rounded to the dollar');
        $this->assertSame('0.14', $payment['rounding_difference']);
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $syp->id])
            ->assertStatus(409);

        $this->getJson('/api/v1/payroll/runs/'.$run['id'])->assertOk()->assertJsonPath('data.paid', 1);
        $csv = $this->get('/api/v1/payroll/runs/'.$run['id'].'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('2507142.86', $csv);
    }

    public function test_syp_payment_rounds_to_the_nearest_thousand(): void
    {
        $this->october();
        $run = $this->createRun();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertOk();
        $line = PayRunLine::query()->firstOrFail();
        $this->assertSame('2442857.15', $line->net); // 2,657,142.86 − 100,000 − 14,285.71 − 100,000

        Sanctum::actingAs($this->finance);
        $box = $this->box('SYP', 2_000_000);
        // Not enough money in the box: nothing is recorded.
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $box->id])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(0, PayPayment::count());
        app(CashService::class)->receipt(['box_id' => $box->id, 'amount' => 1_000_000, 'date' => '2026-11-02',
            'description' => 'Top up', 'account_id' => Account::where('code', '3100')->value('id')], $this->finance);
        $payment = $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $box->id])
            ->assertCreated()->json('data');
        $this->assertSame(557_000.0, app(CashService::class)->balance($box));
        $this->assertSame('2443000.00', $payment['paid_amount']);
        $this->assertSame('142.85', $payment['rounding_difference']);
    }

    public function test_approval_is_blocked_until_attendance_is_ready_and_data_unchanged(): void
    {
        $this->october();
        $run = $this->createRun();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();

        // A contract change after calculation must be recalculated before approval.
        $this->contract->update(['base_salary' => 2_700_000]);
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertUnprocessable()->assertJsonValidationErrors('run');
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/return', ['note' => 'Salary changed'])->assertOk()
            ->assertJsonPath('data.status', 'draft');

        // A reopened attendance day makes the month not ready.
        $day = AttendanceDay::where('work_date', '2026-10-14')->firstOrFail();
        app(AttendanceReviewService::class)->reopen($day, $day->version, 'Recheck the punches', $this->hr);
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/recalculate')->assertOk()->assertJsonPath('data.not_ready', 1);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->director);
        $errors = $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertUnprocessable()->json('errors.run');
        $this->assertStringContainsString('غير جاهز', $errors[0]);
    }

    public function test_change_to_a_locked_month_becomes_an_adjustment_in_the_next_run(): void
    {
        $this->october();
        $october = $this->createRun();
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/approve')->assertOk();

        // HR records the 6 Oct absence as unpaid leave afterwards: the locked month is re-priced in November's run.
        $day = AttendanceDay::where('work_date', '2026-10-06')->firstOrFail();
        $this->approvedLeave(['start_date' => '2026-10-06'], consent: true);
        $day->refresh();
        app(AttendanceReviewService::class)->reopen($day, $day->version, 'Leave approved retroactively', $this->hr);
        $day->refresh();
        app(AttendanceReviewService::class)->approve($day, $day->version, $this->hr);

        $this->travelTo(CarbonImmutable::parse('2026-12-02T10:00:00+03:00'));
        // November's own attendance (confirmed absent days) must be approved for its run to be approved later.
        $this->punches('2026-11-30', [], '2026-11-30T16:00:00');
        app(AttendanceReviewService::class)->approveMany(collect([$this->employee]),
            CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), $this->hr);
        Sanctum::actingAs($this->finance);
        $november = $this->postJson('/api/v1/payroll/runs', ['month' => '2026-11'])->assertCreated()->json('data');
        $adjustments = PayRunItem::query()->where('origin', 'adjustment')->get()->keyBy(fn ($i) => $i->details['source_kind']);
        // The absence proposal (−100,000) disappears and unpaid leave (−100,000) appears: two offsetting adjustments.
        $this->assertSame(['absence', 'unpaid_leave'], $adjustments->keys()->sort()->values()->all());
        $this->assertSame('earning', $adjustments['absence']->category);
        $this->assertSame('deduction', $adjustments['unpaid_leave']->category);
        $this->assertSame('2026-10', $adjustments['absence']->details['source_month']);
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/submit')->assertStatus(409);

        foreach ($adjustments as $adjustment) {
            $this->postJson('/api/v1/payroll/items/'.$adjustment->id.'/adjustment-decision', ['decision' => 'accept', 'reason' => 'Reviewed'])->assertOk();
        }
        // Decisions survive a draft recalculation, so the run can be submitted without deciding again.
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/recalculate')->assertOk();
        $this->assertSame(['approved', 'approved'], PayRunItem::query()->where('origin', 'adjustment')->pluck('status')->all());
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/submit')->assertOk();

        // Once November is approved, the October difference counts as settled and is not proposed again.
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/approve')->assertOk();
        $this->travelTo(CarbonImmutable::parse('2027-01-02T10:00:00+03:00'));
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-12'])->assertCreated();
        $this->assertSame(0, PayRunItem::query()->where('origin', 'adjustment')
            ->whereHas('line.run', fn ($q) => $q->where('month', '2026-12'))->count());
    }

    public function test_months_must_be_finished_and_unique_and_need_payroll_permissions(): void
    {
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-11'])->assertUnprocessable();
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])->assertCreated();
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])->assertUnprocessable();
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        Sanctum::actingAs($this->employeeUser);
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        Sanctum::actingAs($this->director);
        $this->getJson('/api/v1/payroll/runs')->assertOk();
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-09'])->assertForbidden();
    }

    public function test_missing_contract_is_a_blocking_issue(): void
    {
        $this->contract->delete();
        $run = $this->createRun();
        $this->assertSame('no_contract', $run['lines'][0]['issues'][0]['code']);
        $this->assertSame(1, $run['blocking_issues']);
    }

    public function test_contract_pay_type_is_validated_and_defaults_to_monthly(): void
    {
        $this->contract->delete();
        Sanctum::actingAs($this->hr);
        $url = '/api/v1/employees/'.$this->employee->id.'/contracts';
        $base = ['type' => 'indefinite', 'start_date' => '2026-12-01', 'base_salary' => 100, 'currency_code' => 'USD'];
        $this->postJson($url, [...$base, 'contract_number' => 'C-W', 'pay_type' => 'weekly'])->assertUnprocessable()->assertJsonValidationErrors('pay_type');
        $this->postJson($url, [...$base, 'contract_number' => 'C-E', 'currency_code' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors('currency_code');
        $this->postJson($url, [...$base, 'contract_number' => 'C-D', 'pay_type' => 'daily'])->assertCreated();
        $this->postJson($url, [...$base, 'contract_number' => 'C-M', 'start_date' => '2027-01-01'])->assertSuccessful();
        $this->assertSame(['C-D' => 'daily', 'C-M' => 'monthly'], EmploymentContract::query()->orderBy('contract_number')
            ->pluck('pay_type', 'contract_number')->all());
    }

    private function approvedOctober(): PayRunLine
    {
        $this->october();
        $run = $this->createRun();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertOk();
        Sanctum::actingAs($this->finance);

        return PayRunLine::query()->firstOrFail();
    }

    public function test_a_wrong_payment_is_voided_back_into_its_box_and_paid_again(): void
    {
        $line = $this->approvedOctober();
        $box = $this->box('SYP', 5_000_000);
        $first = $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $box->id])
            ->assertCreated()->json('data');
        // Finance cannot undo a salary payment from its side.
        $this->postJson('/api/v1/finance/transactions/'.$first['finance_transaction_id'].'/reverse', ['date' => '2026-11-04', 'reason' => 'x x'])->assertStatus(409);

        $this->postJson('/api/v1/payroll/payments/'.$first['id'].'/void', ['date' => '2026-11-04', 'reason' => 'Wrong box'])->assertOk();
        $this->postJson('/api/v1/payroll/payments/'.$first['id'].'/void', ['date' => '2026-11-04', 'reason' => 'Again'])->assertStatus(409);
        $this->assertSame(5_000_000.0, app(CashService::class)->balance($box));
        $this->getJson('/api/v1/payroll/runs/'.$line->pay_run_id)->assertOk()->assertJsonPath('data.paid', 0);
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-04', 'box_id' => $box->id])
            ->assertCreated();
        $this->getJson('/api/v1/payroll/runs/'.$line->pay_run_id)->assertOk()->assertJsonPath('data.paid', 1);
    }

    public function test_whoever_prepares_a_run_cannot_approve_it(): void
    {
        $admin = $this->actor('system_admin');
        $this->october();
        Sanctum::actingAs($admin);
        $run = $this->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])->assertCreated()->json('data');
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertForbidden();
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertOk();
    }

    public function test_an_employee_who_left_still_receives_adjustments_for_a_locked_month(): void
    {
        $this->approvedOctober();
        $this->contract->update(['status' => 'terminated', 'terminated_at' => '2026-10-31']);
        app(EmployeeTerminationService::class)->terminate($this->employee, ['termination_date' => '2026-10-31', 'reason' => 'Resigned'], $this->hr);

        $this->travelTo(CarbonImmutable::parse('2026-12-02T10:00:00+03:00'));
        Sanctum::actingAs($this->finance);
        $november = $this->postJson('/api/v1/payroll/runs', ['month' => '2026-11'])->assertCreated()->json('data');
        $this->assertSame(0, $november['employees'], 'Nothing changed: no line for someone who left');

        // HR later finds the 6 Oct "absence" was a device problem: the day is corrected to a full day of work.
        $checker = $this->actor('hr_manager');
        $reviews = app(AttendanceReviewService::class);
        $day = AttendanceDay::where('work_date', '2026-10-06')->firstOrFail();
        $day = $reviews->reopen($day, $day->version, 'Device was offline', $this->hr);
        $correction = $reviews->request($day, ['version' => $day->version, 'type' => 'times', 'first_in' => '2026-10-06T09:00:00+03:00',
            'last_out' => '2026-10-06T16:00:00+03:00', 'reason' => 'Confirmed by site log'], $this->hr);
        $reviews->review($correction, ['decision' => 'approve', 'reason' => 'Checked'], $checker);
        $day->refresh();
        $reviews->approve($day, $day->version, $checker);

        Sanctum::actingAs($this->finance);
        $recalculated = $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/recalculate')->assertOk()->json('data');
        $this->assertSame(1, $recalculated['employees']);
        $line = $recalculated['lines'][0];
        $this->assertSame(['SYP', 'monthly'], [$line['currency_code'], $line['pay_type']]);
        $adjustment = PayRunItem::query()->where('origin', 'adjustment')->sole();
        $this->assertSame(['absence', 'earning', '100000.00'], [$adjustment->details['source_kind'], $adjustment->category, $adjustment->proposed_amount]);
    }
}
