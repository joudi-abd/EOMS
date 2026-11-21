<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\TestCase;

/** Expenses (phase 4): the category tree (D21) and expense vouchers (#8): multi-line and the internal-expense payment. */
class ExpensesTest extends TestCase
{
    use FinanceFixture, RefreshDatabase;

    private CashBox $safe;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->safe = $this->box('الخزنة', ['USD' => null, 'TRY' => null]);
        $this->fund($this->safe, 'USD', 5000);
        $this->project = Project::create(['name' => 'فيلا الشام', 'status' => 'active', 'branch_id' => $this->hq->id]);
        $this->login($this->finance);
    }

    private function category(string $name): int
    {
        return ExpenseCategory::where('name', $name)->value('id');
    }

    private function costCategory(string $key): int
    {
        return ProjectCostCategory::where('system_key', $key)->value('id');
    }

    private function asset(): Asset
    {
        return Asset::create(['code' => 'AS-9001', 'name' => 'مولدة', 'currency_id' => $this->currencyId('USD'), 'branch_id' => $this->hq->id]);
    }

    public function test_category_tree_requires_an_expense_account_on_leaves_and_guards_its_shape(): void
    {
        $tree = collect($this->getJson('/api/v1/finance/expense-categories')->assertOk()->json('data'))->keyBy('name');
        $operating = $tree['مصاريف تشغيلية'];
        $this->assertSame([false, 8], [$operating['is_leaf'], count($operating['children'])]);
        $this->assertSame('6104', collect($operating['children'])->firstWhere('name', 'محروقات')['account_code']);

        $parentId = $operating['id'];
        $this->postJson('/api/v1/finance/expense-categories', ['name' => 'صيانة شركة', 'parent_id' => $parentId])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
        // The account must be a postable expense account: an asset account or a group is refused.
        $this->postJson('/api/v1/finance/expense-categories', ['name' => 'صيانة شركة', 'parent_id' => $parentId, 'account_id' => $this->accountId('1300')])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson('/api/v1/finance/expense-categories', ['name' => 'صيانة شركة', 'parent_id' => $parentId, 'account_id' => $this->accountId('6100')])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $leaf = $this->postJson('/api/v1/finance/expense-categories', ['name' => 'صيانة شركة', 'parent_id' => $parentId,
            'account_id' => $this->accountId('6106'), 'is_overhead_allocatable' => true])->assertCreated()->json('data');
        $this->assertTrue($leaf['is_overhead_allocatable']);

        // A parent with active children is not deactivated; a category is not its own ancestor.
        $this->postJson('/api/v1/finance/expense-categories/'.$parentId.'/deactivate')->assertUnprocessable();
        $this->patchJson('/api/v1/finance/expense-categories/'.$parentId, ['parent_id' => $leaf['id']])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/v1/finance/expense-categories/'.$leaf['id'].'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);

        // A category with postings gets no children.
        $this->voucher(['type' => 'payment', 'purpose' => 'internal_expense', 'cash_box_id' => $this->safe->id, 'currency' => 'USD', 'amount' => 20,
            'expense_category_id' => $this->category('طعام')]);
        $this->postJson('/api/v1/finance/expense-categories', ['name' => 'فطور', 'parent_id' => $this->category('طعام'), 'account_id' => $this->accountId('6102')])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        // Categories are settings: readers cannot write them.
        $this->login($this->director)->getJson('/api/v1/finance/expense-categories')->assertOk();
        $this->postJson('/api/v1/finance/expense-categories', ['name' => 'x', 'account_id' => $this->accountId('6102')])->assertForbidden();
    }

    public function test_expense_voucher_routes_each_line_by_category_project_and_asset_and_credits_the_box_once(): void
    {
        $asset = $this->asset();
        $voucher = $this->voucher(['type' => 'expense', 'cash_box_id' => $this->safe->id, 'currency' => 'USD', 'description' => 'مصاريف الأسبوع', 'lines' => [
            ['expense_category_id' => $this->category('بوفيه وضيافة'), 'debit' => 40, 'memo' => 'قهوة'],
            ['expense_category_id' => $this->category('محروقات'), 'asset_id' => $asset->id, 'debit' => 60],
            ['project_id' => $this->project->id, 'project_cost_category_id' => $this->costCategory('materials'), 'debit' => 250],
            ['expense_category_id' => $this->category('محروقات'), 'project_id' => $this->project->id, 'asset_id' => $asset->id, 'debit' => 50],
        ]]);
        $this->assertSame(['EX-2026-000001', '400.0000'], [$voucher['number'], $voucher['amount']]);

        $lines = JournalLine::where('journal_entry_id', $voucher['journal_entry_id'])->orderBy('line_no')->get();
        $this->assertCount(5, $lines);
        $shape = $lines->map(fn ($l) => [$l->account_id, $l->project_id, $l->project_cost_category_id, $l->expense_category_id, $l->asset_id, (string) $l->debit, (string) $l->credit])->all();
        $this->assertEqualsCanonicalizing([
            [$this->accountId('6101'), null, null, $this->category('بوفيه وضيافة'), null, '40.0000', '0.0000'],
            [$this->accountId('6104'), null, null, $this->category('محروقات'), $asset->id, '60.0000', '0.0000'],
            [$this->roleId('project_costs'), $this->project->id, $this->costCategory('materials'), null, null, '250.0000', '0.0000'],
            // With a project and an asset but no cost category: the asset_operation cost category.
            [$this->roleId('project_costs'), $this->project->id, $this->costCategory('asset_operation'), $this->category('محروقات'), $asset->id, '50.0000', '0.0000'],
            [$this->safe->account_id, null, null, null, null, '0.0000', '400.0000'],
        ], $shape);
        $this->assertSame('4600', $this->boxBalance($this->safe, 'USD'));
    }

    public function test_expense_voucher_lines_are_checked(): void
    {
        $fuel = $this->category('محروقات');
        $expense = fn (array $lines) => $this->voucher(['type' => 'expense', 'cash_box_id' => $this->safe->id, 'currency' => 'USD', 'lines' => $lines], 422);

        $expense([]);
        // Lines in the box currency only.
        $expense([['expense_category_id' => $fuel, 'debit' => 10, 'currency' => 'TRY']]);
        // A group category is not used for posting.
        $expense([['expense_category_id' => $this->category('مصاريف تشغيلية'), 'debit' => 10]]);
        // Without a project the category is required; with one, the cost category (or an asset).
        $expense([['debit' => 10]]);
        $expense([['project_id' => $this->project->id, 'debit' => 10]]);
        $expense([['expense_category_id' => $fuel, 'debit' => 0]]);
        // A closed project takes no costs (R5).
        $this->project->update(['status' => 'closed']);
        $expense([['project_id' => $this->project->id, 'project_cost_category_id' => $this->costCategory('materials'), 'debit' => 10]]);
        $this->assertSame('5000', $this->boxBalance($this->safe, 'USD'));
    }

    public function test_internal_expense_payment_is_the_single_line_expense(): void
    {
        $asset = $this->asset();
        $plain = $this->voucher(['type' => 'payment', 'purpose' => 'internal_expense', 'cash_box_id' => $this->safe->id, 'currency' => 'USD', 'amount' => 75,
            'expense_category_id' => $this->category('كهرباء وإنترنت')]);
        $debit = JournalLine::where('journal_entry_id', $plain['journal_entry_id'])->where('debit', '>', 0)->first();
        $this->assertSame([$this->accountId('6103'), $this->category('كهرباء وإنترنت')], [$debit->account_id, $debit->expense_category_id]);

        $onProject = $this->voucher(['type' => 'payment', 'purpose' => 'internal_expense', 'cash_box_id' => $this->safe->id, 'currency' => 'USD', 'amount' => 30,
            'expense_category_id' => $this->category('محروقات'), 'project_id' => $this->project->id, 'asset_id' => $asset->id]);
        $debit = JournalLine::where('journal_entry_id', $onProject['journal_entry_id'])->where('debit', '>', 0)->first();
        $this->assertSame([$this->roleId('project_costs'), $this->project->id, $this->costCategory('asset_operation'), $asset->id],
            [$debit->account_id, $debit->project_id, $debit->project_cost_category_id, $debit->asset_id]);

        $this->voucher(['type' => 'payment', 'purpose' => 'internal_expense', 'cash_box_id' => $this->safe->id, 'currency' => 'USD', 'amount' => 30], 422);
        $this->assertSame('4895', $this->boxBalance($this->safe, 'USD'));
    }
}
