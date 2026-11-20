<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Services\Parties\PartyStatementService;
use App\Modules\Finance\Support\Money;
use App\Modules\HR\Models\Employee;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\TestCase;

/** Parties (phase 3): roles, HR link, partners and shares, partner deposits and drawings (D15), party statement (T12). */
class PartiesTest extends TestCase
{
    use FinanceFixture, RefreshDatabase;

    private CashBox $safe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->safe = $this->box('الخزنة', ['USD' => null, 'TRY' => null]);
        $this->fund($this->safe, 'USD', 10000);
        $this->fund($this->safe, 'TRY', 50000);
        $this->login($this->finance);
    }

    private function party(string $name, array $roles, array $extra = []): array
    {
        return $this->postJson('/api/v1/finance/parties', ['name' => $name, 'roles' => $roles, ...$extra])->assertCreated()->json('data');
    }

    private function employee(string $no): Employee
    {
        return Employee::create(['employee_no' => $no, 'first_name' => 'سامر', 'last_name' => 'خليل', 'national_id' => $no.'000',
            'national_id_hash' => hash('sha256', $no), 'hire_date' => '2026-01-01']);
    }

    public function test_parties_hold_several_roles_and_filter_by_role_search_and_activity(): void
    {
        $both = $this->party('مؤسسة النور', ['supplier', 'contractor'], ['phone' => '0933000111', 'trade' => 'كهرباء']);
        $this->party('شركة الأفق', ['client']);
        $this->assertSame(['contractor', 'supplier'], $both['roles']);
        // D11: no own tracking mode → the general setting applies.
        $this->assertSame([null, 'agreement'], [$both['contractor_tracking_mode'], $both['effective_tracking_mode']]);

        $this->assertSame(['مؤسسة النور'], collect($this->getJson('/api/v1/finance/parties?role=contractor')->assertOk()->json('data'))->pluck('name')->all());
        $this->assertSame(['شركة الأفق'], collect($this->getJson('/api/v1/finance/parties?role=client')->json('data'))->pluck('name')->all());
        $this->assertSame(['مؤسسة النور'], collect($this->getJson('/api/v1/finance/parties?search=0933')->json('data'))->pluck('name')->all());

        $updated = $this->patchJson('/api/v1/finance/parties/'.$both['id'], ['roles' => ['contractor'], 'contractor_tracking_mode' => 'payments_only',
            'sham_cash_wallet' => '1234567890123456', 'sham_cash_name' => 'محمد'])->assertOk()->json('data');
        $this->assertSame([['contractor'], 'payments_only', '1234567890123456'], [$updated['roles'], $updated['effective_tracking_mode'], $updated['sham_cash_wallet']]);
        $this->assertSame([], $this->getJson('/api/v1/finance/parties?role=supplier')->json('data'));

        $this->postJson('/api/v1/finance/parties', ['name' => 'بلا دور', 'roles' => []])->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->postJson('/api/v1/finance/parties', ['name' => 'دور خاطئ', 'roles' => ['boss']])->assertUnprocessable()->assertJsonValidationErrors('roles.0');

        // R4: a deactivated party drops out of the active list and takes no postings.
        $this->postJson('/api/v1/finance/parties/'.$both['id'].'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(['شركة الأفق'], collect($this->getJson('/api/v1/finance/parties?active=1')->json('data'))->pluck('name')->all());
        $this->voucher(['type' => 'payment', 'purpose' => 'debt', 'debt_direction' => 'owed_to_us', 'cash_box_id' => $this->safe->id, 'currency' => 'USD',
            'amount' => 10, 'party_id' => $both['id']], 422);
    }

    public function test_writing_parties_needs_the_vouchers_permission_and_reading_needs_finance_view(): void
    {
        $this->login($this->director)->getJson('/api/v1/finance/parties')->assertOk();
        $this->postJson('/api/v1/finance/parties', ['name' => 'x', 'roles' => ['client']])->assertForbidden();
        $this->login($this->actor('site_supervisor'))->getJson('/api/v1/finance/parties')->assertForbidden();
    }

    public function test_a_party_links_to_one_existing_hr_employee(): void
    {
        $employee = $this->employee('E-100');
        $party = $this->party('سامر خليل', ['employee'], ['employee_id' => $employee->id]);
        $this->assertSame($employee->id, $party['employee_id']);

        $this->postJson('/api/v1/finance/parties', ['name' => 'نسخة', 'roles' => ['employee'], 'employee_id' => $employee->id])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->postJson('/api/v1/finance/parties', ['name' => 'مجهول', 'roles' => ['employee'], 'employee_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->assertSame([$party['id']], collect($this->getJson('/api/v1/finance/parties?employee_id='.$employee->id)->json('data'))->pluck('id')->all());
    }

    public function test_csv_import_creates_parties_adds_missing_roles_and_rejects_a_bad_file_whole(): void
    {
        $this->party('مؤسسة النور', ['supplier'], ['phone' => '0933000111']);
        $csv = "\xEF\xBB\xBFname,phone,role,trade\nمؤسسة النور,0933000111,contractor,كهرباء\nأبو أحمد الدهان,0944000222,contractor,دهان\nشركة الأفق,,client|supplier,\n";
        $result = $this->post('/api/v1/finance/parties/import', ['file' => UploadedFile::fake()->createWithContent('parties.csv', $csv)])
            ->assertOk()->json('data');
        $this->assertSame(['created' => 2, 'updated' => 1, 'skipped' => 0], $result);
        $this->assertSame('دهان', Party::where('name', 'أبو أحمد الدهان')->value('trade'));
        $this->assertSame(['contractor', 'supplier'], collect($this->getJson('/api/v1/finance/parties?search=النور')->json('data.0.roles'))->all());

        $bad = "name,phone,role,trade\nجهة صحيحة,,client,\n,0999,client,\nجهة,,boss,\n";
        $this->post('/api/v1/finance/parties/import', ['file' => UploadedFile::fake()->createWithContent('bad.csv', $bad)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertFalse(Party::where('name', 'جهة صحيحة')->exists());
    }

    public function test_active_partner_shares_add_up_to_100_or_are_all_empty(): void
    {
        $manager = $this->party('المدير العام', ['employee']);
        $other = $this->party('شريك ثان', ['other']);

        // A single owner without a share; becoming a partner adds the role.
        $first = $this->postJson('/api/v1/finance/partners', ['party_id' => $manager['id'], 'joined_at' => '2026-01-01'])->assertCreated()->json('data');
        $this->assertContains('partner', $this->getJson('/api/v1/finance/parties/'.$manager['id'])->json('data.roles'));

        // A second partner with a share while the first has none → refused; with other_shares set together → accepted.
        $this->postJson('/api/v1/finance/partners', ['party_id' => $other['id'], 'share_pct' => 40])->assertUnprocessable()->assertJsonValidationErrors('share_pct');
        $this->postJson('/api/v1/finance/partners', ['party_id' => $other['id'], 'share_pct' => 40, 'other_shares' => [$first['id'] => 50]])
            ->assertUnprocessable();
        $second = $this->postJson('/api/v1/finance/partners', ['party_id' => $other['id'], 'share_pct' => 40, 'other_shares' => [$first['id'] => 60]])
            ->assertCreated()->json('data');
        $this->assertSame(['60.0000', '40.0000'], collect($this->getJson('/api/v1/finance/partners')->json('data'))->pluck('share_pct')->all());

        $this->patchJson('/api/v1/finance/partners/'.$second['id'], ['share_pct' => 30])->assertUnprocessable();
        // Leaving the partnership: the remaining active partner must then hold 100.
        $this->patchJson('/api/v1/finance/partners/'.$second['id'], ['is_active' => false])->assertUnprocessable();
        $this->patchJson('/api/v1/finance/partners/'.$second['id'], ['is_active' => false, 'other_shares' => [$first['id'] => 100]])->assertOk();
        // An active partner keeps the partner role.
        $this->patchJson('/api/v1/finance/parties/'.$manager['id'], ['roles' => ['employee']])->assertUnprocessable()->assertJsonValidationErrors('roles');
    }

    public function test_partner_deposit_credits_the_current_or_capital_account_chosen(): void
    {
        $partner = $this->party('المدير العام', ['partner']);
        $client = $this->party('عميل', ['client']);
        $deposit = fn (array $extra, int $status = 201) => $this->voucher(['type' => 'receipt', 'purpose' => 'partner_deposit', 'cash_box_id' => $this->safe->id,
            'currency' => 'USD', 'amount' => 2000, 'party_id' => $partner['id'], 'account_id' => $this->roleId('partners_current'), ...$extra], $status);

        $voucher = $deposit([]);
        $lines = JournalLine::where('journal_entry_id', $voucher['journal_entry_id'])->get();
        $this->assertSame([$this->safe->account_id, $this->roleId('partners_current')], [$lines->firstWhere('debit', '>', 0)->account_id, $lines->firstWhere('credit', '>', 0)->account_id]);
        $this->assertSame($partner['id'], $lines->firstWhere('credit', '>', 0)->party_id);
        $deposit(['amount' => 5000, 'account_id' => $this->roleId('partners_capital')]);
        $this->assertSame(['-2000', '-5000'], [$this->roleBalance('partners_current', 'USD', $partner['id']), $this->roleBalance('partners_capital', 'USD', $partner['id'])]);

        $deposit(['account_id' => $this->roleId('other_payables')], 422);
        $deposit(['party_id' => $client['id']], 422);
    }

    public function test_partner_drawing_is_classified_by_hand_as_personal_or_company_expense(): void
    {
        $partner = $this->party('المدير العام', ['partner']);
        $fuel = ExpenseCategory::where('name', 'محروقات')->value('id');
        $drawing = fn (array $extra, int $status = 201) => $this->voucher(['type' => 'payment', 'purpose' => 'partner_drawing', 'cash_box_id' => $this->safe->id,
            'currency' => 'USD', 'amount' => 300, 'party_id' => $partner['id'], ...$extra], $status);

        // D15: the classification is required.
        $drawing([], 422);

        // #11 personal drawing → the partner's current account.
        $personal = $drawing(['classification' => 'partner_personal']);
        $debit = JournalLine::where('journal_entry_id', $personal['journal_entry_id'])->where('debit', '>', 0)->first();
        $this->assertSame([$this->roleId('partners_current'), $partner['id']], [$debit->account_id, $debit->party_id]);

        // #12 company expense → like an internal expense, with "بطلب المدير" and the partner as party.
        $drawing(['classification' => 'company_expense'], 422);
        $expense = $drawing(['classification' => 'company_expense', 'expense_category_id' => $fuel, 'description' => 'وقود السيارة']);
        $debit = JournalLine::where('journal_entry_id', $expense['journal_entry_id'])->where('debit', '>', 0)->first();
        $this->assertSame([$this->accountId('6104'), $partner['id'], $fuel], [$debit->account_id, $debit->party_id, $debit->expense_category_id]);
        $this->assertStringStartsWith('بطلب المدير', $debit->memo);
        $this->assertSame(['300', '9400'], [$this->roleBalance('partners_current', 'USD', $partner['id']), $this->boxBalance($this->safe, 'USD')]);
    }

    public function test_party_statement_matches_the_journal_per_currency_with_project_and_date_filters(): void
    {
        $party = $this->party('كيف كافيه', ['supplier', 'other']);
        $project = Project::create(['name' => 'شقق القاسم', 'status' => 'active', 'branch_id' => $this->hq->id]);
        $materials = ProjectCostCategory::where('system_key', 'materials')->value('id');
        $base = ['cash_box_id' => $this->safe->id, 'party_id' => $party['id']];
        // We borrow 1000 USD (علينا), repay 400 on the project, lend 2000 TRY (لنا).
        $this->voucher([...$base, 'type' => 'receipt', 'purpose' => 'debt', 'debt_direction' => 'we_owe', 'currency' => 'USD', 'amount' => 1000, 'voucher_date' => '2026-11-02']);
        $this->voucher([...$base, 'type' => 'payment', 'purpose' => 'debt', 'debt_direction' => 'we_owe', 'currency' => 'USD', 'amount' => 400,
            'project_id' => $project->id, 'voucher_date' => '2026-11-05']);
        $this->voucher([...$base, 'type' => 'payment', 'purpose' => 'debt', 'debt_direction' => 'owed_to_us', 'currency' => 'TRY', 'amount' => 2000, 'voucher_date' => '2026-11-06']);
        // A project cost carrying the supplier as a dimension is not a balance with the party.
        $this->voucher([...$base, 'type' => 'payment', 'purpose' => 'supplier_payment', 'currency' => 'USD', 'amount' => 150, 'project_id' => $project->id,
            'project_cost_category_id' => $materials, 'voucher_date' => '2026-11-07']);

        $statement = $this->getJson('/api/v1/finance/parties/'.$party['id'].'/statement')->assertOk()->json('data.currencies');
        $byCode = collect($statement)->keyBy('code');
        $this->assertSame(['TRY', 'USD'], array_keys($byCode->all()));
        $this->assertSame(['-600', 'علينا', 2], [$byCode['USD']['closing'], $byCode['USD']['closing_side'], count($byCode['USD']['lines'])]);
        $this->assertSame(['-1000', '-600'], array_column($byCode['USD']['lines'], 'balance'));
        $this->assertSame(['2000', 'لنا'], [$byCode['TRY']['closing'], $byCode['TRY']['closing_side']]);

        // Phase 3 acceptance: the statement equals the sum of the journal lines on the party's balance accounts.
        $accounts = app(PartyStatementService::class)->accountIds();
        foreach (['USD', 'TRY'] as $code) {
            $journal = JournalLine::where('party_id', $party['id'])->whereIn('account_id', $accounts)->where('currency_id', $this->currencyId($code))->get();
            $this->assertSame(Money::sub(Money::sum($journal->pluck('debit')), Money::sum($journal->pluck('credit'))), $byCode[$code]['closing']);
        }

        // Filters: opening balance before `from`, a single currency, one project.
        $fromSixth = $this->getJson('/api/v1/finance/parties/'.$party['id'].'/statement?currency=USD&from=2026-11-05')->json('data.currencies');
        $this->assertSame([['USD', '-1000', 1, '-600']], array_map(fn ($c) => [$c['code'], $c['opening'], count($c['lines']), $c['closing']], $fromSixth));
        $projectOnly = $this->getJson('/api/v1/finance/parties/'.$party['id'].'/statement?currency=USD&project_id='.$project->id)->json('data.currencies.0');
        $this->assertSame(['400', 'لنا'], [$projectOnly['closing'], $projectOnly['closing_side']]);

        // Party card: balances per currency.
        $balances = collect($this->getJson('/api/v1/finance/parties/'.$party['id'])->json('data.balances'))->keyBy('code');
        $this->assertSame(['-600', 'علينا', '2000', 'لنا'], [$balances['USD']['balance'], $balances['USD']['side'], $balances['TRY']['balance'], $balances['TRY']['side']]);
    }
}
