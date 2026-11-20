<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\ProjectBudgetLine;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\Feature\Finance\Concerns\ProjectFixture;
use Tests\TestCase;

/** Project budgets (phase 5): budget lines (D10), overrun alerts (R13) and currency conversion (OP-6). */
class BudgetTest extends TestCase
{
    use FinanceFixture, ProjectFixture, RefreshDatabase;

    private int $projectId;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->setUpProjects();
        $this->manager = $this->actor('project_manager');
        $client = $this->party('عميل', ['client']);
        $this->projectId = $this->project(['name' => 'شقق', 'client_party_id' => $client->id, 'manager_user_id' => $this->manager->id])['project_id'];
    }

    private function budget(string $category, string $currency, string|int $amount): array
    {
        return $this->login($this->finance)->postJson("/api/v1/finance/projects/{$this->projectId}/budget-lines", [
            'project_cost_category_id' => $this->categoryId($category), 'currency_id' => $this->currencyId($currency), 'budget_amount' => $amount,
        ])->assertCreated()->json('data');
    }

    private function alerts(User $user, string $level): int
    {
        return $user->notifications()->get()->where('data.kind', 'finance.budget_'.$level)->count();
    }

    /** R13: warning at the threshold (90%), exceeded above 100%, notified once per level; never blocks; reversals lower it silently. */
    public function test_overrun_alerts_are_sent_once_per_level_and_never_block_posting(): void
    {
        $line = $this->budget('materials', 'USD', 1000);
        $this->assertSame([], $this->cost($this->projectId, 'materials', 500)['warnings']);
        $this->cost($this->projectId, 'labor', 5000); // another category: no budget, no alert
        $this->assertSame('none', ProjectBudgetLine::find($line['id'])->alert_level);

        $warned = $this->cost($this->projectId, 'materials', 450); // 950 = 95%
        $this->assertSame('approved', $warned['status']);
        $this->assertCount(1, $warned['warnings']);
        $this->assertSame([1, 1, 'warning'], [$this->alerts($this->finance, 'warning'), $this->alerts($this->manager, 'warning'),
            ProjectBudgetLine::find($line['id'])->alert_level]);

        // Still in the warning band: the voucher warns, nobody is notified again.
        $this->assertCount(1, $this->cost($this->projectId, 'materials', 10)['warnings']);
        $this->assertSame(1, $this->alerts($this->finance, 'warning'));

        $exceeded = $this->cost($this->projectId, 'materials', 100); // 1,060 > 100%
        $this->assertSame('approved', $exceeded['status']);
        $this->assertStringContainsString('تجاوزت', $exceeded['warnings'][0]);
        $this->cost($this->projectId, 'materials', 10);
        $this->assertSame([1, 1, 1, 'exceeded'], [$this->alerts($this->finance, 'exceeded'), $this->alerts($this->manager, 'exceeded'),
            $this->alerts($this->finance, 'warning'), ProjectBudgetLine::find($line['id'])->alert_level]);
        $this->assertSame(0, $this->alerts($this->director, 'exceeded')); // no finance.vouchers

        // Reversing the 100 brings it back to 970 = 97%: stored silently.
        $this->postJson("/api/v1/finance/vouchers/{$exceeded['id']}/reverse", ['date' => '2026-11-12', 'reason' => 'خطأ'])->assertOk();
        $this->assertSame(['warning', 1, 1], [ProjectBudgetLine::find($line['id'])->alert_level, $this->alerts($this->finance, 'warning'),
            $this->alerts($this->finance, 'exceeded')]);

        $row = collect($this->getJson("/api/v1/finance/projects/{$this->projectId}/budget")->assertOk()->json('data'))->firstWhere('id', $line['id']);
        $this->assertSame(['970', '30', '97', 'warning', 'amber'], [$row['actual'], $row['remaining'], $row['pct'], $row['level'], $row['colour']]);
    }

    /** OP-6: costs in another currency are measured in the budget's currency (base amounts × its bulletin rate). */
    public function test_costs_in_other_currencies_are_converted_to_the_budget_currency(): void
    {
        $line = $this->budget('labor', 'TRY', 43400); // = 1,000 USD at 43.4
        $this->cost($this->projectId, 'labor', 900); // 39,060 TRY
        $this->cost($this->projectId, 'labor', 1000, 'TRY'); // face value
        $row = collect($this->getJson("/api/v1/finance/projects/{$this->projectId}/budget")->json('data'))->firstWhere('id', $line['id']);
        $this->assertSame(['40060', '92.3', 'warning', 'amber'], [$row['actual'], $row['pct'], $row['level'], $row['colour']]);
        $this->assertSame('warning', ProjectBudgetLine::find($line['id'])->alert_level);

        $usd = $this->budget('design', 'USD', 100);
        $exceeded = $this->cost($this->projectId, 'design', 4774, 'TRY'); // 110 USD
        $this->assertCount(1, $exceeded['warnings']);
        $this->assertSame(['exceeded', 'red'], [ProjectBudgetLine::find($usd['id'])->alert_level,
            collect($this->getJson("/api/v1/finance/projects/{$this->projectId}/budget")->json('data'))->firstWhere('id', $usd['id'])['colour']]);
    }

    public function test_budget_lines_are_unique_per_category_and_currency_and_written_by_finance_only(): void
    {
        $line = $this->budget('materials', 'USD', 1000);
        $this->postJson("/api/v1/finance/projects/{$this->projectId}/budget-lines", ['project_cost_category_id' => $this->categoryId('materials'),
            'currency_id' => $this->currencyId('USD'), 'budget_amount' => 5])->assertUnprocessable();
        $this->budget('materials', 'TRY', 1000);

        $this->cost($this->projectId, 'materials', 950);
        $this->assertSame('warning', ProjectBudgetLine::find($line['id'])->alert_level);
        // A larger budget lowers the level without notifying.
        $this->patchJson("/api/v1/finance/budget-lines/{$line['id']}", ['budget_amount' => 5000])->assertOk()->assertJsonPath('data.alert_level', 'none');

        $this->login($this->manager)->getJson("/api/v1/finance/projects/{$this->projectId}/budget")->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/finance/projects/{$this->projectId}/budget-lines", ['project_cost_category_id' => $this->categoryId('labor'),
            'currency_id' => $this->currencyId('USD'), 'budget_amount' => 5])->assertForbidden();
        $this->login($this->finance)->deleteJson("/api/v1/finance/budget-lines/{$line['id']}")->assertOk();
        $this->assertNull(ProjectBudgetLine::find($line['id']));
    }
}
