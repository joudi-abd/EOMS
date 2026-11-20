<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\UserBranch;
use App\Modules\Projects\Models\Project;
use App\Modules\Settings\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\Feature\Finance\Concerns\ProjectFixture;
use Tests\TestCase;

/** Project finance (phase 5): profiles, contract types, billing (#1, #2, §6.1), R14, profitability (§6.2), statement (T9), reach. */
class ProjectFinanceTest extends TestCase
{
    use FinanceFixture, ProjectFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->setUpProjects();
    }

    private function suggestion(int $projectId, string $date = '2026-11-15'): array
    {
        return $this->login($this->finance)->getJson("/api/v1/finance/projects/{$projectId}/billing-suggestion?date={$date}")->assertOk()->json('data');
    }

    public function test_creating_a_project_creates_the_general_project_and_its_profile_under_the_contract_rules(): void
    {
        $client = $this->party('عماد القاسم', ['client']);
        $manager = $this->actor('project_manager');
        $base = ['name' => 'شقق القاسم', 'branch_id' => $this->hq->id, 'contract_currency_id' => $this->currencyId('USD'), 'client_party_id' => $client->id];

        $this->postJson('/api/v1/finance/projects', [...$base, 'contract_type' => 'lump_sum'])->assertUnprocessable()->assertJsonValidationErrors('contract_value');
        $this->postJson('/api/v1/finance/projects', [...$base, 'contract_type' => 'cost_plus'])->assertUnprocessable()->assertJsonValidationErrors('fee_pct');
        $this->postJson('/api/v1/finance/projects', [...$base, 'contract_type' => 'percentage_of_receipts', 'fee_pct' => 8,
            'client_party_id' => $this->supplier->id])->assertUnprocessable()->assertJsonValidationErrors('client_party_id');
        $this->postJson('/api/v1/finance/projects', [...$base, 'contract_type' => 'cost_plus', 'fee_pct' => 10,
            'fee_base_category_ids' => [$this->categoryId('loaded_payroll')]])->assertUnprocessable()->assertJsonValidationErrors('fee_base_category_ids');

        $first = $this->project([...$base, 'contract_type' => 'percentage_of_receipts', 'fee_pct' => 8, 'manager_user_id' => $manager->id,
            'start_date' => '2026-01-01']);
        $this->assertSame(['100100', 'percentage_of_receipts', '8', 'USD', 'شقق القاسم', 'active'], [$first['code'], $first['contract_type'],
            (string) (float) $first['fee_pct'], $first['contract_currency'], $first['name'], $first['status']]);
        $project = Project::findOrFail($first['project_id']);
        $this->assertSame([$manager->id, $this->hq->id, '2026-01-01'], [$project->manager_user_id, $project->branch_id, $project->start_date->toDateString()]);

        // Internal projects need no client and keep no fee; the code sequence continues.
        $internal = $this->project(['name' => 'مكاتب الشركة', 'contract_type' => 'internal', 'fee_pct' => 5]);
        $this->assertSame(['100101', null, null], [$internal['code'], $internal['client_party_id'], $internal['fee_pct']]);

        // A general project made in the Projects module gets its profile by id; a second profile is refused.
        $general = Project::create(['name' => 'عام', 'status' => 'planned', 'branch_id' => $this->hq->id]);
        $this->postJson('/api/v1/finance/projects', ['project_id' => $general->id, 'contract_type' => 'internal',
            'contract_currency_id' => $this->currencyId('USD')])->assertCreated()->assertJsonPath('data.code', '100102');
        $this->postJson('/api/v1/finance/projects', ['project_id' => $general->id, 'contract_type' => 'internal',
            'contract_currency_id' => $this->currencyId('USD')])->assertConflict();

        // Status goes through the Projects module.
        $this->postJson("/api/v1/finance/projects/{$first['project_id']}/status", ['status' => 'on_hold'])->assertOk()->assertJsonPath('data.status', 'on_hold');
        $this->getJson('/api/v1/finance/projects?contract_type=internal')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/finance/projects?client_party_id={$client->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/finance/projects/{$first['project_id']}")->assertOk()->assertJsonPath('data.code', '100100')
            ->assertJsonPath('data.summary.billings.approved', 0);
    }

    /** §14 item 6 and the §6.2 Excel case: 8% of 141,500 USD of receipts = 11,320. */
    public function test_percentage_of_receipts_suggestion_matches_the_excel_case(): void
    {
        $client = $this->party('عماد القاسم', ['client']);
        $project = $this->project(['name' => 'عماد القاسم – شقق', 'contract_type' => 'percentage_of_receipts', 'fee_pct' => 8, 'client_party_id' => $client->id]);
        foreach ([2000, 25000, 23000, 5000, 20000, 25000, 5000, 35000, 1500] as $amount) {
            $this->receipt($project['project_id'], $client, $amount);
        }
        $this->cost($project['project_id'], 'materials', 50000);
        $this->cost($project['project_id'], 'labor', 30000);
        $this->loadedCost($project['project_id'], 4000);

        $s = $this->suggestion($project['project_id']);
        $this->assertSame(['141500', '80000', '11320', '0', '91320', '141500'], [$s['receipts'], $s['billable_costs'], $s['fee'], $s['already_billed'],
            $s['suggested'], $s['available_advances']]);

        $this->bill($project['project_id'], 50000);
        $s = $this->suggestion($project['project_id']);
        $this->assertSame(['50000', '41320', '91500'], [$s['already_billed'], $s['suggested'], $s['available_advances']]);
        // Costs dated after the billing date stay out of the suggestion.
        $this->cost($project['project_id'], 'materials', 1000, 'USD', '2026-11-14');
        $this->assertSame('41320', $this->suggestion($project['project_id'], '2026-11-13')['suggested']);
    }

    public function test_cost_plus_suggestion_uses_billable_costs_the_fee_base_and_converts_other_currencies(): void
    {
        $client = $this->party('عميل', ['client']);
        $project = $this->project(['contract_type' => 'cost_plus', 'fee_pct' => 10, 'client_party_id' => $client->id]);
        $this->cost($project['project_id'], 'materials', 1000);
        $this->cost($project['project_id'], 'labor', 500);
        $this->loadedCost($project['project_id'], 300); // OP-2: not billed
        $s = $this->suggestion($project['project_id']);
        $this->assertSame(['1500', '150', '1650'], [$s['billable_costs'], $s['fee'], $s['suggested']]);

        // 4,340 TRY at the bulletin (43.4 per USD) = 100 USD in the contract currency.
        $this->cost($project['project_id'], 'design', 4340, 'TRY');
        $this->assertSame(['1600', '160', '1760'], array_values(array_intersect_key($this->suggestion($project['project_id']), array_flip(['billable_costs', 'fee', 'suggested']))));

        // The fee only on materials: 1,600 + 10% × 1,000.
        $this->patchJson("/api/v1/finance/projects/{$project['project_id']}", ['fee_base_category_ids' => [$this->categoryId('materials')]])->assertOk();
        $s = $this->suggestion($project['project_id']);
        $this->assertSame(['1000', '100', '1700'], [$s['fee_base_costs'], $s['fee'], $s['suggested']]);
    }

    public function test_lump_sum_billing_is_capped_at_the_contract_value_and_internal_projects_are_not_billed(): void
    {
        $client = $this->party('عميل', ['client']);
        $lump = $this->project(['contract_type' => 'lump_sum', 'contract_value' => 10000, 'client_party_id' => $client->id]);
        $this->cost($lump['project_id'], 'materials', 2500);
        $s = $this->suggestion($lump['project_id']);
        $this->assertSame([null, '10000', null], [$s['suggested'], $s['max'], $s['fee']]);

        $this->bill($lump['project_id'], 6000);
        $this->bill($lump['project_id'], 4001, 422);
        $this->assertSame('4000', $this->suggestion($lump['project_id'])['max']);
        $this->bill($lump['project_id'], 4000);
        $this->assertSame('0', $this->suggestion($lump['project_id'])['max']);

        $internal = $this->project(['name' => 'مكاتب الشركة', 'contract_type' => 'internal']);
        $this->getJson("/api/v1/finance/projects/{$internal['project_id']}/billing-suggestion")->assertUnprocessable();
        $this->bill($internal['project_id'], 100, 422);
        $this->voucher(['type' => 'project_billing', 'project_id' => $internal['project_id'], 'currency' => 'USD', 'amount' => 100], 422);
    }

    /** #1 and #2: a billing consumes the client's advances first, then the rest is a receivable. */
    public function test_billing_consumes_client_advances_then_receivables(): void
    {
        $client = $this->party('عميل', ['client']);
        $other = $this->party('عميل آخر', ['client']);
        $project = $this->project(['contract_type' => 'cost_plus', 'fee_pct' => 10, 'client_party_id' => $client->id]);
        $id = $project['project_id'];

        // #1: only the project's client, on a project with a profile.
        $this->receipt($id, $other, 100, 'USD', '2026-11-10', 422);
        $this->login($this->finance)->voucher(['type' => 'receipt', 'purpose' => 'client_payment', 'party_id' => $client->id,
            'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 100], 422);
        $receipt = $this->receipt($id, $client, 3000);
        $lines = JournalLine::where('journal_entry_id', $receipt['journal_entry_id'])->get();
        $credit = $lines->firstWhere('account_id', $this->roleId('client_advances'));
        $this->assertSame([$client->id, $id, '3000.0000'], [$credit->party_id, $credit->project_id, (string) $credit->credit]);

        $billing = $this->bill($id, 5000);
        $this->assertSame(['PB-2026-000001', 'approved'], [$billing['number'], $billing['status']]);
        $lines = JournalLine::where('journal_entry_id', $billing['journal_entry_id'])->get()->keyBy('account_id');
        $this->assertSame(['3000.0000', '2000.0000', '5000.0000'], [(string) $lines[$this->roleId('client_advances')]->debit,
            (string) $lines[$this->roleId('receivables_clients')]->debit, (string) $lines[$this->roleId('project_revenue')]->credit]);
        $this->assertSame([$client->id, $id], [$lines[$this->roleId('project_revenue')]->party_id, $lines[$this->roleId('project_revenue')]->project_id]);
        $this->assertSame(['0', '2000'], [$this->roleBalance('client_advances', 'USD', $client->id), $this->roleBalance('receivables_clients', 'USD', $client->id)]);

        // With no advances left the whole billing is a receivable.
        $second = $this->bill($id, 1000);
        $this->assertSame([$this->roleId('receivables_clients'), $this->roleId('project_revenue')],
            JournalLine::where('journal_entry_id', $second['journal_entry_id'])->orderBy('line_no')->pluck('account_id')->all());

        // The billing is in the contract currency and to the project's client.
        $this->voucher(['type' => 'project_billing', 'project_id' => $id, 'currency' => 'TRY', 'amount' => 100], 422);
        $this->voucher(['type' => 'project_billing', 'project_id' => $id, 'party_id' => $other->id, 'currency' => 'USD', 'amount' => 100], 422);
        $this->assertCount(2, $this->getJson("/api/v1/finance/projects/{$id}/billings")->assertOk()->json('data'));
    }

    public function test_contract_type_and_fee_are_locked_after_the_first_approved_billing_until_it_is_reversed(): void
    {
        $client = $this->party('عميل', ['client']);
        $project = $this->project(['contract_type' => 'cost_plus', 'fee_pct' => 10, 'client_party_id' => $client->id]);
        $id = $project['project_id'];
        $this->patchJson("/api/v1/finance/projects/{$id}", ['fee_pct' => 12])->assertOk()->assertJsonPath('data.billing_locked', false);
        $billing = $this->bill($id, 500);

        $this->patchJson("/api/v1/finance/projects/{$id}", ['fee_pct' => 8])->assertUnprocessable()->assertJsonValidationErrors('fee_pct');
        $this->patchJson("/api/v1/finance/projects/{$id}", ['contract_type' => 'percentage_of_receipts'])->assertUnprocessable();
        $this->patchJson("/api/v1/finance/projects/{$id}", ['fee_pct' => 12, 'name' => 'اسم جديد'])->assertOk()
            ->assertJsonPath('data.name', 'اسم جديد')->assertJsonPath('data.billing_locked', true);

        $this->postJson("/api/v1/finance/vouchers/{$billing['id']}/reverse", ['date' => '2026-11-13', 'reason' => 'مطالبة خاطئة'])->assertOk();
        $this->patchJson("/api/v1/finance/projects/{$id}", ['fee_pct' => 8, 'contract_type' => 'percentage_of_receipts'])->assertOk()
            ->assertJsonPath('data.contract_type', 'percentage_of_receipts');
        $this->assertSame('8.0000', (string) ProjectProfile::where('project_id', $id)->value('fee_pct'));
    }

    public function test_profitability_and_projects_summary(): void
    {
        $client = $this->party('عميل', ['client']);
        $project = $this->project(['name' => 'فيلا', 'contract_type' => 'cost_plus', 'fee_pct' => 10, 'client_party_id' => $client->id]);
        $id = $project['project_id'];
        $this->receipt($id, $client, 2000);
        $this->cost($id, 'materials', 1000);
        $this->cost($id, 'labor', 500);
        $this->loadedCost($id, 200);
        $this->loadedCost($id, 100, 'loaded_overhead');
        $this->bill($id, 1650);

        $p = $this->getJson("/api/v1/finance/projects/{$id}/profitability")->assertOk()->json('data');
        $this->assertSame(['USD', '1650', '1500', '300', '150', '-150', '2000', '350', '150'], [$p['currency'], $p['revenue'], $p['direct_costs'],
            $p['loaded_costs'], $p['gross_margin'], $p['net_margin'], $p['receipts'], $p['client_balance'], $p['fee']]);
        $this->assertSame(['materials' => '1000', 'labor' => '500', 'loaded_payroll' => '200', 'loaded_overhead' => '100'],
            collect($p['costs_by_category'])->pluck('amount', 'system_key')->all());

        $percentage = $this->project(['name' => 'شقق', 'contract_type' => 'percentage_of_receipts', 'fee_pct' => 8, 'client_party_id' => $client->id]);
        $this->receipt($percentage['project_id'], $client, 10000);
        $this->cost($percentage['project_id'], 'materials', 4000);

        $rows = collect($this->getJson('/api/v1/finance/projects/summary')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['2000', '1800', '200', '150', '-150'], [$rows['فيلا']['receipts'], $rows['فيلا']['costs'], $rows['فيلا']['remaining'],
            $rows['فيلا']['fee'], $rows['فيلا']['profit']]);
        $this->assertSame(['10000', '4000', '6000', '800'], [$rows['شقق']['receipts'], $rows['شقق']['costs'], $rows['شقق']['remaining'], $rows['شقق']['fee']]);
    }

    /** T9, D14: the client variant hides non-billable costs and the internal numbers. */
    public function test_statement_internal_and_client_variants(): void
    {
        $client = $this->party('عماد القاسم', ['client']);
        $project = $this->project(['name' => 'شقق', 'contract_type' => 'percentage_of_receipts', 'fee_pct' => 8, 'client_party_id' => $client->id]);
        $id = $project['project_id'];
        $this->receipt($id, $client, 2000);
        $this->receipt($id, $client, 25000);
        $this->cost($id, 'materials', 3000);
        $this->cost($id, 'labor', 1500);
        $this->loadedCost($id, 700);

        $internal = $this->getJson("/api/v1/finance/projects/{$id}/statement")->assertOk()->json('data');
        $this->assertSame(['100100', 'شقق', 'عماد القاسم', 'UFUQ'], [$internal['header']['statement_code'], $internal['header']['project'],
            $internal['header']['client'], $internal['header']['company']]);
        $this->assertCount(2, $internal['incoming']);
        $this->assertSame(['materials', 'labor', 'loaded_payroll'], array_column($internal['outgoing'], 'system_key'));
        $this->assertSame(['27000', '5200', '21800', '2160'], [$internal['totals']['incoming'], $internal['totals']['outgoing'],
            $internal['totals']['remaining'], $internal['totals']['fee']]);
        $this->assertArrayHasKey('profitability', $internal);
        $this->assertCount(6, $internal['signatures']);

        $customer = $this->getJson("/api/v1/finance/projects/{$id}/statement?variant=client")->assertOk()->json('data');
        $this->assertSame(['materials', 'labor'], array_column($customer['outgoing'], 'system_key'));
        $this->assertSame('4500', $customer['totals']['outgoing']);
        $this->assertArrayNotHasKey('fee', $customer['totals']);
        $this->assertArrayNotHasKey('profitability', $customer);

        $hidden = $this->getJson("/api/v1/finance/projects/{$id}/statement?hide_internal=1")->assertOk()->json('data');
        $this->assertSame(['materials', 'labor', 'loaded_payroll'], array_column($hidden['outgoing'], 'system_key'));
        $this->assertArrayNotHasKey('profitability', $hidden);
    }

    /** §14 item 11: a project manager sees only the projects he manages; a branch accountant works in his branch. */
    public function test_project_manager_sees_only_his_projects_and_branch_accountant_only_his_branch(): void
    {
        $client = $this->party('عميل', ['client']);
        $manager = $this->actor('project_manager');
        $mine = $this->project(['name' => 'مشروعي', 'manager_user_id' => $manager->id, 'client_party_id' => $client->id]);
        $other = $this->project(['name' => 'مشروع غيري', 'client_party_id' => $client->id]);

        $this->login($manager)->getJson('/api/v1/finance/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'مشروعي');
        $this->getJson("/api/v1/finance/projects/{$mine['project_id']}/profitability")->assertOk();
        $this->getJson("/api/v1/finance/projects/{$other['project_id']}")->assertNotFound();
        $this->getJson("/api/v1/finance/projects/{$other['project_id']}/statement")->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/v1/finance/projects/summary')->json('data'));
        $this->patchJson("/api/v1/finance/projects/{$mine['project_id']}", ['fee_pct' => 5])->assertForbidden();
        $this->postJson("/api/v1/finance/projects/{$mine['project_id']}/billings", ['amount' => 10])->assertForbidden();

        $idlib = Branch::create(['name' => 'فرع إدلب', 'code' => 'IDL']);
        $accountant = $this->actor('branch_accountant');
        UserBranch::create(['user_id' => $accountant->id, 'branch_id' => $idlib->id]);
        $this->login($accountant)->postJson('/api/v1/finance/projects', ['name' => 'في المركز', 'branch_id' => $this->hq->id, 'contract_type' => 'internal',
            'contract_currency_id' => $this->currencyId('USD')])->assertForbidden();
        $local = $this->postJson('/api/v1/finance/projects', ['name' => 'في إدلب', 'branch_id' => $idlib->id, 'contract_type' => 'internal',
            'contract_currency_id' => $this->currencyId('USD')])->assertCreated()->json('data');
        $this->getJson('/api/v1/finance/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.project_id', $local['project_id']);
        $this->getJson("/api/v1/finance/projects/{$mine['project_id']}")->assertNotFound();
        $this->patchJson("/api/v1/finance/projects/{$local['project_id']}", ['notes' => 'ملاحظة'])->assertOk();

        $this->login($this->director)->getJson('/api/v1/finance/projects')->assertOk()->assertJsonCount(3, 'data');
        $this->postJson('/api/v1/finance/projects', ['name' => 'x', 'contract_type' => 'internal', 'contract_currency_id' => $this->currencyId('USD')])
            ->assertForbidden();
    }
}
