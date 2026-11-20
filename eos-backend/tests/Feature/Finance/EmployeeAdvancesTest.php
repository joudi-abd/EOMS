<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceInstallment;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Services\Advances\EmployeeAdvanceService;
use App\Modules\Finance\Support\Money;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Settings\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\TestCase;

/** Employee advances (phase 6: D17, entry #14, R18) and the API payroll uses to deduct their installments. */
class EmployeeAdvancesTest extends TestCase
{
    use FinanceFixture, RefreshDatabase;

    private CashBox $safe;

    private Employee $employee;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->safe = $this->box('Main safe', ['SYP' => null, 'USD' => null, 'TRY' => null]);
        $this->fund($this->safe, 'SYP', 5000000);
        $this->fund($this->safe, 'USD', 5000);
        $this->fund($this->safe, 'TRY', 5000);
        [$this->employee, $this->party] = $this->employeeWithParty('E-1');
    }

    /** @return array{0: Employee, 1: Party|null} */
    private function employeeWithParty(string $number, bool $withParty = true): array
    {
        $employee = Employee::create(['employee_no' => $number, 'first_name' => 'Ahmad', 'father_name' => 'Omar', 'last_name' => $number,
            'national_id' => $number.'-NID', 'national_id_hash' => hash('sha256', $number), 'hire_date' => '2026-01-01']);
        if (! $withParty) {
            return [$employee, null];
        }
        $party = Party::create(['name' => 'Ahmad '.$number, 'employee_id' => $employee->id]);
        PartyRole::create(['party_id' => $party->id, 'role' => 'employee']);

        return [$employee, $party];
    }

    private function advance(array $extra = [], int $status = 201): ?array
    {
        return $this->voucher(['type' => 'advance_issue', 'employee_id' => $this->employee->id, 'cash_box_id' => $this->safe->id, 'currency' => 'SYP',
            'amount' => 1000000, 'meta' => ['installments_count' => 3, 'start_month' => '2026-12'], ...$extra], $status);
    }

    private function service(): EmployeeAdvanceService
    {
        return app(EmployeeAdvanceService::class);
    }

    public function test_advance_posts_to_employee_advances_and_splits_installments_with_the_remainder_on_the_last(): void
    {
        $voucher = $this->advance();

        $this->assertSame(['AI-2026-000001', $this->party->id], [$voucher['number'], $voucher['party_id']]);
        $debit = JournalLine::where('journal_entry_id', $voucher['journal_entry_id'])->where('account_id', $this->roleId('employee_advances'))->sole();
        $this->assertSame([$this->employee->id, $this->party->id, '1000000.0000'], [$debit->employee_id, $debit->party_id, $debit->debit]);
        $this->assertSame('4000000', $this->boxBalance($this->safe, 'SYP'));

        $advance = EmployeeAdvance::where('voucher_id', $voucher['id'])->sole();
        $this->assertSame(['active', 3, '2026-12'], [$advance->status, $advance->installments_count, $advance->start_month]);
        $schedule = $advance->installments()->orderBy('due_month')->get()->map(fn ($i) => [$i->due_month, Money::of((string) $i->amount)])->all();
        $this->assertSame([['2026-12', '333333'], ['2027-01', '333333'], ['2027-02', '333334']], $schedule);

        // The payment purpose posts the same way.
        $payment = $this->voucher(['type' => 'payment', 'purpose' => 'employee_advance', 'employee_id' => $this->employee->id, 'cash_box_id' => $this->safe->id,
            'currency' => 'USD', 'amount' => 100, 'meta' => ['installments_count' => 3, 'start_month' => '2026-11']]);
        $usd = EmployeeAdvance::where('voucher_id', $payment['id'])->sole();
        $this->assertSame(['33.33', '33.33', '33.34'], $usd->installments()->orderBy('id')->pluck('amount')->map(fn ($a) => Money::of((string) $a))->all());
        $this->assertSame(['SYP' => '1000000', 'USD' => '100'], $this->service()->outstanding($this->employee->id));
    }

    public function test_advance_needs_a_payroll_currency_a_linked_party_and_a_valid_schedule(): void
    {
        $this->advance(['currency' => 'TRY', 'amount' => 100], 422);
        [$unlinked] = $this->employeeWithParty('E-2', withParty: false);
        $response = $this->postJson('/api/v1/finance/vouchers', ['type' => 'advance_issue', 'employee_id' => $unlinked->id, 'cash_box_id' => $this->safe->id,
            'currency' => 'SYP', 'amount' => 1000, 'voucher_date' => '2026-11-10', 'meta' => ['installments_count' => 1, 'start_month' => '2026-12']]);
        $response->assertUnprocessable()->assertJsonValidationErrors('party_id');
        [, $other] = $this->employeeWithParty('E-3');
        $this->advance(['party_id' => $other->id], 422);
        $this->advance(['meta' => ['installments_count' => 0, 'start_month' => '2026-12']], 422);
        $this->advance(['meta' => ['installments_count' => 2, 'start_month' => '2026-10']], 422);
        $this->advance(['meta' => ['installments_count' => 2, 'start_month' => '2026-13']], 422);
        $this->advance(['employee_id' => 999999], 422);
        $this->assertSame(0, EmployeeAdvance::count());
    }

    public function test_installments_due_mark_deducted_and_release_run_for_payroll(): void
    {
        $advance = EmployeeAdvance::where('voucher_id', $this->advance()['id'])->sole();
        $service = $this->service();

        $this->assertCount(0, $service->installmentsDue($this->employee->id, '2026-11'));
        $due = $service->installmentsDue($this->employee->id, '2026-12');
        $this->assertSame([['2026-12', '333333', 'SYP']], $due->map(fn ($i) => [$i['due_month'], $i['amount'], $i['currency']])->all());
        $this->assertCount(3, $service->installmentsDue($this->employee->id, '2027-06'));

        $service->markDeducted([$due[0]['id']], 77);
        $this->assertSame(['SYP' => '666667'], $service->outstanding($this->employee->id));
        $this->assertSame('2027-01', $service->installmentsDue($this->employee->id, '2027-06')->first()['due_month']);
        $this->assertThrows(fn () => $service->markDeducted([$due[0]['id']], 78), ValidationException::class);

        $service->releaseRun(77);
        $this->assertSame(['SYP' => '1000000'], $service->outstanding($this->employee->id));
        $this->assertNull(EmployeeAdvanceInstallment::find($due[0]['id'])->pay_run_id);

        $all = $service->installmentsDue($this->employee->id, '2027-12')->pluck('id')->all();
        $service->markDeducted($all, 80);
        $this->assertSame(['settled', []], [$advance->refresh()->status, $service->outstanding($this->employee->id)]);
        $service->releaseRun(80);
        $this->assertSame('active', $advance->refresh()->status);
    }

    public function test_postponing_moves_the_installment_after_the_last_one_and_is_audited(): void
    {
        $advance = EmployeeAdvance::where('voucher_id', $this->advance()['id'])->sole();
        $first = $advance->installments()->orderBy('due_month')->first();

        $this->postJson("/api/v1/finance/employee-advance-installments/{$first->id}/postpone", [])->assertUnprocessable();
        $shown = $this->postJson("/api/v1/finance/employee-advance-installments/{$first->id}/postpone", ['reason' => 'الراتب لا يكفي هذا الشهر'])
            ->assertOk()->json('data');

        $this->assertSame(['postponed', 'الراتب لا يكفي هذا الشهر'], [$first->refresh()->status, $first->postponed_reason]);
        $moved = $advance->installments()->where('status', 'pending')->orderByDesc('due_month')->first();
        $this->assertSame(['2027-03', '333333'], [$moved->due_month, Money::of((string) $moved->amount)]);
        $this->assertSame(['1000000', '2027-01'], [$shown['remaining'], $shown['next_due_month']]);
        $this->assertTrue(AuditLog::where('action', 'finance.advance_installment_postponed')->where('auditable_id', $advance->id)->exists());
        $this->assertCount(0, $this->service()->installmentsDue($this->employee->id, '2026-12'));

        $this->postJson("/api/v1/finance/employee-advance-installments/{$first->id}/postpone", ['reason' => 'مرة أخرى'])->assertUnprocessable();
        $this->login($this->director)->postJson("/api/v1/finance/employee-advance-installments/{$moved->id}/postpone", ['reason' => 'test'])->assertForbidden();
    }

    public function test_reversal_cancels_the_advance_and_is_refused_after_a_deduction(): void
    {
        $first = $this->advance();
        $this->postJson("/api/v1/finance/vouchers/{$first['id']}/reverse", ['date' => '2026-11-12', 'reason' => 'خطأ في المبلغ'])->assertOk();
        $this->assertSame('cancelled', EmployeeAdvance::where('voucher_id', $first['id'])->value('status'));
        $this->assertSame([], $this->service()->outstanding($this->employee->id));
        $this->assertSame('5000000', $this->boxBalance($this->safe, 'SYP'));

        $second = $this->advance();
        $due = $this->service()->installmentsDue($this->employee->id, '2026-12');
        $this->service()->markDeducted([$due[0]['id']], 5);
        $this->postJson("/api/v1/finance/vouchers/{$second['id']}/reverse", ['date' => '2026-11-12', 'reason' => 'خطأ'])
            ->assertUnprocessable()->assertJsonValidationErrors('voucher');
        $this->assertSame('approved', $this->getJson("/api/v1/finance/vouchers/{$second['id']}")->json('data.status'));
        $this->assertSame('active', EmployeeAdvance::where('voucher_id', $second['id'])->value('status'));
        $this->assertSame('4000000', $this->boxBalance($this->safe, 'SYP'));
    }

    public function test_list_show_and_t15_report(): void
    {
        $voucher = $this->advance();
        [$other] = $this->employeeWithParty('E-4');
        $this->advance(['employee_id' => $other->id, 'amount' => 300000]);
        $advance = EmployeeAdvance::where('voucher_id', $voucher['id'])->sole();

        $list = $this->getJson('/api/v1/finance/employee-advances?employee_id='.$this->employee->id)->assertOk()->json('data');
        $this->assertSame([[$advance->id, '1000000', 'SYP']], array_map(fn ($a) => [$a['id'], $a['remaining'], $a['currency']['code']], $list));
        $this->assertCount(2, $this->getJson('/api/v1/finance/employee-advances?currency=SYP&status=active')->json('data'));

        $shown = $this->getJson('/api/v1/finance/employee-advances/'.$advance->id)->assertOk()->json('data');
        $this->assertSame(['1000000', '0', '2026-12', 3], [$shown['remaining'], $shown['deducted_total'], $shown['next_due_month'], count($shown['installments'])]);

        $report = $this->getJson('/api/v1/finance/employee-advances/report?months=3')->assertOk()->json('data');
        $mine = collect($report['balances'])->firstWhere('employee_id', $this->employee->id);
        $this->assertSame(['1000000', '2026-12', '333333', 'SYP'], [$mine['outstanding'], $mine['next_due_month'], $mine['next_amount'], $mine['currency']]);
        $this->assertSame([['2026-12', '433333', 2], ['2027-01', '433333', 2]],
            array_map(fn ($u) => [$u['due_month'], $u['amount'], $u['installments']], $report['upcoming']));

        // A branch accountant of another branch sees no advance of the head office.
        $branch = Branch::create(['name' => 'فرع إدلب', 'code' => 'IDL', 'is_head_office' => false, 'is_active' => true]);
        $accountant = $this->actor('branch_accountant');
        $this->putJson("/api/v1/finance/users/{$accountant->id}/branches", ['branch_ids' => [$branch->id]]);
        $this->login($accountant)->getJson('/api/v1/finance/employee-advances')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/finance/employee-advances/'.$advance->id)->assertForbidden();
    }
}
