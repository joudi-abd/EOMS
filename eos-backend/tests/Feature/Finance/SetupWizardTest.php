<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\ContractorAgreement;
use App\Modules\Finance\Models\Custody;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Projects\ContractorAgreementService;
use App\Modules\Finance\Support\Money;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Database\Seeders\BranchSeeder;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use App\Modules\Settings\Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\Feature\Finance\Concerns\ProjectFixture;
use Tests\TestCase;

/** Setup wizard (spec §12, phase 11): steps, starter chart, opening balances in one voucher, the opening check, and the closing screen. */
class SetupWizardTest extends TestCase
{
    use FinanceFixture, ProjectFixture, RefreshDatabase;

    public function test_the_starter_chart_is_created_only_on_an_empty_chart(): void
    {
        $this->seed([RolePermissionSeeder::class, CurrencySeeder::class, BranchSeeder::class, SettingsSeeder::class]);
        $accountant = User::factory()->create();
        $accountant->assignRole('finance_manager');
        Sanctum::actingAs($accountant);

        $chart = collect($this->getJson('/api/v1/finance/setup/status')->assertOk()->json('data.steps'))->firstWhere('key', 'chart');
        $this->assertFalse($chart['done']);
        $this->assertTrue($chart['details']['starter_chart_available']);

        $created = $this->postJson('/api/v1/finance/setup/starter-chart')->assertCreated()->json('data');
        $this->assertSame(0, $created['system_accounts']['unmapped_count']);
        $this->assertTrue(collect($this->getJson('/api/v1/finance/setup/status')->json('data.steps'))->firstWhere('key', 'chart')['done']);
        $this->postJson('/api/v1/finance/setup/starter-chart')->assertConflict();

        $branchAccountant = User::factory()->create();
        $branchAccountant->assignRole('branch_accountant');
        Sanctum::actingAs($branchAccountant);
        $this->postJson('/api/v1/finance/setup/starter-chart')->assertForbidden();
        $this->assertGreaterThan(0, Account::query()->count());
    }

    public function test_opening_balances_post_as_one_voucher_and_leave_every_statement_right(): void
    {
        $this->setUpFinance();
        $this->cash = $this->box('الخزنة', ['USD' => null, 'SYP' => null]);
        $client = $this->party('عماد القاسم', ['client']);
        $contractor = $this->party('أحمد جمو', ['contractor']);
        $debtor = $this->party('كيف كافيه', ['other']);
        $holder = $this->party('أبو مراد', ['other']);
        $partner = $this->party('المدير العام', ['partner']);
        $project = $this->project(['name' => 'شقق القاسم', 'client_party_id' => $client->id, 'contract_type' => 'percentage_of_receipts', 'fee_pct' => 8]);
        $agreement = $this->postJson('/api/v1/finance/contractor-agreements', ['project_id' => $project['project_id'], 'contractor_party_id' => $contractor->id,
            'scope' => 'تمديد صحية', 'currency_id' => $this->currencyId('USD'), 'agreed_value' => 10000])->assertCreated()->json('data');
        $employee = Employee::create(['employee_no' => 'E-1', 'first_name' => 'سامر', 'last_name' => 'خليل', 'national_id' => '1000',
            'national_id_hash' => hash('sha256', 'E-1'), 'hire_date' => '2025-01-01']);

        $payload = [
            'date' => '2026-09-30',
            'boxes' => [['cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 25000], ['cash_box_id' => $this->cash->id, 'currency' => 'SYP', 'amount' => 13000000]],
            'projects' => [['project_id' => $project['project_id'], 'receipts' => 141500, 'billed' => 100000,
                'costs' => [['project_cost_category_id' => $this->categoryId('materials'), 'amount' => 60000], ['project_cost_category_id' => $this->categoryId('design'), 'amount' => 5000]]]],
            'agreements' => [['contractor_agreement_id' => $agreement['id'], 'paid' => 7810]],
            'parties' => [['party_id' => $debtor->id, 'currency' => 'USD', 'direction' => 'owed_to_us', 'amount' => 400]],
            'employee_advances' => [['employee_id' => $employee->id, 'currency' => 'SYP', 'remaining' => 1000000, 'installments_count' => 3, 'start_month' => '2026-10']],
            'custodies' => [['holder_party_id' => $holder->id, 'project_id' => $project['project_id'], 'currency' => 'USD', 'balance' => 250, 'opened_on' => '2026-09-20']],
            'partners' => [['party_id' => $partner->id, 'currency' => 'USD', 'direction' => 'owed_to_us', 'amount' => 3000]],
        ];
        $result = $this->postJson('/api/v1/finance/setup/opening-balances', $payload)->assertCreated()->json('data');

        $voucher = Voucher::findOrFail($result['voucher']['id']);
        $this->assertSame(['opening_balance', 'approved', 1], [$voucher->type, $voucher->status,
            Voucher::where('type', 'opening_balance')->count()], 'everything but the billing in one opening voucher');
        $this->assertSame('100000', Money::of($result['billings'][0]['amount']));
        $this->assertSame(['25000', '13000000'], [$this->boxBalance($this->cash, 'USD'), $this->boxBalance($this->cash, 'SYP')]);

        $profit = $this->getJson("/api/v1/finance/projects/{$project['project_id']}/profitability?date=2026-09-30")->assertOk()->json('data');
        $this->assertSame(['141500', '100000', '72810', '11320', '41500'], [Money::of($profit['receipts']), Money::of($profit['billed']),
            Money::of($profit['total_costs']), Money::of($profit['fee']), Money::of($profit['client_balance'])],
            'receipts, billed, costs (incl. the contractor), the 8% fee of §6.2 and the client balance start right');
        $this->assertSame('7810', app(ContractorAgreementService::class)->grossPaid(ContractorAgreement::findOrFail($agreement['id'])));

        $custody = Custody::findOrFail($result['custody_ids'][0]);
        $this->assertSame(['open', '250', '250'], [$custody->status, Money::of((string) $custody->issued_total), Money::of((string) $custody->balance)]);
        $advance = EmployeeAdvance::with('installments')->findOrFail($result['employee_advance_ids'][0]);
        $this->assertSame(['2026-10', '2026-11', '2026-12'], $advance->installments->pluck('due_month')->all());
        $this->assertSame('1000000', Money::sum($advance->installments->map(fn ($i) => (string) $i->amount)));
        $this->assertSame('1000000', $this->roleBalance('employee_advances', 'SYP'));
        $this->assertSame('-400', Money::neg($this->roleBalance('other_receivables', 'USD')));
        $this->assertSame('3000', $this->roleBalance('partners_current', 'USD', $partner->id));

        // Step 8: balanced per currency and the opening equity equals net assets less other equity and profit to date.
        $check = $this->getJson('/api/v1/finance/setup/opening-check')->assertOk()->json('data');
        $this->assertSame('2026-09-30', $check['date']);
        $this->assertTrue($check['balanced']);
        $this->assertTrue($check['equity_matches']);
        $usd = collect($check['currencies'])->firstWhere('currency', 'USD');
        $this->assertSame(Money::neg($this->roleBalance('opening_balance_equity', 'USD')), Money::of($usd['opening_balance_equity']));
        $steps = collect($this->getJson('/api/v1/finance/setup/status')->json('data.steps'))->keyBy('key');
        $this->assertTrue($steps['opening_balances']['done']);
        $this->assertTrue($steps['opening_check']['done']);
        $this->assertSame('2026-09-30', $steps['opening_balances']['details']['start_date']);

        // One wizard voucher per branch.
        $this->postJson('/api/v1/finance/setup/opening-balances', ['date' => '2026-09-30', 'boxes' => [['cash_box_id' => $this->cash->id, 'currency' => 'USD',
            'amount' => 1]]])->assertConflict();

        // Reversing the opening voucher closes the custody it opened and cancels the advance.
        $this->postJson("/api/v1/finance/vouchers/{$voucher->id}/reverse", ['date' => '2026-10-01', 'reason' => 'إعادة الإدخال'])->assertOk();
        $this->assertSame('closed', $custody->refresh()->status);
        $this->assertSame('cancelled', $advance->refresh()->status);
    }

    public function test_opening_balances_are_validated_against_the_branch_and_the_parties(): void
    {
        $this->setUpFinance();
        $this->cash = $this->box('الخزنة', ['USD' => null]);
        $internal = $this->project(['name' => 'مكاتب', 'contract_type' => 'internal']);
        $plain = $this->party('ليس شريكاً', ['other']);

        $this->postJson('/api/v1/finance/setup/opening-balances', ['date' => '2026-09-30'])->assertUnprocessable()->assertJsonValidationErrors('boxes');
        $this->postJson('/api/v1/finance/setup/opening-balances', ['date' => '2026-09-30', 'boxes' => [['cash_box_id' => $this->cash->id, 'currency' => 'TRY',
            'amount' => 5]]])->assertUnprocessable()->assertJsonValidationErrors('boxes.0.currency');
        $this->postJson('/api/v1/finance/setup/opening-balances', ['date' => '2026-09-30', 'projects' => [['project_id' => $internal['project_id'],
            'billed' => 10]]])->assertUnprocessable()->assertJsonValidationErrors('projects.0.billed');
        $this->postJson('/api/v1/finance/setup/opening-balances', ['date' => '2026-09-30', 'partners' => [['party_id' => $plain->id, 'currency' => 'USD',
            'direction' => 'we_owe', 'amount' => 10]]])->assertUnprocessable()->assertJsonValidationErrors('partners.0.party_id');
        $this->assertSame(0, Voucher::count(), 'nothing is left behind by a refused request');
        $this->assertSame(0, Custody::count());

        $this->login($this->director)->postJson('/api/v1/finance/setup/opening-balances', ['date' => '2026-09-30'])->assertForbidden();
        $this->getJson('/api/v1/finance/setup/status')->assertOk();
    }

    public function test_the_closing_screen_lists_months_with_their_checklist(): void
    {
        $this->setUpFinance();
        $this->cash = $this->box('الخزنة', ['USD' => null]);
        $this->fund($this->cash, 'USD', 100, '2026-10-05');
        $this->postJson('/api/v1/finance/vouchers', ['type' => 'opening_balance', 'voucher_date' => '2026-10-07',
            'lines' => [['cash_box_id' => $this->cash->id, 'currency' => 'USD', 'debit' => 5]]])->assertCreated();

        $months = collect($this->getJson('/api/v1/finance/periods/closing')->assertOk()->json('data'))->keyBy('month');
        $this->assertSame(['2026-11', '2026-10'], $months->keys()->all());
        $october = $months['2026-10'];
        $this->assertFalse($october['can_close']);
        $checks = collect($october['checklist'])->keyBy('key');
        $this->assertSame([false, 1], [$checks['pending_vouchers']['passed'], $checks['pending_vouchers']['count']]);
        foreach (['site_requests', 'cash_counts', 'allocation_payroll', 'allocation_overhead'] as $key) {
            $this->assertArrayHasKey($key, $checks->all());
        }
        $this->assertTrue($checks->keys()->contains(fn ($k) => str_starts_with($k, 'module:')), 'the payroll module check is listed');
        $this->assertSame($october['blockers'], count($this->getJson('/api/v1/finance/periods/2026-10/checks')->json('data.blockers')));
        $this->login($this->director)->getJson('/api/v1/finance/periods/closing')->assertForbidden();
    }
}
