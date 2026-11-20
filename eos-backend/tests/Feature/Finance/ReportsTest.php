<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\UserBranch;
use App\Modules\Finance\Services\Core\PostingService;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\ExchangeRateService;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\Feature\Finance\Concerns\ProjectFixture;
use Tests\TestCase;

/** Reports T1–T23 (phase 10): figures match the ledger, consolidation in both rate modes, branch and project reach, CSV. */
class ReportsTest extends TestCase
{
    use FinanceFixture, ProjectFixture, RefreshDatabase;

    private Party $client;

    private array $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->setUpProjects();
        $this->client = $this->party('عميل', ['client']);
        $this->project = $this->project(['name' => 'برج', 'client_party_id' => $this->client->id]);
        $this->receipt($this->project['project_id'], $this->client, 2000);
        $this->cost($this->project['project_id'], 'materials', 500);
        // 100 USD → 4,340 TRY at the bulletin rate.
        $this->voucher(['type' => 'exchange', 'cash_box_id' => $this->cash->id, 'counter_cash_box_id' => $this->cash->id, 'currency' => 'USD',
            'amount' => 100, 'counter_currency' => 'TRY', 'counter_amount' => 4340, 'voucher_date' => '2026-11-11']);
    }

    private function report(string $name, array $query = []): array
    {
        return $this->getJson('/api/v1/finance/reports/'.$name.'?'.http_build_query($query))->assertOk()->json('data');
    }

    /** Σ(debit − credit) of the lines matching `$where` up to `$date`. */
    private function ledger(array $where, string $date = '2026-11-15', string $column = 'debit - credit'): string
    {
        return Money::round(Money::of((string) JournalLine::query()->where($where)->where('entry_date', '<=', $date)
            ->sum(DB::raw($column))), 4);
    }

    public function test_box_balances_trial_balance_and_balance_sheet_match_the_ledger(): void
    {
        $usd = $this->currencyId('USD');

        $t1 = $this->report('box-balances', ['as_of' => '2026-11-15']);
        $box = collect($t1['boxes'])->firstWhere('id', $this->cash->id);
        $balances = collect($box['balances'])->keyBy('code');
        $this->assertSame($this->ledger(['account_id' => $this->cash->account_id, 'currency_id' => $usd]), Money::of($balances['USD']['balance']));
        $this->assertSame('1001400', Money::of($balances['USD']['balance']), '1,000,000 + 2,000 − 500 − 100');
        $this->assertSame('10004340', Money::of($balances['TRY']['balance']));
        $this->assertSame(Money::of($balances['USD']['balance']), Money::of(collect($t1['totals'])->firstWhere('code', 'USD')['balance']));
        // As of a day before the receipt only the funding is there.
        $before = collect($this->report('box-balances', ['as_of' => '2026-11-05'])['boxes'])->firstWhere('id', $this->cash->id);
        $this->assertSame('1000000', Money::of(collect($before['balances'])->firstWhere('code', 'USD')['balance']));

        $t5 = $this->report('trial-balance', ['from' => '2026-11-01', 'to' => '2026-11-15']);
        foreach ($t5['currencies'] as $block) {
            $this->assertTrue($block['totals']['balanced'], $block['code'].' trial balance balances');
            foreach ($block['accounts'] as $row) {
                $this->assertSame($this->ledger(['account_id' => $row['account_id'], 'currency_id' => $block['currency_id']]), Money::of($row['closing']),
                    "closing of {$row['code']} {$block['code']} = Σ lines");
            }
        }
        $usdBlock = collect($t5['currencies'])->firstWhere('code', 'USD');
        $this->assertSame($this->ledger(['currency_id' => $usd], column: 'debit'), Money::of($usdBlock['totals']['debit']));

        $t7 = $this->report('balance-sheet', ['as_of' => '2026-11-15']);
        foreach ($t7['currencies'] as $block) {
            $this->assertTrue($block['balanced'], $block['code'].': assets = liabilities + equity + profit');
        }
        $usdSheet = collect($t7['currencies'])->firstWhere('code', 'USD');
        $assets = Money::round(Money::of((string) JournalLine::query()->join('fin_accounts as a', 'a.id', '=', 'fin_journal_lines.account_id')
            ->where('a.type', 'asset')->where('currency_id', $usd)->sum(DB::raw('debit - credit'))), 4);
        $this->assertSame($assets, Money::of($usdSheet['total_assets']));
        $this->assertSame('-500', Money::of($usdSheet['current_profit']), 'the cost is the only P&L line');
        $this->assertSame($this->ledger(['account_id' => $this->roleId('client_advances'), 'currency_id' => $usd]),
            Money::neg(Money::of(collect($usdSheet['liabilities'])->firstWhere('account_id', $this->roleId('client_advances'))['amount'])));
    }

    public function test_consolidated_views_convert_at_the_report_date_or_historically_and_list_the_rates_used(): void
    {
        app(ExchangeRateService::class)->setRates('2026-11-10', ['TRY' => 40]);

        $byDate = $this->report('box-balances', ['as_of' => '2026-11-15', 'view' => 'consolidated']);
        $this->assertSame('report_date', $byDate['consolidation']['rate_mode']);
        $rates = collect($byDate['consolidation']['rates_used'])->keyBy('currency');
        $this->assertSame(['TRY', '40', '2026-11-10'], [$rates['TRY']['currency'], Money::of($rates['TRY']['rate']), $rates['TRY']['date']],
            'the latest rate on or before the report date, with its day');
        $box = collect($byDate['boxes'])->firstWhere('id', $this->cash->id);
        $this->assertSame(Money::add('1001400', Money::div('10004340', '40', 4)), Money::of($box['total_base']));
        $this->assertSame(Money::of($box['total_base']), Money::of($byDate['consolidated']['total']));

        // Historical: the base amounts stored on the lines (TRY at 43.4 when posted), whatever today's rate.
        app(SettingService::class)->set('finance.consolidated_report_rate_mode', 'historical', 'finance');
        $historical = $this->report('box-balances', ['as_of' => '2026-11-15', 'view' => 'consolidated']);
        $this->assertSame('historical', $historical['consolidation']['rate_mode']);
        $this->assertNull(collect($historical['consolidation']['rates_used'])->firstWhere('currency', 'TRY')['rate']);
        $stored = $this->ledger(['account_id' => $this->cash->account_id], column: 'base_debit - base_credit');
        $this->assertSame($stored, Money::of($historical['consolidated']['total']));

        // The request may override the mode; the consolidated trial balance balances in both.
        foreach (['report_date', 'historical'] as $mode) {
            $tb = $this->report('trial-balance', ['from' => '2026-11-01', 'to' => '2026-11-15', 'view' => 'consolidated', 'rate_mode' => $mode]);
            $this->assertSame($mode, $tb['consolidation']['rate_mode']);
            $this->assertNotEmpty($tb['consolidation']['rates_used']);
            $this->assertLessThanOrEqual(0.001, abs((float) $tb['consolidated']['totals']['closing_debit'] - (float) $tb['consolidated']['totals']['closing_credit']));
        }
        $sheet = $this->report('balance-sheet', ['as_of' => '2026-11-15', 'view' => 'consolidated', 'rate_mode' => 'report_date']);
        $this->assertTrue($sheet['consolidated']['balanced']);
        $this->assertArrayHasKey('rates_used', $sheet['consolidation']);
    }

    public function test_a_branch_accountant_sees_only_the_assigned_branches(): void
    {
        $north = Branch::create(['name' => 'فرع إدلب', 'code' => 'IDL']);
        $northBox = $this->box('صندوق إدلب', ['USD' => null], $north->id);
        $this->postJson('/api/v1/finance/vouchers', ['type' => 'opening_balance', 'voucher_date' => '2026-11-01', 'approve' => true, 'branch_id' => $north->id,
            'lines' => [['cash_box_id' => $northBox->id, 'currency' => 'USD', 'debit' => 300]]])->assertCreated();
        $accountant = $this->actor('branch_accountant');
        UserBranch::create(['user_id' => $accountant->id, 'branch_id' => $north->id]);
        $this->login($accountant);

        $t1 = $this->report('box-balances');
        $this->assertSame([$northBox->id], array_column($t1['boxes'], 'id'));
        $this->assertSame([$north->id], $t1['filters']['branch_ids']);
        $this->getJson('/api/v1/finance/reports/box-balances?branch_id='.$this->hq->id)->assertForbidden();
        $this->getJson('/api/v1/finance/reports/box-statement?box_id='.$this->cash->id)->assertForbidden();

        $tb = collect($this->report('trial-balance', ['from' => '2026-11-01'])['currencies'])->firstWhere('code', 'USD');
        $this->assertSame('300', Money::of($tb['totals']['debit']), 'only the Idlib lines');
        $this->assertSame([], $this->report('aging', ['as_of' => '2026-11-15'])['rows']);
    }

    public function test_project_reports_follow_project_visibility(): void
    {
        $manager = $this->actor('project_manager');
        $mine = $this->project(['name' => 'مشروعي', 'client_party_id' => $this->client->id, 'manager_user_id' => $manager->id]);
        $this->receipt($mine['project_id'], $this->client, 1000);
        $this->bill($mine['project_id'], 800);
        $this->cost($mine['project_id'], 'materials', 300);
        $this->bill($this->project['project_id'], 1000);

        $all = $this->report('project-profitability');
        $this->assertCount(2, $all['projects']);
        $this->assertSame([$this->project['project_id'], $mine['project_id']], array_column($all['projects'], 'project_id'), 'ranked by net margin');
        $this->assertSame([1, 2], array_column($all['projects'], 'rank'));

        $this->login($manager);
        $this->assertSame([$mine['project_id']], array_column($this->report('projects-summary')['projects'], 'project_id'));
        $ranking = $this->report('project-profitability');
        $this->assertSame([$mine['project_id']], array_column($ranking['projects'], 'project_id'));
        $this->assertSame(['500', '500'], [Money::of($ranking['projects'][0]['gross_margin']), Money::of($ranking['projects'][0]['net_margin'])]);
        $this->getJson('/api/v1/finance/reports/project-statement?project_id='.$this->project['project_id'])->assertNotFound();
        $this->getJson('/api/v1/finance/reports/project-statement?project_id='.$mine['project_id'])->assertOk()->assertJsonPath('data.totals.incoming', '1000');
        $this->getJson('/api/v1/finance/reports/trial-balance')->assertForbidden();

        $summary = $this->login($this->finance)->report('projects-summary', ['view' => 'consolidated']);
        $this->assertSame(Money::add('2000', '1000'), Money::of($summary['consolidated']['receipts']));
        $this->assertSame('3000', Money::of(collect($summary['totals'])->firstWhere('currency', 'USD')['receipts']));
    }

    public function test_aging_settles_the_oldest_debits_first(): void
    {
        $debtor = $this->party('مدين', ['other']);
        $lend = fn (int $amount, string $date, string $type = 'payment') => $this->voucher(['type' => $type, 'purpose' => 'debt', 'debt_direction' => 'owed_to_us',
            'party_id' => $debtor->id, 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => $amount, 'voucher_date' => $date]);
        $this->fund($this->cash, 'USD', 5000, '2026-09-01');
        $lend(1000, '2026-09-05');
        $lend(500, '2026-10-10');
        $lend(1200, '2026-11-01', 'receipt');

        $row = collect($this->report('aging', ['as_of' => '2026-11-15', 'side' => 'receivable'])['rows'])->firstWhere('party_id', $debtor->id);
        $this->assertSame(['300', '0', '300', '0', '0', '0', '2026-10-10'], [Money::of($row['outstanding']), Money::of($row['0_30']), Money::of($row['31_60']),
            Money::of($row['61_90']), Money::of($row['90_plus']), Money::of($row['unapplied']), $row['oldest_open_date']],
            'the 1,000 of September is settled first; 300 of the October loan stays, 36 days old');

        $csv = $this->get('/api/v1/finance/reports/aging?format=csv&as_of=2026-11-15')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('مدين', $csv->streamedContent());
    }

    public function test_ledger_lists_and_period_reports(): void
    {
        $journal = $this->getJson('/api/v1/finance/reports/journal?from=2026-11-01&to=2026-11-15&voucher_type=exchange')->assertOk();
        $this->assertSame(1, $journal->json('meta.total'));
        $this->assertCount(4, $journal->json('data.entries.0.lines'));
        $this->assertStringContainsString('JE-2026-', $this->get('/api/v1/finance/reports/journal?format=csv&from=2026-11-01')->streamedContent());

        $gl = $this->report('general-ledger', ['account_ids' => [$this->cash->account_id], 'from' => '2026-11-05', 'to' => '2026-11-15', 'currency' => 'USD']);
        $usd = $gl['accounts'][0]['currencies'][0];
        $this->assertSame(['1000000', '1001400'], [Money::of($usd['opening']), Money::of($usd['closing'])]);
        $this->assertSame('1001400', Money::of(end($usd['lines'])['balance']));

        $income = $this->report('income-statement', ['from' => '2026-11-01', 'to' => '2026-11-15', 'by_branch' => 1]);
        $this->assertSame('-500', Money::of(collect($income['currencies'])->firstWhere('code', 'USD')['net_profit']));
        $this->assertSame($this->hq->id, $income['branches'][0]['branch_id']);

        // T21: an exchange away from the bulletin moves fx_position; its USD balance is the exchange result.
        $this->voucher(['type' => 'exchange', 'cash_box_id' => $this->cash->id, 'counter_cash_box_id' => $this->cash->id, 'currency' => 'USD',
            'amount' => 100, 'counter_currency' => 'TRY', 'counter_amount' => 4400, 'voucher_date' => '2026-11-12']);
        $t21 = $this->report('exchanges', ['from' => '2026-11-01']);
        $this->assertCount(2, $t21['exchanges']);
        $this->assertSame('0', Money::of($t21['exchanges'][0]['difference']));
        $this->assertSame('43.4', Money::of($t21['exchanges'][1]['bulletin_rate']));
        $fx = $this->ledger(['account_id' => $this->roleId('fx_position')], column: 'base_debit - base_credit');
        $this->assertSame($fx, Money::of($t21['fx_position']['balance_base']));
        $this->assertTrue(Money::isPositive($t21['fx_position']['result_base']), 'more TRY than the bulletin gives a gain');
        $this->assertSame(Money::of($t21['period_result_base']), Money::of($t21['fx_position']['result_base']));

        // T18: expenses by category tree and month, with the parent's subtotal.
        $food = ExpenseCategory::where('name', 'طعام')->firstOrFail();
        $this->fund($this->cash, 'USD', 500, '2026-10-01');
        foreach (['2026-10-05' => 50, '2026-11-03' => 30] as $date => $amount) {
            $this->voucher(['type' => 'payment', 'purpose' => 'internal_expense', 'expense_category_id' => $food->id, 'cash_box_id' => $this->cash->id,
                'currency' => 'USD', 'amount' => $amount, 'voucher_date' => $date]);
        }
        $t18 = $this->report('expenses-by-category', ['from' => '2026-10-01', 'to' => '2026-11-15', 'currency' => 'USD']);
        $this->assertSame(['2026-10', '2026-11'], $t18['months']);
        $operating = collect($t18['currencies'][0]['categories'])->firstWhere('category_id', $food->parent_id);
        $this->assertTrue($operating['is_subtotal']);
        $this->assertSame(['50', '30', '80'], [Money::of($operating['months']['2026-10']), Money::of($operating['months']['2026-11']), Money::of($operating['total'])]);

        // T17: partner drawings and deposits per month.
        $partner = $this->party('المدير العام', ['partner']);
        $this->voucher(['type' => 'payment', 'purpose' => 'partner_drawing', 'classification' => 'partner_personal', 'party_id' => $partner->id,
            'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 700, 'voucher_date' => '2026-11-04']);
        $t17 = $this->report('partners', ['from' => '2026-10-01', 'to' => '2026-11-15']);
        $row = collect($t17['partners'])->firstWhere('party_id', $partner->id);
        $this->assertSame(['700', '700', 'لنا'], [Money::of($row['months'][1]['drawings']), Money::of($row['closing']), $row['closing_side']]);
    }

    public function test_payroll_cost_comes_from_the_accrual_entries(): void
    {
        $voucher = app(VoucherService::class)->createInternal(['type' => 'payroll_accrual', 'voucher_date' => '2026-10-31', 'branch_id' => $this->hq->id,
            'lines' => [
                ['account_id' => $this->roleId('salaries_expense'), 'employee_id' => 77, 'currency' => 'USD', 'debit' => 1000, 'memo' => 'راتب'],
                ['account_id' => $this->roleId('project_costs'), 'employee_id' => 77, 'project_id' => $this->project['project_id'],
                    'project_cost_category_id' => $this->categoryId('loaded_payroll'), 'currency' => 'USD', 'debit' => 200, 'memo' => 'راتب إشراف'],
                ['account_id' => $this->roleId('salaries_payable'), 'employee_id' => 77, 'currency' => 'USD', 'credit' => 1200, 'memo' => 'صافي الراتب'],
            ]], $this->finance);
        app(PostingService::class)->approve($voucher, $this->finance);

        $t19 = $this->report('payroll-cost', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $expected = Money::round(Money::of((string) JournalLine::query()->join('fin_accounts as a', 'a.id', '=', 'fin_journal_lines.account_id')
            ->where('a.type', 'expense')->where('journal_entry_id', $voucher->refresh()->journal_entry_id)->sum(DB::raw('debit - credit'))), 4);
        $this->assertSame($expected, Money::of($t19['totals'][0]['amount']));
        $this->assertSame('1200', Money::of($t19['totals'][0]['amount']));
        $this->assertSame([77], array_column($t19['by_employee'], 'employee_id'));
        $this->assertSame(['200'], array_map(fn ($r) => Money::of($r['amount']), array_values(array_filter($t19['by_project'], fn ($r) => $r['project_id'] !== null))));
        $this->assertEqualsCanonicalizing(['راتب', 'راتب إشراف'], array_column($t19['by_component'], 'component'));
    }

    public function test_wrapped_reports_and_csv_exports_answer(): void
    {
        foreach (['employee-advances', 'open-custodies', 'cash-counts', 'contractor-balances', 'party-statement?party_id='.$this->client->id,
            'budget-vs-actual', 'audit-log'] as $report) {
            $this->getJson('/api/v1/finance/reports/'.$report)->assertOk();
        }
        $tb = $this->get('/api/v1/finance/reports/trial-balance?format=csv&view=consolidated')->assertOk();
        $this->assertStringContainsString('(موحّد)', $tb->streamedContent());
        $this->assertStringContainsString('RV-2026-000001', $this->get('/api/v1/finance/reports/party-statement?format=csv&party_id='.$this->client->id)->streamedContent());

        $statement = $this->report('party-statement', ['party_id' => $this->client->id, 'view' => 'consolidated']);
        $this->assertSame('-2000', Money::of($statement['consolidated']['closing']));
        $this->login($this->actor('branch_accountant'))->getJson('/api/v1/finance/reports/audit-log')->assertForbidden();
    }
}
