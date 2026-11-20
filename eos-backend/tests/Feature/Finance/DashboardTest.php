<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\UserBranch;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\Feature\Finance\Concerns\ProjectFixture;
use Tests\TestCase;

/** The general manager's dashboard (spec §10.1). */
class DashboardTest extends TestCase
{
    use FinanceFixture, ProjectFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->setUpProjects();
    }

    public function test_the_dashboard_gathers_liquidity_alerts_projects_flows_dues_custodies_counts_and_drawings(): void
    {
        $this->fund($this->cash, 'USD', 1000, '2026-10-01');
        $client = $this->party('عميل', ['client']);
        $profitable = $this->project(['name' => 'رابح', 'client_party_id' => $client->id]);
        $losing = $this->project(['name' => 'خاسر', 'client_party_id' => $client->id]);
        $this->postJson("/api/v1/finance/projects/{$losing['project_id']}/budget-lines", ['project_cost_category_id' => $this->categoryId('materials'),
            'currency_id' => $this->currencyId('USD'), 'budget_amount' => 100])->assertCreated();
        $this->receipt($profitable['project_id'], $client, 2000);
        $this->cost($profitable['project_id'], 'materials', 500);
        $this->bill($profitable['project_id'], 2500);
        $this->cost($losing['project_id'], 'materials', 200);

        // A box allowed −1,000 that has used 900 of it.
        $exchange = $this->box('صرافة', ['USD' => 1000]);
        $this->voucher(['type' => 'payment', 'purpose' => 'other', 'account_id' => $this->accountId('6101'), 'cash_box_id' => $exchange->id,
            'currency' => 'USD', 'amount' => 900]);
        // Internal moves are not income or spending.
        $this->voucher(['type' => 'exchange', 'cash_box_id' => $this->cash->id, 'counter_cash_box_id' => $this->cash->id, 'currency' => 'USD',
            'amount' => 100, 'counter_currency' => 'TRY', 'counter_amount' => 4340]);
        $holder = $this->party('أبو مراد', ['other']);
        $this->voucher(['type' => 'custody_issue', 'party_id' => $holder->id, 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 100,
            'voucher_date' => '2026-10-02']);
        $contractor = $this->party('مقاول', ['contractor']);
        $this->postJson('/api/v1/finance/contractor-agreements', ['project_id' => $profitable['project_id'], 'contractor_party_id' => $contractor->id,
            'scope' => 'كهرباء', 'currency_id' => $this->currencyId('USD'), 'agreed_value' => 5000])->assertCreated();
        $partner = $this->party('المدير العام', ['partner']);
        $this->voucher(['type' => 'payment', 'purpose' => 'partner_drawing', 'classification' => 'partner_personal', 'party_id' => $partner->id,
            'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 300, 'voucher_date' => '2026-11-12']);
        $count = $this->postJson('/api/v1/finance/cash-counts', ['cash_box_id' => $this->cash->id, 'count_date' => '2026-11-14',
            'lines' => [['currency' => 'USD', 'counted_amount' => 1000]]])->assertCreated()->json('data');
        $this->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/submit')->assertOk();

        $data = $this->login($this->director)->getJson('/api/v1/finance/dashboard')->assertOk()->json('data');

        $usd = collect($data['liquidity']['currencies'])->firstWhere('currency', 'USD');
        $this->assertSame(Money::add($this->boxBalance($this->cash, 'USD'), $this->boxBalance($exchange, 'USD')), Money::of($usd['balance']));
        $try = collect($data['liquidity']['currencies'])->firstWhere('currency', 'TRY');
        $this->assertSame(Money::add($usd['balance_base'], Money::div($try['balance'], '43.4', 4)), Money::of($data['liquidity']['total_base']));
        $this->assertSame('43.4', Money::of(collect($data['consolidation']['rates_used'])->firstWhere('currency', 'TRY')['rate']));

        $this->assertSame([[$exchange->id, '-900', true]], array_map(fn ($a) => [$a['cash_box_id'], Money::of($a['balance']), $a['near_limit']], $data['box_alerts']));

        $this->assertSame([$profitable['project_id'], $losing['project_id']], array_column($data['projects']['top_profit'], 'project_id'));
        $this->assertSame(['2000', '-200'], array_map(fn ($p) => Money::of($p['net_profit']), $data['projects']['top_profit']));
        $this->assertSame([$losing['project_id']], array_column($data['projects']['over_budget'], 'project_id'));

        $flow = collect($data['cash_flow']['currencies'])->firstWhere('currency', 'USD');
        $this->assertSame(['1000', '100'], [Money::of($flow['last_month']['in']), Money::of($flow['last_month']['out'])]);
        $this->assertSame(['1002000', '1900'], [Money::of($flow['this_month']['in']), Money::of($flow['this_month']['out'])],
            'the exchange is left out: out = 500 + 200 + 900 + 300');

        $this->assertSame('5000', Money::of($data['contractors']['agreements_remaining'][0]['amount']));
        $this->assertSame('500', Money::of($data['clients']['currencies'][0]['amount']), 'billed 2,500 against 2,000 received');
        $this->assertSame(1, $data['custodies']['count']);
        $this->assertSame([$count['id']], array_column($data['cash_counts_pending']['items'], 'id'));
        $this->assertSame('300', Money::of($data['partner_drawings']['currencies'][0]['personal']));
    }

    public function test_a_branch_accountant_sees_only_the_assigned_branches_and_others_are_refused(): void
    {
        $north = Branch::create(['name' => 'فرع إدلب', 'code' => 'IDL']);
        $box = $this->box('صندوق إدلب', ['USD' => null], $north->id);
        $this->postJson('/api/v1/finance/vouchers', ['type' => 'opening_balance', 'voucher_date' => '2026-11-01', 'approve' => true, 'branch_id' => $north->id,
            'lines' => [['cash_box_id' => $box->id, 'currency' => 'USD', 'debit' => 250]]])->assertCreated();
        $accountant = $this->actor('branch_accountant');
        UserBranch::create(['user_id' => $accountant->id, 'branch_id' => $north->id]);

        $data = $this->login($accountant)->getJson('/api/v1/finance/dashboard')->assertOk()->json('data');
        $this->assertSame([['USD', '250']], array_map(fn ($c) => [$c['currency'], Money::of($c['balance'])], $data['liquidity']['currencies']));

        $this->login($this->actor('project_manager'))->getJson('/api/v1/finance/dashboard')->assertForbidden();
    }
}
