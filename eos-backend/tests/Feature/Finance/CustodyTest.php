<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\Custody;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Settings\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\TestCase;

/** Custody (phase 6: §5.7, entries #19–#21, R15–R17, spec §14 item 8, §9.6 "عهدتي"). */
class CustodyTest extends TestCase
{
    use FinanceFixture, RefreshDatabase;

    private CashBox $safe;

    private Party $holder;

    private Party $supplier;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->safe = $this->box('Main safe', ['USD' => null, 'TRY' => null]);
        $this->fund($this->safe, 'USD', 5000);
        $this->holder = $this->party('أبو مراد', 'employee');
        $this->supplier = $this->party('مؤسسة البناء', 'supplier');
        $this->project = Project::create(['name' => 'شقق القاسم', 'status' => 'active', 'branch_id' => $this->hq->id]);
    }

    private function party(string $name, string $role, ?int $employeeId = null): Party
    {
        $party = Party::create(['name' => $name, 'employee_id' => $employeeId]);
        PartyRole::create(['party_id' => $party->id, 'role' => $role]);

        return $party;
    }

    private function issue(string|int $amount, array $extra = [], int $status = 201): ?array
    {
        return $this->voucher(['type' => 'custody_issue', 'party_id' => $this->holder->id, 'cash_box_id' => $this->safe->id, 'currency' => 'USD',
            'amount' => $amount, ...$extra], $status);
    }

    private function settle(int $custodyId, array $lines, int $status = 201): ?array
    {
        return $this->voucher(['type' => 'custody_settlement', 'custody_id' => $custodyId, 'currency' => 'USD', 'lines' => $lines], $status);
    }

    private function giveBack(int $custodyId, string|int $amount, int $status = 201): ?array
    {
        return $this->voucher(['type' => 'custody_return', 'custody_id' => $custodyId, 'cash_box_id' => $this->safe->id, 'currency' => 'USD',
            'amount' => $amount], $status);
    }

    private function fuelCategory(): ExpenseCategory
    {
        return ExpenseCategory::where('name', 'محروقات')->sole();
    }

    /** @return list<string> issued, settled, returned, balance, status */
    private function state(int $custodyId): array
    {
        $c = Custody::findOrFail($custodyId);

        return [rtrim(rtrim((string) $c->issued_total, '0'), '.'), rtrim(rtrim((string) $c->settled_total, '0'), '.'),
            rtrim(rtrim((string) $c->returned_total, '0'), '.'), rtrim(rtrim((string) $c->balance, '0'), '.'), $c->status];
    }

    public function test_issue_1000_settle_750_return_250_closes_the_custody(): void
    {
        $issued = $this->issue(1000, ['project_id' => $this->project->id]);
        $custodyId = $issued['custody_id'];
        $this->assertNotNull($custodyId);
        $line = JournalLine::where('journal_entry_id', $issued['journal_entry_id'])->where('account_id', $this->roleId('custody'))->sole();
        $this->assertSame([$custodyId, $this->holder->id, $this->project->id, '1000.0000'], [$line->custody_id, $line->party_id, $line->project_id, $line->debit]);
        $this->assertSame(['1000', '0', '0', '1000', 'open'], $this->state($custodyId));
        $this->assertSame('CU-2026-000001', $issued['number']);

        $materials = ProjectCostCategory::where('system_key', 'materials')->value('id');
        $settlement = $this->settle($custodyId, [
            ['project_id' => $this->project->id, 'project_cost_category_id' => $materials, 'party_id' => $this->supplier->id, 'debit' => 500, 'memo' => 'إسمنت'],
            ['expense_category_id' => $this->fuelCategory()->id, 'debit' => 250, 'memo' => 'مازوت'],
        ]);
        $this->assertSame(['1000', '750', '0', '250', 'open'], $this->state($custodyId));
        $lines = JournalLine::where('journal_entry_id', $settlement['journal_entry_id'])->get();
        $cost = $lines->firstWhere('account_id', $this->roleId('project_costs'));
        $this->assertSame([$this->project->id, $materials, $this->supplier->id, '500.0000'], [$cost->project_id, $cost->project_cost_category_id, $cost->party_id, $cost->debit]);
        $fuel = $lines->firstWhere('account_id', $this->accountId('6104'));
        $this->assertSame([$this->fuelCategory()->id, '250.0000'], [$fuel->expense_category_id, $fuel->debit]);
        $credit = $lines->firstWhere('account_id', $this->roleId('custody'));
        $this->assertSame([$custodyId, $this->holder->id, '750.0000'], [$credit->custody_id, $credit->party_id, $credit->credit]);
        $this->assertSame(['750', 'USD'], [rtrim(rtrim($settlement['amount'], '0'), '.'), 'USD']);

        $this->giveBack($custodyId, 300, 422);
        $this->giveBack($custodyId, 250);
        $this->assertSame(['1000', '750', '250', '0', 'closed'], $this->state($custodyId));
        $this->assertSame('2026-11-10', Custody::find($custodyId)->closed_on->toDateString());
        $this->assertSame('4250', $this->boxBalance($this->safe, 'USD'));
        $this->assertSame('0', $this->roleBalance('custody', 'USD', $this->holder->id));

        $shown = $this->getJson('/api/v1/finance/custodies/'.$custodyId)->assertOk()->json('data');
        $this->assertSame([['issue', '1000'], ['settlement', '750'], ['return', '250']], array_map(fn ($v) => [$v['kind'], $v['amount']], $shown['vouchers']));
        $this->assertCount(2, $shown['vouchers'][1]['lines']);
    }

    public function test_r15_an_open_custody_needs_confirmation_and_is_topped_up(): void
    {
        $first = $this->issue(1000);
        $this->issue(500, [], 422);
        $this->postJson('/api/v1/finance/vouchers', ['type' => 'custody_issue', 'party_id' => $this->holder->id, 'cash_box_id' => $this->safe->id,
            'currency' => 'USD', 'amount' => 500, 'voucher_date' => '2026-11-10'])->assertUnprocessable()->assertJsonValidationErrors('meta.confirm_existing');

        $topUp = $this->issue(500, ['meta' => ['confirm_existing' => true]]);
        $this->assertSame($first['custody_id'], $topUp['custody_id']);
        $this->assertSame(['1500', '0', '0', '1500', 'open'], $this->state($first['custody_id']));
        $payment = $this->voucher(['type' => 'payment', 'purpose' => 'custody', 'party_id' => $this->holder->id, 'cash_box_id' => $this->safe->id,
            'currency' => 'USD', 'amount' => 100, 'meta' => ['confirm_existing' => true]]);
        $this->assertSame($first['custody_id'], $payment['custody_id']);
        $this->assertSame(['1600', '0', '0', '1600', 'open'], $this->state($first['custody_id']));
        $this->assertSame(1, Custody::count());

        // Another project, or another currency, is another custody and needs no confirmation.
        $project = $this->issue(200, ['project_id' => $this->project->id]);
        $this->assertNotSame($first['custody_id'], $project['custody_id']);
        $this->assertSame(2, Custody::count());
    }

    public function test_r16_a_settlement_above_the_balance_is_refused(): void
    {
        $custodyId = $this->issue(100)['custody_id'];
        $response = $this->postJson('/api/v1/finance/vouchers', ['type' => 'custody_settlement', 'custody_id' => $custodyId, 'currency' => 'USD',
            'voucher_date' => '2026-11-10', 'approve' => true, 'lines' => [['expense_category_id' => $this->fuelCategory()->id, 'debit' => 150]]]);
        $response->assertUnprocessable();
        $this->assertStringContainsString('سجّل صرف عهدة إضافياً أولاً', $response->json('errors.lines.0'));
        $this->assertSame(['100', '0', '0', '100', 'open'], $this->state($custodyId));
    }

    public function test_settlement_lines_route_to_the_project_or_the_expense_category(): void
    {
        $custodyId = $this->issue(1000)['custody_id'];
        $this->settle($custodyId, [['debit' => 10]], 422);
        $this->settle($custodyId, [['project_id' => $this->project->id, 'debit' => 10]], 422);
        $this->settle($custodyId, [['expense_category_id' => $this->fuelCategory()->id, 'debit' => 10, 'currency' => 'TRY']], 422);
        $this->settle($custodyId, [['account_id' => $this->accountId('6104'), 'debit' => 10]], 422);
        $this->settle($custodyId, [], 422);

        $parent = ExpenseCategory::where('name', 'مصاريف تشغيلية')->sole();
        $this->assertNull($parent->account_id);
        $this->settle($custodyId, [['expense_category_id' => $parent->id, 'debit' => 10]], 422);
        $voucher = $this->settle($custodyId, [['project_id' => $this->project->id, 'project_cost_category_id' => ProjectCostCategory::where('system_key', 'misc')->value('id'),
            'debit' => 40]]);
        $line = JournalLine::where('journal_entry_id', $voucher['journal_entry_id'])->where('account_id', $this->roleId('project_costs'))->sole();
        $this->assertSame(ProjectCostCategory::where('system_key', 'misc')->value('id'), $line->project_cost_category_id);
        $this->assertSame(['1000', '40', '0', '960', 'open'], $this->state($custodyId));
    }

    public function test_reversal_recomputes_and_reopens_and_an_issue_already_spent_cannot_be_reversed(): void
    {
        $issued = $this->issue(1000);
        $custodyId = $issued['custody_id'];
        $returned = $this->giveBack($custodyId, 1000);
        $this->assertSame(['1000', '0', '1000', '0', 'closed'], $this->state($custodyId));

        $this->postJson("/api/v1/finance/vouchers/{$returned['id']}/reverse", ['date' => '2026-11-12', 'reason' => 'لم يُستلم المبلغ'])->assertOk();
        $this->assertSame(['1000', '0', '0', '1000', 'open'], $this->state($custodyId));
        $this->assertNull(Custody::find($custodyId)->closed_on);

        $this->settle($custodyId, [['expense_category_id' => $this->fuelCategory()->id, 'debit' => 300]]);
        $this->postJson("/api/v1/finance/vouchers/{$issued['id']}/reverse", ['date' => '2026-11-12', 'reason' => 'خطأ'])->assertUnprocessable();
        $this->assertSame(['1000', '300', '0', '700', 'open'], $this->state($custodyId));
        $this->assertSame('approved', $this->getJson("/api/v1/finance/vouchers/{$issued['id']}")->json('data.status'));
    }

    public function test_r17_overdue_custodies_are_notified_once_a_day(): void
    {
        $custodyId = $this->issue(1000)['custody_id'];
        $fresh = $this->holder;
        $this->holder = $this->party('معلم الورشة', 'other');
        $this->issue(100);
        $this->holder = $fresh;

        $this->artisan('finance:custody-overdue')->assertSuccessful();
        $this->assertSame(0, $this->finance->notifications()->count());

        $this->travelTo(now()->addDays(31));
        $this->artisan('finance:custody-overdue')->assertSuccessful();
        $this->artisan('finance:custody-overdue')->assertSuccessful();
        $this->assertSame(2, $this->finance->notifications()->count());
        $this->assertSame([$custodyId, 'finance.custody_overdue'], [$this->finance->notifications()->orderBy('created_at')->first()->data['custody_id'] ?? null,
            $this->finance->notifications()->first()->data['kind']]);
        $this->assertSame(0, $this->director->notifications()->count());

        $this->travelTo(now()->addDay());
        $this->artisan('finance:custody-overdue')->assertSuccessful();
        $this->assertSame(4, $this->finance->notifications()->count());

        $this->assertCount(2, $this->getJson('/api/v1/finance/custodies?overdue=1')->assertOk()->json('data'));
        $report = $this->getJson('/api/v1/finance/custodies/report')->assertOk()->json('data');
        $this->assertSame([37, true], [$report['custodies'][0]['age_days'], $report['custodies'][0]['overdue']]);
        $this->assertSame([['USD', '1100', 2]], array_map(fn ($t) => [$t['currency'], $t['balance'], $t['count']], $report['totals']));
    }

    public function test_my_custody_shows_only_the_supervisors_own(): void
    {
        $supervisor = $this->supervisor('E-1');
        $other = $this->supervisor('E-2');
        $mineId = $this->voucher(['type' => 'custody_issue', 'party_id' => $supervisor[1]->id, 'cash_box_id' => $this->safe->id, 'currency' => 'USD',
            'amount' => 400, 'project_id' => $this->project->id])['custody_id'];
        $this->voucher(['type' => 'custody_issue', 'party_id' => $other[1]->id, 'cash_box_id' => $this->safe->id, 'currency' => 'USD', 'amount' => 900]);
        $this->settle($mineId, [['expense_category_id' => $this->fuelCategory()->id, 'debit' => 150]]);

        $data = $this->login($supervisor[0])->getJson('/api/v1/finance/my-custody')->assertOk()->json('data');
        $this->assertSame($supervisor[1]->id, $data['party']['id']);
        $this->assertSame([$mineId], array_column($data['custodies'], 'id'));
        $this->assertSame([['currency' => 'USD', 'balance' => '250']], $data['balances']);
        $this->assertSame(['settlement', 'issue'], array_column($data['movements'], 'kind'));
        $this->assertSame('شقق القاسم', $data['custodies'][0]['project_name']);

        // The supervisor reads no other custody and approves nothing.
        $this->getJson('/api/v1/finance/custodies')->assertForbidden();
        $this->postJson('/api/v1/finance/vouchers', ['type' => 'custody_return', 'custody_id' => $mineId, 'cash_box_id' => $this->safe->id,
            'currency' => 'USD', 'amount' => 10, 'voucher_date' => '2026-11-10', 'approve' => true])->assertForbidden();
        $this->login($this->finance)->getJson('/api/v1/finance/my-custody')->assertOk()->assertJsonPath('data.party', null);
        $this->login($this->director)->getJson('/api/v1/finance/my-custody')->assertForbidden();
    }

    public function test_custody_lists_follow_the_branch_scope(): void
    {
        $this->issue(1000);
        $this->assertCount(1, $this->getJson('/api/v1/finance/custodies?status=open')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/finance/custodies?status=closed')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/finance/custodies?holder_party_id='.$this->holder->id)->json('data'));

        $branch = Branch::create(['name' => 'فرع إدلب', 'code' => 'IDL', 'is_head_office' => false, 'is_active' => true]);
        $accountant = $this->actor('branch_accountant');
        $this->putJson("/api/v1/finance/users/{$accountant->id}/branches", ['branch_ids' => [$branch->id]])->assertOk();
        $this->login($accountant)->getJson('/api/v1/finance/custodies')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/finance/custodies/'.Custody::first()->id)->assertForbidden();
        $this->assertSame([], $this->getJson('/api/v1/finance/custodies/report')->json('data.custodies'));
    }

    /** @return array{0: User, 1: Party} a site supervisor account linked to an employee and that employee's party */
    private function supervisor(string $number): array
    {
        $user = $this->actor('site_supervisor');
        $employee = Employee::create(['employee_no' => $number, 'first_name' => 'Sup', 'father_name' => 'F', 'last_name' => $number, 'user_id' => $user->id,
            'national_id' => $number.'-NID', 'national_id_hash' => hash('sha256', $number), 'hire_date' => '2026-01-01']);

        return [$user, $this->party('مشرف '.$number, 'employee', $employee->id)];
    }
}
