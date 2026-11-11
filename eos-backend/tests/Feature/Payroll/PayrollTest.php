<?php

namespace Tests\Feature\Payroll;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Box;
use App\Modules\Finance\Services\CashService;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use App\Modules\HR\Services\Attendance\AttendanceReviewService;
use App\Modules\HR\Services\EmployeeTerminationService;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\PayExport;
use App\Modules\Payroll\Models\PayPayment;
use App\Modules\Payroll\Models\PayRunItem;
use App\Modules\Payroll\Models\PayRunLine;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use App\Modules\Settings\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
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

    /** HR prepares the run (payroll.manage); finance reviews, approves and pays it. */
    private function createRun(string $month = '2026-10'): array
    {
        Sanctum::actingAs($this->hr);

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

        // HR enters the bonus without deciding it, cannot review deductions, and sends the run to finance.
        $bonus = $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/items', ['employee_id' => $this->employee->id,
            'category' => 'earning', 'amount' => 50000, 'reason' => 'Project bonus'])->assertCreated()->assertJsonPath('data.status', 'proposed')->json('data');
        $this->postJson('/api/v1/payroll/items/'.$items['late']->id.'/review', ['decision' => 'waive', 'reason' => 'x x x'])->assertForbidden();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertForbidden();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertSame(1, $this->finance->notifications()->count());
        $this->assertSame(1, $this->director->notifications()->count());

        // Finance decides: it cannot edit HR's draft, and reviews proposed and manual items.
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/items', ['employee_id' => $this->employee->id,
            'category' => 'earning', 'amount' => 1, 'reason' => 'nope'])->assertForbidden();
        $this->postJson('/api/v1/payroll/items/'.$bonus['id'].'/review', ['decision' => 'modify', 'amount' => 60000, 'reason' => 'More'])->assertUnprocessable();
        $this->postJson('/api/v1/payroll/items/'.$items['late']->id.'/review', ['decision' => 'waive', 'reason' => 'Traffic accident'])
            ->assertOk()->assertJsonPath('data.amount', '0.00');
        $this->postJson('/api/v1/payroll/items/'.$items['absence']->id.'/review', ['decision' => 'modify', 'amount' => 200000, 'reason' => 'Too high'])
            ->assertUnprocessable();
        // Nothing is accepted implicitly: the undecided bonus and absence count as zero and block approval.
        $this->assertSame('2557142.86', $line->refresh()->net);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertStatus(409);
        $this->getJson('/api/v1/payroll/runs/'.$run['id'])->assertJsonPath('data.undecided_items', 2);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/review-remaining', ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.decided', 2)->assertJsonPath('data.run.undecided_items', 0);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'approved');

        $line->refresh();
        // 2,600,000 + 57,142.86 + 50,000 − 100,000 unpaid − 100,000 absence (approved explicitly) − 0 late.
        $this->assertSame('2507142.86', $line->net);
        $this->assertSame('approved', $items['absence']->refresh()->status);
        $this->assertSame('approved', PayRunItem::find($bonus['id'])->status);
        $this->assertSame(1, $this->hr->notifications()->get()->where('data.kind', 'payroll.run_approved')->count());

        // HR cannot pay; finance can.
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => 1])->assertForbidden();

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
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/review-remaining', ['decision' => 'approve'])->assertOk();
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
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertUnprocessable()->assertJsonValidationErrors('run');
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/return', ['note' => 'Salary changed'])->assertOk()
            ->assertJsonPath('data.status', 'draft');

        // A reopened attendance day makes the month not ready.
        $day = AttendanceDay::where('work_date', '2026-10-14')->firstOrFail();
        app(AttendanceReviewService::class)->reopen($day, $day->version, 'Recheck the punches', $this->hr);
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/recalculate')->assertOk()->assertJsonPath('data.not_ready', 1);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->director);
        $errors = $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertUnprocessable()->json('errors.run');
        $this->assertStringContainsString('غير جاهز', $errors[0]);
    }

    public function test_approval_rejects_stale_roster_after_service_period_changes(): void
    {
        $this->october();
        $run = $this->createRun();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();

        $hire = Employee::create(['employee_no' => 'LA-2', 'first_name' => 'Sara', 'last_name' => 'Nader',
            'hire_date' => '2025-01-01', 'national_id' => 'LA-2', 'national_id_hash' => hash('sha256', 'LA-2')]);
        $hire->servicePeriods()->create(['start_date' => '2025-01-01']);
        EmploymentContract::create(['employee_id' => $hire->id, 'contract_number' => 'C-PAY-2', 'type' => 'indefinite',
            'status' => 'active', 'start_date' => '2025-01-01', 'base_salary' => 2_600_000, 'currency_code' => 'SYP', 'pay_type' => 'monthly']);

        Sanctum::actingAs($this->director);
        $errors = $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertUnprocessable()->json('errors.run');
        $this->assertTrue(collect($errors)->contains(fn ($message) => str_contains($message, 'غير موجود في الكشف')));

        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/return', ['note' => 'New hire missing'])->assertOk();
        $this->employee->servicePeriods()->first()->update(['end_date' => '2026-09-30']);
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/recalculate')->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();
        // After ending service before October, the original line must leave the run (no prior locked month).
        $this->assertSame(0, PayRunLine::query()->where('pay_run_id', $run['id'])->where('employee_id', $this->employee->id)->count());
    }

    public function test_approval_rejects_when_locked_month_adjustment_sources_change(): void
    {
        $this->october();
        $october = $this->createRun();
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/review-remaining', ['decision' => 'approve'])->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/approve')->assertOk();

        $day = AttendanceDay::where('work_date', '2026-10-06')->firstOrFail();
        $this->approvedLeave(['start_date' => '2026-10-06'], consent: true);
        $day->refresh();
        app(AttendanceReviewService::class)->reopen($day, $day->version, 'Leave approved retroactively', $this->hr);
        $day->refresh();
        app(AttendanceReviewService::class)->approve($day, $day->version, $this->hr);

        $this->travelTo(CarbonImmutable::parse('2026-12-02T10:00:00+03:00'));
        $this->punches('2026-11-30', [], '2026-11-30T16:00:00');
        app(AttendanceReviewService::class)->approveMany(collect([$this->employee]),
            CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), $this->hr);
        Sanctum::actingAs($this->hr);
        $november = $this->postJson('/api/v1/payroll/runs', ['month' => '2026-11'])->assertCreated()->json('data');
        $adjustments = PayRunItem::query()->where('origin', 'adjustment')->get();
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->finance);
        foreach ($adjustments as $adjustment) {
            $this->postJson('/api/v1/payroll/items/'.$adjustment->id.'/adjustment-decision', ['decision' => 'accept', 'reason' => 'Reviewed'])->assertOk();
        }

        // Another locked-day change after decisions: adjustment payloads are stale until recalculate.
        $late = AttendanceDay::where('work_date', '2026-10-05')->firstOrFail();
        $checker = $this->actor('hr_manager');
        $reviews = app(AttendanceReviewService::class);
        $late = $reviews->reopen($late, $late->version, 'Correct lateness', $this->hr);
        $correction = $reviews->request($late, ['version' => $late->version, 'type' => 'times', 'first_in' => '2026-10-05T09:00:00+03:00',
            'last_out' => '2026-10-05T16:00:00+03:00', 'reason' => 'Was on time'], $this->hr);
        $reviews->review($correction, ['decision' => 'approve', 'reason' => 'Checked'], $checker);
        $late->refresh();
        $reviews->approve($late, $late->version, $checker);

        $errors = $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/approve')->assertUnprocessable()->json('errors.run');
        $this->assertTrue(collect($errors)->contains(fn ($message) => str_contains($message, 'تسويات الأشهر المقفلة')));
    }

    public function test_change_to_a_locked_month_becomes_an_adjustment_in_the_next_run(): void
    {
        $this->october();
        $october = $this->createRun();
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/review-remaining', ['decision' => 'approve'])->assertOk();
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
        Sanctum::actingAs($this->hr);
        $november = $this->postJson('/api/v1/payroll/runs', ['month' => '2026-11'])->assertCreated()->json('data');
        $adjustments = PayRunItem::query()->where('origin', 'adjustment')->get()->keyBy(fn ($i) => $i->details['source_kind']);
        // The absence proposal (−100,000) disappears and unpaid leave (−100,000) appears: two offsetting adjustments.
        $this->assertSame(['absence', 'unpaid_leave'], $adjustments->keys()->sort()->values()->all());
        $this->assertSame('earning', $adjustments['absence']->category);
        $this->assertSame('deduction', $adjustments['unpaid_leave']->category);
        $this->assertSame('2026-10', $adjustments['absence']->details['source_month']);
        // HR sends the run as is; adjustments are finance's decision, and approval waits for them.
        $this->postJson('/api/v1/payroll/items/'.$adjustments['absence']->id.'/adjustment-decision', ['decision' => 'accept', 'reason' => 'Reviewed'])->assertForbidden();
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/approve')->assertStatus(409);
        foreach ($adjustments as $adjustment) {
            $this->postJson('/api/v1/payroll/items/'.$adjustment->id.'/adjustment-decision', ['decision' => 'accept', 'reason' => 'Reviewed'])->assertOk();
        }
        // Decisions survive a return to HR and a recalculation, so they need not be taken again.
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/return', ['note' => 'Check again'])->assertOk();
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/recalculate')->assertOk();
        $this->assertSame(['approved', 'approved'], PayRunItem::query()->where('origin', 'adjustment')->pluck('status')->all());
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/submit')->assertOk();

        // Once November is approved, the October difference counts as settled and is not proposed again.
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/review-remaining', ['decision' => 'approve'])->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/approve')->assertOk();
        $this->travelTo(CarbonImmutable::parse('2027-01-02T10:00:00+03:00'));
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-12'])->assertCreated();
        $this->assertSame(0, PayRunItem::query()->where('origin', 'adjustment')
            ->whereHas('line.run', fn ($q) => $q->where('month', '2026-12'))->count());
    }

    public function test_months_must_be_finished_and_unique_and_need_payroll_permissions(): void
    {
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-11'])->assertUnprocessable();
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])->assertCreated();
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])->assertUnprocessable();
        $this->getJson('/api/v1/payroll/runs')->assertOk();
        // Finance reviews and pays but does not prepare.
        Sanctum::actingAs($this->finance);
        $this->getJson('/api/v1/payroll/runs')->assertOk();
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-09'])->assertForbidden();
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
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/review-remaining', ['decision' => 'approve'])->assertOk();
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
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/review-remaining', ['decision' => 'approve'])->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertOk();
    }

    public function test_adjustments_follow_applied_amount_and_preserve_waivers(): void
    {
        $this->october();
        $october = $this->createRun();
        $absence = $this->items($october['id'])['absence'];
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/items/'.$absence->id.'/review', ['decision' => 'waive', 'reason' => 'First absence forgiven'])
            ->assertOk()->assertJsonPath('data.amount', '0.00');
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/review-remaining', ['decision' => 'approve'])->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/approve')->assertOk();
        $this->assertSame('0.00', $absence->refresh()->amount);

        $this->travelTo(CarbonImmutable::parse('2026-12-02T10:00:00+03:00'));
        $this->punches('2026-11-30', [], '2026-11-30T16:00:00');
        app(AttendanceReviewService::class)->approveMany(collect([$this->employee]),
            CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), $this->hr);
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-11'])->assertCreated();
        // Day still absent: waived deduction must not be re-imposed automatically.
        $this->assertSame(0, PayRunItem::query()->where('origin', 'adjustment')
            ->get()->filter(fn (PayRunItem $i) => ($i->details['source_kind'] ?? null) === 'absence')->count());

        $checker = $this->actor('hr_manager');
        $reviews = app(AttendanceReviewService::class);
        $day = AttendanceDay::where('work_date', '2026-10-06')->firstOrFail();
        $day = $reviews->reopen($day, $day->version, 'Device was offline', $this->hr);
        $correction = $reviews->request($day, ['version' => $day->version, 'type' => 'times', 'first_in' => '2026-10-06T09:00:00+03:00',
            'last_out' => '2026-10-06T16:00:00+03:00', 'reason' => 'Confirmed by site log'], $this->hr);
        $reviews->review($correction, ['decision' => 'approve', 'reason' => 'Checked'], $checker);
        $day->refresh();
        $reviews->approve($day, $day->version, $checker);

        $november = PayRunLine::query()->whereHas('run', fn ($q) => $q->where('month', '2026-11'))->firstOrFail()->run;
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/runs/'.$november->id.'/recalculate')->assertOk();
        // Nothing was deducted: correcting the day must not invent a 100,000 refund.
        $this->assertSame(0, PayRunItem::query()->where('origin', 'adjustment')
            ->get()->filter(fn (PayRunItem $i) => ($i->details['source_kind'] ?? null) === 'absence')->count());
    }

    public function test_modified_deduction_refunds_only_what_was_applied(): void
    {
        $this->october();
        $october = $this->createRun();
        $absence = $this->items($october['id'])['absence'];
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/submit')->assertOk();
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/items/'.$absence->id.'/review', [
            'decision' => 'modify', 'amount' => 40000, 'reason' => 'Partial penalty',
        ])->assertOk()->assertJsonPath('data.amount', '40000.00');
        Sanctum::actingAs($this->director);
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/review-remaining', ['decision' => 'approve'])->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$october['id'].'/approve')->assertOk();

        $checker = $this->actor('hr_manager');
        $reviews = app(AttendanceReviewService::class);
        $day = AttendanceDay::where('work_date', '2026-10-06')->firstOrFail();
        $day = $reviews->reopen($day, $day->version, 'Device was offline', $this->hr);
        $correction = $reviews->request($day, ['version' => $day->version, 'type' => 'times', 'first_in' => '2026-10-06T09:00:00+03:00',
            'last_out' => '2026-10-06T16:00:00+03:00', 'reason' => 'Confirmed by site log'], $this->hr);
        $reviews->review($correction, ['decision' => 'approve', 'reason' => 'Checked'], $checker);
        $day->refresh();
        $reviews->approve($day, $day->version, $checker);

        $this->travelTo(CarbonImmutable::parse('2026-12-02T10:00:00+03:00'));
        $this->punches('2026-11-30', [], '2026-11-30T16:00:00');
        $reviews->approveMany(collect([$this->employee]),
            CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), $this->hr);
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/runs', ['month' => '2026-11'])->assertCreated();
        $adjustment = PayRunItem::query()->where('origin', 'adjustment')
            ->get()->first(fn (PayRunItem $i) => ($i->details['source_kind'] ?? null) === 'absence');
        $this->assertNotNull($adjustment);
        $this->assertSame('earning', $adjustment->category);
        $this->assertSame('40000.00', $adjustment->proposed_amount);
        $this->assertSame(-40000.0, (float) $adjustment->details['locked']);
        $this->assertSame(0.0, (float) $adjustment->details['recomputed']);
    }

    public function test_an_employee_who_left_still_receives_adjustments_for_a_locked_month(): void
    {
        $this->approvedOctober();
        $this->contract->update(['status' => 'terminated', 'terminated_at' => '2026-10-31']);
        app(EmployeeTerminationService::class)->terminate($this->employee, ['termination_date' => '2026-10-31', 'reason' => 'Resigned'], $this->hr);

        $this->travelTo(CarbonImmutable::parse('2026-12-02T10:00:00+03:00'));
        Sanctum::actingAs($this->hr);
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

        Sanctum::actingAs($this->hr);
        $recalculated = $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/recalculate')->assertOk()->json('data');
        $this->assertSame(1, $recalculated['employees']);
        $line = $recalculated['lines'][0];
        $this->assertSame(['SYP', 'monthly'], [$line['currency_code'], $line['pay_type']]);
        $adjustment = PayRunItem::query()->where('origin', 'adjustment')->sole();
        $this->assertSame(['absence', 'earning', '100000.00'], [$adjustment->details['source_kind'], $adjustment->category, $adjustment->proposed_amount]);
    }

    public function test_submit_rejects_when_former_employee_gained_adjustment_missing_from_run(): void
    {
        $this->approvedOctober();
        $this->contract->update(['status' => 'terminated', 'terminated_at' => '2026-10-31']);
        app(EmployeeTerminationService::class)->terminate($this->employee, ['termination_date' => '2026-10-31', 'reason' => 'Resigned'], $this->hr);

        $this->travelTo(CarbonImmutable::parse('2026-12-02T10:00:00+03:00'));
        Sanctum::actingAs($this->hr);
        $november = $this->postJson('/api/v1/payroll/runs', ['month' => '2026-11'])->assertCreated()->json('data');
        $this->assertSame(0, $november['employees']);

        $checker = $this->actor('hr_manager');
        $reviews = app(AttendanceReviewService::class);
        $day = AttendanceDay::where('work_date', '2026-10-06')->firstOrFail();
        $day = $reviews->reopen($day, $day->version, 'Device was offline', $this->hr);
        $correction = $reviews->request($day, ['version' => $day->version, 'type' => 'times', 'first_in' => '2026-10-06T09:00:00+03:00',
            'last_out' => '2026-10-06T16:00:00+03:00', 'reason' => 'Confirmed by site log'], $this->hr);
        $reviews->review($correction, ['decision' => 'approve', 'reason' => 'Checked'], $checker);
        $reviews->approve($day->refresh(), $day->refresh()->version, $checker);

        // Draft was built before the adjustment existed; submit must refuse until recalculate.
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/payroll/runs/'.$november['id'].'/submit')->assertUnprocessable();
    }

    public function test_sham_cash_file_is_filled_from_the_template_and_confirmed_as_payments(): void
    {
        $line = $this->approvedOctober();
        $url = '/api/v1/payroll/runs/'.$line->pay_run_id.'/shamcash';
        $box = $this->box('SYP', 5_000_000);
        // HR prepares but does not pay.
        Sanctum::actingAs($this->hr);
        $this->postJson($url, ['currency' => 'SYP'])->assertForbidden();
        Sanctum::actingAs($this->finance);

        // Every employee in the file needs a 16-digit wallet (or is skipped on request).
        $this->employee->update(['sham_cash_wallet' => '0933 123']);
        $this->postJson($url, ['currency' => 'SYP'])->assertUnprocessable()->assertJsonValidationErrors('wallets');
        $this->postJson($url, ['currency' => 'SYP', 'skip_missing' => true])->assertUnprocessable();
        $this->employee->update(['sham_cash_wallet' => '0012 3456 7890 1234']);
        // Only lines in the file currency by default.
        $this->postJson($url, ['currency' => 'USD'])->assertUnprocessable();

        $response = $this->post($url, ['currency' => 'SYP', 'date' => '2026-11-03'])->assertOk();
        $docId = $response->headers->get('X-Doc-Id');
        $this->assertMatchesRegularExpression('/^\d{8}$/', $docId);
        $sheet = $this->xlsx($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('<c r="B2" s="7" t="inlineStr"><is><t>'.$this->employee->full_name.'</t></is></c>', $sheet['sheet']);
        $this->assertStringContainsString('<c r="C2" s="27" t="inlineStr"><is><t>0012345678901234</t></is></c>', $sheet['sheet'], 'wallet kept as text');
        $this->assertStringContainsString('<c r="D2" s="8"><v>2443000</v></c>', $sheet['sheet'], 'SYP rounded to the thousand');
        $this->assertStringContainsString('<c r="G2" s="11"><v>'.$docId.'</v></c>', $sheet['sheet']);
        $this->assertStringContainsString('<t>سوري</t>', $sheet['sheet']);
        $this->assertStringContainsString('<t>رواتب 2026-10</t>', $sheet['sheet']);
        $this->assertStringNotContainsString('sample', $sheet['sheet']);
        $this->assertFalse($sheet['calcChain']);
        $this->assertStringContainsString('fullCalcOnLoad="1"', $sheet['workbook']);

        // The same salary is never in two open files; a file that was not sent is cancelled and replaced.
        $this->postJson($url, ['currency' => 'SYP'])->assertUnprocessable();
        $export = PayExport::where('doc_id', $docId)->sole();
        $this->postJson('/api/v1/payroll/shamcash-exports/'.$export->id.'/cancel')->assertOk();
        $this->postJson('/api/v1/payroll/shamcash-exports/'.$export->id.'/confirm', ['box_id' => $box->id])->assertStatus(409);
        $second = $this->post($url, ['currency' => 'SYP', 'date' => '2026-11-03'])->assertOk()->headers->get('X-Doc-Id');
        $this->assertNotSame($docId, $second);
        $export = PayExport::where('doc_id', $second)->sole();
        // Re-download gives the same file.
        $this->get('/api/v1/payroll/shamcash-exports/'.$export->id.'/file')->assertOk()->assertHeader('X-Doc-Id', $second);

        // Confirmation records the payments from the box, referenced by the DocId.
        $this->postJson('/api/v1/payroll/shamcash-exports/'.$export->id.'/confirm', ['box_id' => $box->id])->assertOk();
        $payment = PayPayment::query()->sole();
        $this->assertSame(['sham_cash', $second, '2443000.00', '0012345678901234'], [$payment->method, $payment->reference, $payment->paid_amount, $payment->wallet]);
        $this->assertSame(2_557_000.0, app(CashService::class)->balance($box));
        $this->postJson('/api/v1/payroll/shamcash-exports/'.$export->id.'/confirm', ['box_id' => $box->id])->assertStatus(409);
        $this->postJson('/api/v1/payroll/shamcash-exports/'.$export->id.'/cancel')->assertStatus(409);
        $this->postJson($url, ['currency' => 'SYP'])->assertUnprocessable(); // nothing left to pay
        $this->getJson('/api/v1/payroll/runs/'.$line->pay_run_id.'/shamcash-exports')->assertOk()
            ->assertJsonPath('data.0.status', 'confirmed')->assertJsonPath('data.1.status', 'cancelled');
    }

    public function test_sham_cash_file_in_another_currency_and_rate_changes(): void
    {
        $line = $this->approvedOctober();
        $this->employee->update(['sham_cash_wallet' => '1234567890123456']);
        $url = '/api/v1/payroll/runs/'.$line->pay_run_id.'/shamcash';
        app(ExchangeRateService::class)->setRate('USD', 'SYP', 13000, '2026-11-03');
        // Chosen lines are converted at the date's rate, USD rounded to the dollar.
        $response = $this->post($url, ['currency' => 'USD', 'date' => '2026-11-03', 'line_ids' => [$line->id]])->assertOk();
        $sheet = $this->xlsx($response->baseResponse->getFile()->getPathname())['sheet'];
        $this->assertStringContainsString('<c r="D2" s="8"><v>188</v></c>', $sheet, '2,442,857.15 ÷ 13,000 = 187.91');
        $this->assertStringContainsString('<t>دولار</t>', $sheet);
        $this->postJson($url, ['currency' => 'USD', 'line_ids' => [999]])->assertUnprocessable()->assertJsonValidationErrors('line_ids');
        // If the rate is changed after the file was produced, confirmation refuses to pay a different sum.
        app(ExchangeRateService::class)->setRate('USD', 'SYP', 12000, '2026-11-03');
        $box = $this->box('USD', 1000);
        $export = PayExport::query()->sole();
        $this->postJson('/api/v1/payroll/shamcash-exports/'.$export->id.'/confirm', ['box_id' => $box->id])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertSame(0, PayPayment::count());
    }

    public function test_database_allows_one_active_payment_per_line(): void
    {
        $line = $this->approvedOctober();
        $box = $this->box('SYP', 5_000_000);
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $box->id])->assertCreated();
        $payment = PayPayment::query()->sole();
        // A path that skipped the application lock would still be stopped by the database.
        try {
            $payment->replicate(['active_line_id'])->save();
            $this->fail('A second active payment for the line was stored.');
        } catch (QueryException $e) {
            $this->assertSame(1, PayPayment::query()->whereNull('voided_at')->count());
        }
        // Once voided, the line can be paid again.
        $this->postJson('/api/v1/payroll/payments/'.$payment->id.'/void', ['date' => '2026-11-04', 'reason' => 'Wrong box'])->assertOk();
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', ['currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-04', 'box_id' => $box->id])->assertCreated();
        $this->assertSame([1, 1], [PayPayment::query()->whereNull('voided_at')->count(), PayPayment::query()->whereNotNull('voided_at')->count()]);
    }

    public function test_individual_payment_is_blocked_while_line_is_in_an_open_sham_cash_file(): void
    {
        $line = $this->approvedOctober();
        $this->employee->update(['sham_cash_wallet' => '0012345678901234']);
        $box = $this->box('SYP', 5_000_000);
        Sanctum::actingAs($this->finance);
        $docId = $this->post('/api/v1/payroll/runs/'.$line->pay_run_id.'/shamcash', ['currency' => 'SYP', 'date' => '2026-11-03'])
            ->assertOk()->headers->get('X-Doc-Id');
        $export = PayExport::where('doc_id', $docId)->sole();

        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', [
            'currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $box->id,
        ])->assertStatus(409);
        $this->assertSame(0, PayPayment::count());

        $this->postJson('/api/v1/payroll/shamcash-exports/'.$export->id.'/cancel')->assertOk();
        $this->postJson('/api/v1/payroll/lines/'.$line->id.'/payment', [
            'currency' => 'SYP', 'method' => 'cash', 'paid_at' => '2026-11-03', 'box_id' => $box->id,
        ])->assertCreated();
        $this->assertSame(1, PayPayment::count());
    }

    public function test_termination_is_blocked_while_employee_is_in_an_open_sham_cash_file(): void
    {
        $line = $this->approvedOctober();
        $this->employee->update(['sham_cash_wallet' => '0012345678901234']);
        Sanctum::actingAs($this->finance);
        $this->post('/api/v1/payroll/runs/'.$line->pay_run_id.'/shamcash', ['currency' => 'SYP', 'date' => '2026-11-03'])->assertOk();
        $this->contract->update(['status' => 'terminated', 'terminated_at' => '2026-10-31']);
        Sanctum::actingAs($this->hr);
        try {
            app(EmployeeTerminationService::class)->terminate(
                $this->employee, ['termination_date' => '2026-10-31', 'reason' => 'Resigned'], $this->hr
            );
            $this->fail('Expected termination to be blocked by the open Sham Cash file.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payroll', $e->errors());
        }
        $this->assertSame('active', $this->employee->fresh()->status);
    }

    public function test_sham_cash_confirm_records_the_wallet_frozen_in_the_file(): void
    {
        $line = $this->approvedOctober();
        $this->employee->update(['sham_cash_wallet' => '0012345678901234']);
        $box = $this->box('SYP', 5_000_000);
        Sanctum::actingAs($this->finance);
        $docId = $this->post('/api/v1/payroll/runs/'.$line->pay_run_id.'/shamcash', ['currency' => 'SYP', 'date' => '2026-11-03'])
            ->assertOk()->headers->get('X-Doc-Id');
        $export = PayExport::where('doc_id', $docId)->sole();
        $this->assertSame('0012345678901234', $export->rows[0]['wallet']);

        // Employee wallet changes after the file was produced and (presumably) executed outside.
        $this->employee->update(['sham_cash_wallet' => '9999888877776666']);
        $this->postJson('/api/v1/payroll/shamcash-exports/'.$export->id.'/confirm', ['box_id' => $box->id])->assertOk();
        $this->assertSame('0012345678901234', PayPayment::query()->sole()->wallet);
    }

    public function test_finance_decides_remaining_items_in_bulk_by_kind(): void
    {
        $this->october();
        $run = $this->createRun();
        $items = $this->items($run['id']);
        $bonus = $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/items', ['employee_id' => $this->employee->id,
            'category' => 'earning', 'amount' => 50000, 'reason' => 'Project bonus'])->assertCreated()->json('data');
        $this->assertSame('0.00', PayRunItem::find($bonus['id'])->amount, 'An HR item counts as zero until finance decides');
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/review-remaining', ['decision' => 'approve'])->assertForbidden();

        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/review-remaining', ['decision' => 'waive'])->assertUnprocessable();
        // Waive all lateness at once; the absence and the bonus still wait.
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/review-remaining', ['decision' => 'waive', 'kinds' => ['late'], 'reason' => 'Transport strike'])
            ->assertOk()->assertJsonPath('data.decided', 1);
        $this->assertSame(['waived', 'proposed', 'proposed'], [$items['late']->refresh()->status, $items['absence']->refresh()->status, PayRunItem::find($bonus['id'])->status]);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertStatus(409);
        $this->postJson('/api/v1/payroll/items/'.$bonus['id'].'/review', ['decision' => 'modify', 'amount' => 30000, 'reason' => 'Half the project'])->assertOk();
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/review-remaining', ['decision' => 'approve'])->assertOk()->assertJsonPath('data.decided', 1);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/approve')->assertOk();
        // 2,600,000 + 57,142.86 + 30,000 − 100,000 unpaid − 100,000 absence − 0 late.
        $this->assertSame('2487142.86', PayRunLine::query()->firstOrFail()->net);
    }

    public function test_runs_opened_before_explicit_decisions_stop_counting_undecided_items(): void
    {
        $this->october();
        $run = $this->createRun();
        $items = $this->items($run['id']);
        $line = PayRunLine::query()->firstOrFail();
        $net = (float) $line->net;
        // As an older version stored it: the undecided absence at its full amount, and in the line total.
        PayRunItem::query()->whereKey($items['absence']->id)->update(['amount' => $items['absence']->proposed_amount]);
        $line->update(['deductions' => (float) $line->deductions + 100000, 'net' => $net - 100000]);
        $this->postJson('/api/v1/payroll/runs/'.$run['id'].'/submit')->assertOk();

        // Deciding another item rebuilds the line without the undecided one.
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/v1/payroll/items/'.$items['late']->id.'/review', ['decision' => 'approve'])->assertOk();
        $this->assertEqualsWithDelta($net + 0 - (float) $items['late']->proposed_amount, (float) $line->refresh()->net, 0.001);

        // The data migration fixes open runs that nobody touches again.
        PayRunItem::query()->whereKey($items['absence']->id)->update(['amount' => $items['absence']->proposed_amount]);
        $line->update(['net' => 1]);
        (require base_path('app/Modules/Payroll/Database/Migrations/2026_11_15_000001_zero_undecided_items_in_open_runs.php'))->up();
        $this->assertSame('0.00', $items['absence']->refresh()->amount);
        $this->assertEqualsWithDelta($net - (float) $items['late']->proposed_amount, (float) $line->refresh()->net, 0.001);
    }

    /** @return array{sheet: string, workbook: string, calcChain: bool} */
    private function xlsx(string $path): array
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $result = ['sheet' => $zip->getFromName('xl/worksheets/sheet1.xml'), 'workbook' => $zip->getFromName('xl/workbook.xml'),
            'calcChain' => $zip->locateName('xl/calcChain.xml') !== false];
        $zip->close();

        return $result;
    }
}
