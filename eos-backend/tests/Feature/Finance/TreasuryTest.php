<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Support\Money;
use App\Modules\Projects\Models\Project;
use App\Modules\Settings\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\TestCase;

/** Treasury (phase 2): multi-currency boxes, receipts, payments, transfers, exchanges, negative floors, branch reach, statements. */
class TreasuryTest extends TestCase
{
    use FinanceFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
    }

    private function payment(CashBox $box, string $currency, string|int|float $amount, array $extra = [], int $status = 201): ?array
    {
        return $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $box->id, 'currency' => $currency, 'amount' => $amount,
            'account_id' => $this->accountId('6104'), 'description' => 'محروقات', ...$extra], $status);
    }

    public function test_boxes_get_an_account_under_the_boxes_parent_and_list_balances_per_currency(): void
    {
        $box = $this->box('موني آوت', ['USD' => 1000, 'TRY' => null]);
        $this->assertSame(['1100-'.$box->id, true, true], [$box->account->code, $box->account->is_system, $box->account->is_postable]);
        $this->fund($box, 'TRY', 4340);
        $this->payment($box, 'USD', 850);

        $row = collect($this->getJson('/api/v1/finance/cash-boxes')->assertOk()->json('data'))->firstWhere('id', $box->id);
        $currencies = collect($row['currencies'])->keyBy('code');
        $this->assertSame(['-850', true, true, '150'], [$currencies['USD']['balance'], $currencies['USD']['negative'], $currencies['USD']['near_limit'], $currencies['USD']['available']]);
        $this->assertSame(['4340', false, false], [$currencies['TRY']['balance'], $currencies['TRY']['negative'], $currencies['TRY']['near_limit']]);

        // A currency with a balance stays on the box; the box is deactivated, not deleted.
        $this->patchJson('/api/v1/finance/cash-boxes/'.$box->id, ['currencies' => [['currency' => 'USD', 'allow_negative' => true, 'negative_limit' => 1000]]])
            ->assertUnprocessable();
        $this->postJson('/api/v1/finance/cash-boxes/'.$box->id.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->payment($box, 'TRY', 1, [], 422);
        $this->login($this->director)->postJson('/api/v1/finance/cash-boxes', ['name' => 'x', 'type' => 'bank', 'branch_id' => $this->hq->id,
            'currencies' => [['currency' => 'USD']]])->assertForbidden();
    }

    public function test_exchange_posts_four_lines_and_fx_position_nets_to_zero_at_the_bulletin_rate(): void
    {
        $usd = $this->box('Dollar safe', ['USD' => null]);
        $try = $this->box('Lira office', ['TRY' => null]);
        $this->fund($usd, 'USD', 500);

        $this->assertSame('43.40000000', $this->getJson('/api/v1/finance/vouchers/suggest-rate?from=USD&to=TRY&date=2026-11-10')->json('data.rate'));
        $voucher = $this->voucher(['type' => 'exchange', 'cash_box_id' => $usd->id, 'currency' => 'USD', 'amount' => 100,
            'counter_cash_box_id' => $try->id, 'counter_currency' => 'TRY', 'counter_amount' => 4340]);
        $this->assertSame(['FX-2026-000001', '43.40000000', []], [$voucher['number'], $voucher['rate'], $voucher['warnings']]);
        $lines = JournalLine::where('journal_entry_id', $voucher['journal_entry_id'])->get();
        $this->assertCount(4, $lines);
        $this->assertSame(['43.40000000'], $lines->pluck('deal_rate')->unique()->values()->all());
        $fx = $lines->where('account_id', $this->roleId('fx_position'));
        $this->assertSame('0', Money::sub(Money::sum($fx->pluck('base_debit')), Money::sum($fx->pluck('base_credit'))));
        $this->assertSame(['400', '4340'], [$this->boxBalance($usd, 'USD'), $this->boxBalance($try, 'TRY')]);

        // R10: a rate far from the bulletin warns but posts; R9: two currencies and positive amounts.
        $off = $this->voucher(['type' => 'exchange', 'cash_box_id' => $usd->id, 'currency' => 'USD', 'amount' => 100,
            'counter_cash_box_id' => $try->id, 'counter_currency' => 'TRY', 'rate' => 50]);
        $this->assertSame(['5000', 'approved'], [$off['counter_amount'] === null ? null : Money::of($off['counter_amount']), $off['status']]);
        $this->assertCount(1, $off['warnings']);
        $this->voucher(['type' => 'exchange', 'cash_box_id' => $usd->id, 'currency' => 'USD', 'amount' => 10,
            'counter_cash_box_id' => $usd->id, 'counter_currency' => 'USD', 'counter_amount' => 10], 422);
        $this->voucher(['type' => 'exchange', 'cash_box_id' => $usd->id, 'currency' => 'USD', 'amount' => 10,
            'counter_cash_box_id' => $try->id, 'counter_currency' => 'TRY', 'counter_amount' => 0], 422);
        // The target box must hold the currency (R7).
        $this->voucher(['type' => 'exchange', 'cash_box_id' => $usd->id, 'currency' => 'USD', 'amount' => 10,
            'counter_cash_box_id' => $usd->id, 'counter_currency' => 'TRY', 'counter_amount' => 434], 422);
    }

    public function test_negative_balance_limit_per_box_and_currency(): void
    {
        $exchangeOffice = $this->box('Exchange office', ['USD' => 30000]);
        $this->payment($exchangeOffice, 'USD', 25050);
        $this->assertSame('-25050', $this->boxBalance($exchangeOffice, 'USD'));
        $message = $this->postJson('/api/v1/finance/vouchers', ['type' => 'payment', 'purpose' => 'other', 'voucher_date' => '2026-11-10', 'approve' => true,
            'cash_box_id' => $exchangeOffice->id, 'currency' => 'USD', 'amount' => 4951, 'account_id' => $this->accountId('6104')])
            ->assertUnprocessable()->json('errors.amount.0');
        $this->assertSame('رصيد الصندوق Exchange office بعملة USD لا يكفي: الرصيد الحالي -25,050.00، المتاح 4,950.00.', $message);
        $this->payment($exchangeOffice, 'USD', 4950);

        $safe = $this->box('Safe', ['USD' => null]);
        $this->fund($safe, 'USD', 100);
        $this->payment($safe, 'USD', 100.01, [], 422);
        $this->payment($safe, 'USD', 100);

        $unlimited = $this->box('Bank', ['USD' => 'unlimited']);
        $this->payment($unlimited, 'USD', 1_000_000);
        $this->assertSame('-1000000', $this->boxBalance($unlimited, 'USD'));
    }

    public function test_a_second_approval_sees_the_first_and_a_back_dated_payment_cannot_overdraw_later_days(): void
    {
        $box = $this->box('Safe', ['USD' => null]);
        $this->fund($box, 'USD', 1000, '2026-11-01');
        // Two drafts each taking 60% of the balance: approvals queue on the box lock, so only one posts (§14.4).
        $first = $this->payment($box, 'USD', 600, ['approve' => false]);
        $second = $this->payment($box, 'USD', 600, ['approve' => false]);
        $this->postJson('/api/v1/finance/vouchers/'.$first['id'].'/approve')->assertOk();
        $this->postJson('/api/v1/finance/vouchers/'.$second['id'].'/approve')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(['approved', 'draft'], [Voucher::find($first['id'])->status, Voucher::find($second['id'])->status]);
        $this->assertSame('400', $this->boxBalance($box, 'USD'));

        // 400 is left today, but on 5 Nov it was 1000 with a 600 payment on the 10th: a 500 payment dated the 5th would
        // leave the statement at −100 on the 10th (OP-13).
        $this->payment($box, 'USD', 500, ['voucher_date' => '2026-11-05'], 422);
        $this->payment($box, 'USD', 400, ['voucher_date' => '2026-11-05']);
    }

    public function test_transfer_between_boxes_with_a_fee(): void
    {
        $a = $this->box('A', ['USD' => null, 'TRY' => null]);
        $b = $this->box('B', ['USD' => null]);
        $this->fund($a, 'USD', 1000);
        $this->fund($a, 'TRY', 100);

        $this->voucher(['type' => 'transfer', 'cash_box_id' => $a->id, 'counter_cash_box_id' => $a->id, 'currency' => 'USD', 'amount' => 10], 422);
        $this->voucher(['type' => 'transfer', 'cash_box_id' => $a->id, 'counter_cash_box_id' => $b->id, 'currency' => 'TRY', 'amount' => 10], 422);
        $voucher = $this->voucher(['type' => 'transfer', 'cash_box_id' => $a->id, 'counter_cash_box_id' => $b->id, 'currency' => 'USD', 'amount' => 300,
            'fee_amount' => 5, 'fee_currency' => 'TRY']);
        $this->assertSame('TR-2026-000001', $voucher['number']);
        $this->assertSame(['700', '300', '95'], [$this->boxBalance($a, 'USD'), $this->boxBalance($b, 'USD'), $this->boxBalance($a, 'TRY')]);
        $this->assertSame('5', $this->roleBalance('transfer_fees', 'TRY'));
    }

    public function test_receipts_and_payments_by_purpose(): void
    {
        $box = $this->box('Safe', ['USD' => null, 'SYP' => null]);
        $this->fund($box, 'USD', 1000);
        $lender = Party::create(['name' => 'كيف كافيه']);
        $supplier = Party::create(['name' => 'مورد مواد']);
        PartyRole::create(['party_id' => $supplier->id, 'role' => 'supplier']);

        // purpose "other": never a box or a system role account (except opening balances); R7: currency enabled on the box.
        $this->voucher(['type' => 'receipt', 'purpose' => 'other', 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 5, 'account_id' => $box->account_id], 422);
        $this->voucher(['type' => 'receipt', 'purpose' => 'other', 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 5, 'account_id' => $this->roleId('fx_position')], 422);
        $this->voucher(['type' => 'receipt', 'purpose' => 'other', 'cash_box_id' => $box->id, 'currency' => 'TRY', 'amount' => 5, 'account_id' => $this->accountId('3100')], 422);
        $this->voucher(['type' => 'receipt', 'purpose' => 'client_payment', 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 5], 422); // phase 5

        // General debts (#26, #27).
        $this->voucher(['type' => 'receipt', 'purpose' => 'debt', 'debt_direction' => 'we_owe', 'party_id' => $lender->id, 'cash_box_id' => $box->id,
            'currency' => 'USD', 'amount' => 200]);
        $this->assertSame('-200', $this->roleBalance('other_payables', 'USD', $lender->id));
        $this->voucher(['type' => 'payment', 'purpose' => 'debt', 'debt_direction' => 'we_owe', 'party_id' => $lender->id, 'cash_box_id' => $box->id,
            'currency' => 'USD', 'amount' => 200]);
        $this->assertSame('0', $this->roleBalance('other_payables', 'USD', $lender->id));
        $this->voucher(['type' => 'payment', 'purpose' => 'debt', 'debt_direction' => 'owed_to_us', 'party_id' => $lender->id, 'cash_box_id' => $box->id,
            'currency' => 'USD', 'amount' => 50]);
        $this->assertSame('50', $this->roleBalance('other_receivables', 'USD', $lender->id));
        $this->voucher(['type' => 'payment', 'purpose' => 'debt', 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 50, 'party_id' => $lender->id], 422);

        // Supplier payments: the party must be a supplier; without a project it settles the payable, with one it is a project cost.
        $this->voucher(['type' => 'payment', 'purpose' => 'supplier_payment', 'party_id' => $lender->id, 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 10], 422);
        $this->voucher(['type' => 'payment', 'purpose' => 'supplier_payment', 'party_id' => $supplier->id, 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 40]);
        $this->assertSame('40', $this->roleBalance('payables_suppliers', 'USD', $supplier->id));
        $project = Project::create(['name' => 'شقق القاسم', 'status' => 'active', 'branch_id' => $this->hq->id]);
        $materials = ProjectCostCategory::where('system_key', 'materials')->value('id');
        $this->voucher(['type' => 'payment', 'purpose' => 'supplier_payment', 'party_id' => $supplier->id, 'project_id' => $project->id,
            'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 60], 422);
        $this->voucher(['type' => 'payment', 'purpose' => 'supplier_payment', 'party_id' => $supplier->id, 'project_id' => $project->id,
            'project_cost_category_id' => $materials, 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 60]);
        $line = JournalLine::where('account_id', $this->roleId('project_costs'))->sole();
        $this->assertSame([$project->id, $materials, $supplier->id], [$line->project_id, $line->project_cost_category_id, $line->party_id]);

        // R5: a closed project takes no postings; a completed one warns. R4: an inactive party is refused.
        $project->update(['status' => 'completed']);
        $completed = $this->voucher(['type' => 'payment', 'purpose' => 'supplier_payment', 'party_id' => $supplier->id, 'project_id' => $project->id,
            'project_cost_category_id' => $materials, 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 1]);
        $this->assertCount(1, $completed['warnings']);
        $project->update(['status' => 'closed']);
        $this->voucher(['type' => 'payment', 'purpose' => 'supplier_payment', 'party_id' => $supplier->id, 'project_id' => $project->id,
            'project_cost_category_id' => $materials, 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 1], 422);
        $supplier->update(['is_active' => false]);
        $this->voucher(['type' => 'payment', 'purpose' => 'supplier_payment', 'party_id' => $supplier->id, 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 1], 422);
    }

    public function test_manual_journal_never_touches_a_box(): void
    {
        $box = $this->box('Safe', ['USD' => null]);
        $this->voucher(['type' => 'manual_journal', 'lines' => [
            ['cash_box_id' => $box->id, 'currency' => 'USD', 'debit' => 10],
            ['account_id' => $this->accountId('3100'), 'currency' => 'USD', 'credit' => 10],
        ]], 422);
        $journal = $this->voucher(['type' => 'manual_journal', 'lines' => [
            ['account_id' => $this->accountId('6102'), 'currency' => 'USD', 'debit' => 10],
            ['account_id' => $this->accountId('3100'), 'currency' => 'USD', 'credit' => 10],
        ]]);
        $this->assertSame('MJ-2026-000001', $journal['number']);
        // Automatic types are not created by hand.
        $this->voucher(['type' => 'count_adjustment', 'cash_box_id' => $box->id], 422);
        $this->voucher(['type' => 'payroll_payment', 'cash_box_id' => $box->id], 422);
    }

    public function test_draft_life_cycle_with_versions_rejection_and_permissions(): void
    {
        $box = $this->box('Safe', ['USD' => null]);
        $this->fund($box, 'USD', 500);
        $draft = $this->payment($box, 'USD', 100, ['approve' => false]);
        $this->assertSame(['draft', 1], [$draft['status'], $draft['version']]);

        $this->putJson('/api/v1/finance/vouchers/'.$draft['id'], ['version' => 7, 'amount' => 120])->assertStatus(409);
        $updated = $this->putJson('/api/v1/finance/vouchers/'.$draft['id'], ['version' => 1, 'amount' => 120])->assertOk()->json('data');
        $this->assertSame([2, '120.0000'], [$updated['version'], $updated['amount']]);
        $this->postJson('/api/v1/finance/vouchers/'.$draft['id'].'/reject', ['reason' => 'x x x'])->assertStatus(409); // only submitted
        $this->postJson('/api/v1/finance/vouchers/'.$draft['id'].'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->putJson('/api/v1/finance/vouchers/'.$draft['id'], ['version' => 2, 'amount' => 1])->assertStatus(409);

        // The company manager reads finance but does not approve daily vouchers (D25).
        $this->login($this->director)->postJson('/api/v1/finance/vouchers/'.$draft['id'].'/approve')->assertForbidden();
        $this->getJson('/api/v1/finance/vouchers/'.$draft['id'])->assertOk();
        $this->login($this->finance)->postJson('/api/v1/finance/vouchers/'.$draft['id'].'/reject', ['reason' => 'مستند ناقص'])->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->postJson('/api/v1/finance/vouchers/'.$draft['id'].'/approve')->assertStatus(409);
        $this->deleteJson('/api/v1/finance/vouchers/'.$draft['id'])->assertStatus(409);

        // Finance approves what it entered itself (OP-14: no four-eyes on daily vouchers).
        $own = $this->payment($box, 'USD', 50, ['approve' => false]);
        $this->postJson('/api/v1/finance/vouchers/'.$own['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->putJson('/api/v1/finance/vouchers/'.$own['id'], ['version' => 1, 'amount' => 1])->assertStatus(409);
        $this->assertSame(2, $this->getJson('/api/v1/finance/vouchers?type=payment')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/v1/finance/vouchers?type=payment&status=approved')->json('meta.total'));
    }

    public function test_a_branch_accountant_works_only_in_the_assigned_branches(): void
    {
        $idlib = Branch::create(['name' => 'فرع إدلب', 'code' => 'IDL']);
        $hqBox = $this->box('Head office safe', ['USD' => null]);
        $idlibBox = $this->box('Idlib safe', ['USD' => null], $idlib->id);
        $this->fund($hqBox, 'USD', 100);
        $this->fund($idlibBox, 'USD', 100);
        $accountant = $this->actor('branch_accountant');
        $this->putJson('/api/v1/finance/users/'.$accountant->id.'/branches', ['branch_ids' => [$idlib->id]])->assertOk()->assertJsonPath('data.branch_ids', [$idlib->id]);

        $this->login($accountant);
        $this->assertSame([$idlibBox->id], array_column($this->getJson('/api/v1/finance/cash-boxes')->assertOk()->json('data'), 'id'));
        $this->getJson('/api/v1/finance/cash-boxes/'.$hqBox->id)->assertForbidden();
        $this->payment($hqBox, 'USD', 10, [], 403);
        $this->voucher(['type' => 'transfer', 'cash_box_id' => $idlibBox->id, 'counter_cash_box_id' => $hqBox->id, 'currency' => 'USD', 'amount' => 10], 403);
        $own = $this->payment($idlibBox, 'USD', 10);
        $this->assertSame($idlib->id, $own['branch_id']);
        $this->assertSame([$own['id']], array_column($this->getJson('/api/v1/finance/vouchers?type=payment')->json('data'), 'id'));
        $this->getJson('/api/v1/finance/reports/box-statement?box_id='.$hqBox->id)->assertForbidden();
        $this->postJson('/api/v1/finance/cash-boxes', ['name' => 'x', 'type' => 'bank', 'branch_id' => $idlib->id, 'currencies' => [['currency' => 'USD']]])
            ->assertForbidden();

        $hqDraft = $this->login($this->finance)->payment($hqBox, 'USD', 5, ['approve' => false]);
        $this->login($accountant)->postJson('/api/v1/finance/vouchers/'.$hqDraft['id'].'/approve')->assertForbidden();
        $this->getJson('/api/v1/finance/vouchers/'.$hqDraft['id'])->assertForbidden();

        // A site supervisor never approves.
        $this->login($this->actor('site_supervisor'))->postJson('/api/v1/finance/vouchers/'.$hqDraft['id'].'/approve')->assertForbidden();
        $this->getJson('/api/v1/finance/cash-boxes')->assertForbidden();
    }

    public function test_box_statement_has_opening_running_and_closing_balances_per_currency(): void
    {
        $box = $this->box('Safe', ['USD' => null, 'TRY' => null]);
        $this->fund($box, 'USD', 1000, '2026-10-20');
        $this->payment($box, 'USD', 100, ['voucher_date' => '2026-11-02']);
        $this->voucher(['type' => 'receipt', 'purpose' => 'other', 'cash_box_id' => $box->id, 'currency' => 'USD', 'amount' => 40,
            'account_id' => $this->accountId('6105'), 'voucher_date' => '2026-11-03']);
        $this->payment($box, 'USD', 10, ['voucher_date' => '2026-11-20']);

        $this->login($this->director);
        $statement = $this->getJson('/api/v1/finance/reports/box-statement?box_id='.$box->id.'&from=2026-11-01&to=2026-11-15')->assertOk()->json('data');
        $usd = collect($statement['currencies'])->firstWhere('code', 'USD');
        $this->assertSame(['1000', '940', '40', '100'], [$usd['opening'], $usd['closing'], $usd['total_in'], $usd['total_out']]);
        $this->assertSame(['900', '940'], array_column($usd['lines'], 'balance'));
        $this->assertSame('PV-2026-000001', $usd['lines'][0]['voucher_number']);
        $this->assertSame('0', collect($statement['currencies'])->firstWhere('code', 'TRY')['closing']);
        $this->login($this->actor('hr_manager'))->getJson('/api/v1/finance/reports/box-statement?box_id='.$box->id)->assertForbidden();
    }
}
