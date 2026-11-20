<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\AccountBalance;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\SystemAccount;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\EntryDraft;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Core\LedgerService;
use App\Modules\Payroll\Models\PayRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\TestCase;

/** The posting engine (phase 1): per-currency balance, bulletin rates, periods, reversal, numbering, balances, chart, roles, attachments, audit. */
class CoreLedgerTest extends TestCase
{
    use FinanceFixture, RefreshDatabase;

    private CashBox $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->cash = $this->box('Main safe', ['USD' => null, 'SYP' => null, 'TRY' => null]);
    }

    public function test_an_entry_unbalanced_in_any_currency_is_refused_whole(): void
    {
        $rent = $this->accountId('6101');
        $equity = $this->roleId('opening_balance_equity');
        // Balanced in USD, unbalanced in TRY: nothing posts (§14.1, R2).
        $response = $this->voucher(['type' => 'manual_journal', 'lines' => [
            ['account_id' => $rent, 'currency' => 'USD', 'debit' => 100], ['account_id' => $equity, 'currency' => 'USD', 'credit' => 100],
            ['account_id' => $rent, 'currency' => 'TRY', 'debit' => 500], ['account_id' => $equity, 'currency' => 'TRY', 'credit' => 499],
        ]], 422);
        $this->assertSame(['القيد غير متوازن في العملة TRY.'], $this->postJson('/api/v1/finance/vouchers', ['type' => 'manual_journal', 'voucher_date' => '2026-11-10',
            'approve' => true, 'lines' => [
                ['account_id' => $rent, 'currency' => 'USD', 'debit' => 100], ['account_id' => $equity, 'currency' => 'USD', 'credit' => 100],
                ['account_id' => $rent, 'currency' => 'TRY', 'debit' => 500], ['account_id' => $equity, 'currency' => 'TRY', 'credit' => 499],
            ]])->json('errors.lines'));
        $this->assertNull($response);
        $this->assertSame(0, Voucher::where('type', 'manual_journal')->count(), 'approve: true rolls the draft back too');
        $this->assertSame(0, JournalLine::where('account_id', $rent)->count());

        // The engine never balances by itself, even when called directly.
        $this->expectException(ValidationException::class);
        app(LedgerService::class)->postEntry(new EntryDraft('2026-11-10', $this->hq->id, 'x', [
            LineDraft::debit($rent, 'USD', '10'), LineDraft::credit($equity, 'USD', '9.99'),
        ]), $this->finance);
    }

    public function test_lines_keep_the_bulletin_rate_on_or_before_the_date_and_base_amounts(): void
    {
        $this->fund($this->cash, 'SYP', 1_300_000);
        $line = JournalLine::where('account_id', $this->cash->account_id)->sole();
        $this->assertSame(['13000.00000000', '100.0000', '1300000.0000'], [$line->rate_to_base, $line->base_debit, $line->debit]);

        // No bulletin on or before the date: the posting is refused with a clear message.
        $error = $this->postJson('/api/v1/finance/vouchers', ['type' => 'opening_balance', 'voucher_date' => '2026-08-31', 'approve' => true,
            'lines' => [['cash_box_id' => $this->cash->id, 'currency' => 'TRY', 'debit' => 10]]])->assertUnprocessable()->json('errors.date.0');
        $this->assertSame('لا يوجد سعر لـ TRY في النشرة حتى تاريخ 2026-08-31؛ أدخل النشرة أولاً.', $error);
    }

    public function test_reversal_restores_balances_and_cannot_repeat(): void
    {
        $this->fund($this->cash, 'USD', 1000);
        $other = $this->accountId('6103');
        $payment = $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD',
            'amount' => 250, 'account_id' => $other, 'description' => 'Internet']);
        $this->assertSame('750', $this->boxBalance($this->cash, 'USD'));

        $this->login($this->director)->postJson('/api/v1/finance/vouchers/'.$payment['id'].'/reverse', ['date' => '2026-11-12', 'reason' => 'خطأ'])->assertForbidden();
        $reversed = $this->login($this->finance)->postJson('/api/v1/finance/vouchers/'.$payment['id'].'/reverse', ['date' => '2026-11-12', 'reason' => 'خطأ في المبلغ'])
            ->assertOk()->assertJsonPath('data.status', 'reversed')->json('data');
        $this->assertSame('1000', $this->boxBalance($this->cash, 'USD'));
        $this->assertSame('0', app(LedgerService::class)->balance($other, $this->currencyId('USD')));
        $mirror = JournalEntry::findOrFail($reversed['reversal_entry_id']);
        $this->assertSame([$payment['journal_entry_id'], '2026-11-12'], [$mirror->reversal_of_id, $mirror->entry_date->toDateString()]);
        $this->assertSame('reversed', JournalEntry::find($payment['journal_entry_id'])->status);

        $this->postJson('/api/v1/finance/vouchers/'.$payment['id'].'/reverse', ['date' => '2026-11-12', 'reason' => 'again'])->assertStatus(409);
        $this->expectException(HttpException::class);
        app(LedgerService::class)->reverseEntry($mirror, '2026-11-13', 'x', $this->finance);
    }

    public function test_closed_months_refuse_postings_and_a_closed_voucher_is_reversed_in_an_open_month(): void
    {
        $this->fund($this->cash, 'USD', 1000, '2026-09-05');
        $october = $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD',
            'amount' => 100, 'account_id' => $this->accountId('6104'), 'voucher_date' => '2026-10-10']);
        $draft = $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD',
            'amount' => 5, 'account_id' => $this->accountId('6104'), 'voucher_date' => '2026-10-20', 'approve' => false]);

        // Months close in order, after their checks.
        $this->login($this->director)->postJson('/api/v1/finance/periods/2026-09/close')->assertForbidden();
        $this->login($this->finance)->postJson('/api/v1/finance/periods/2026-10/close')->assertUnprocessable();
        $run = PayRun::create(['month' => '2026-10', 'prepared_by' => $this->finance->id]);
        $checks = $this->getJson('/api/v1/finance/periods/2026-10/checks')->assertOk()->json('data');
        $this->assertCount(3, $checks['blockers'], 'September still open, one draft, and the pay run is not approved');
        $this->assertContains('كشف رواتب الشهر غير معتمد.', $checks['blockers']);
        $this->assertContains('لم يُنفَّذ توزيع الرواتب على المشاريع لهذا الشهر.', $checks['warnings']);
        $this->postJson('/api/v1/finance/periods/2026-11/close')->assertUnprocessable(); // the month has not ended
        $this->postJson('/api/v1/finance/periods/2026-09/close')->assertOk()->assertJsonPath('data.status', 'closed');
        $this->deleteJson('/api/v1/finance/vouchers/'.$draft['id'])->assertOk();
        $this->postJson('/api/v1/finance/periods/2026-10/close')->assertUnprocessable();
        $run->update(['status' => 'approved']);
        $this->postJson('/api/v1/finance/periods/2026-10/close')->assertOk();

        // R1: nothing posts into a closed month; the correction is dated in an open one.
        $refused = $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD',
            'amount' => 1, 'account_id' => $this->accountId('6104'), 'voucher_date' => '2026-10-15'], 422);
        $this->assertNull($refused);
        $this->postJson('/api/v1/finance/vouchers/'.$october['id'].'/reverse', ['date' => '2026-10-31', 'reason' => 'x x'])->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'الشهر 2026-10 مقفل؛ سجّل في فترة مفتوحة.');
        $this->postJson('/api/v1/finance/vouchers/'.$october['id'].'/reverse', ['date' => '2026-11-02', 'reason' => 'x x'])->assertOk();
        $this->assertSame('1000', $this->boxBalance($this->cash, 'USD'));

        // Reopening needs a reason and goes from the latest closed month back.
        $this->postJson('/api/v1/finance/periods/2026-09/reopen', ['reason' => 'تصحيح'])->assertUnprocessable();
        $this->postJson('/api/v1/finance/periods/2026-10/reopen', [])->assertUnprocessable();
        $this->postJson('/api/v1/finance/periods/2026-10/reopen', ['reason' => 'تصحيح قيد'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD',
            'amount' => 1, 'account_id' => $this->accountId('6104'), 'voucher_date' => '2026-10-15']);
        $this->assertDatabaseHas('usr_audit_logs', ['action' => 'finance.period_reopened']);
    }

    public function test_numbers_are_gapless_per_type_and_year_and_assigned_at_approval(): void
    {
        $this->fund($this->cash, 'USD', 100, '2025-12-01');
        $other = $this->accountId('6107');
        $receipt = fn (string $date) => $this->voucher(['type' => 'receipt', 'purpose' => 'other', 'cash_box_id' => $this->cash->id,
            'currency' => 'USD', 'amount' => 10, 'account_id' => $other, 'voucher_date' => $date]);
        $this->assertSame('RV-2026-000001', $receipt('2026-11-01')['number']);
        $this->assertSame('RV-2025-000001', $receipt('2025-12-20')['number']);
        $this->assertSame('RV-2026-000002', $receipt('2026-11-02')['number']);

        // A refused posting gives its number back; a draft has no number yet.
        $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 999,
            'account_id' => $other], 422);
        $draft = $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 5,
            'account_id' => $other, 'approve' => false]);
        $this->assertSame([null, 'مسودة #'.$draft['id']], [$draft['number'], $draft['display_number']]);
        $this->assertSame('PV-2026-000001', $this->postJson('/api/v1/finance/vouchers/'.$draft['id'].'/approve')->assertOk()->json('data.number'));
        $this->assertSame('OB-2025-000001', Voucher::where('type', 'opening_balance')->value('number'));
        $this->assertSame(['JE-2025-000001', 'JE-2025-000002'], JournalEntry::where('number', 'like', 'JE-2025-%')->orderBy('id')->pluck('number')->all());
    }

    public function test_opening_balance_adds_one_equity_counter_line_per_currency(): void
    {
        $partner = Party::create(['name' => 'Partner']);
        $voucher = $this->voucher(['type' => 'opening_balance', 'voucher_date' => '2026-11-01', 'lines' => [
            ['cash_box_id' => $this->cash->id, 'currency' => 'USD', 'debit' => 1000],
            ['cash_box_id' => $this->cash->id, 'currency' => 'TRY', 'debit' => 5000],
            ['account_id' => $this->roleId('other_receivables'), 'party_id' => $partner->id, 'currency' => 'USD', 'debit' => 200],
            ['account_id' => $this->roleId('other_payables'), 'party_id' => $partner->id, 'currency' => 'TRY', 'credit' => 800],
        ]]);
        $equity = JournalLine::where('journal_entry_id', $voucher['journal_entry_id'])->where('account_id', $this->roleId('opening_balance_equity'))
            ->orderBy('currency_id')->get();
        $this->assertCount(2, $equity);
        $this->assertSame(['-1200', '-4200'], [$this->roleBalance('opening_balance_equity', 'USD'), $this->roleBalance('opening_balance_equity', 'TRY')]);
        $this->assertSame('200', $this->roleBalance('other_receivables', 'USD', $partner->id));
        $this->assertSame('5000', $this->boxBalance($this->cash, 'TRY'));
    }

    public function test_rebuilt_balances_equal_the_live_ones(): void
    {
        $this->fund($this->cash, 'USD', 1000);
        $this->fund($this->cash, 'SYP', 2_600_000);
        $this->voucher(['type' => 'exchange', 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 100,
            'counter_cash_box_id' => $this->cash->id, 'counter_currency' => 'TRY', 'counter_amount' => 4340]);
        $payment = $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'SYP',
            'amount' => 130000, 'account_id' => $this->accountId('6101')]);
        $this->postJson('/api/v1/finance/vouchers/'.$payment['id'].'/reverse', ['date' => '2026-11-11', 'reason' => 'x x'])->assertOk();

        $this->artisan('finance:rebuild-balances --dry-run')->expectsOutputToContain('Balances match the journal')->assertSuccessful();

        AccountBalance::query()->where('account_id', $this->cash->account_id)->where('currency_id', $this->currencyId('USD'))->update(['balance' => 1]);
        $this->artisan('finance:rebuild-balances')->assertFailed();
        $this->assertSame('900', $this->boxBalance($this->cash, 'USD'));
        $this->artisan('finance:rebuild-balances')->assertSuccessful();
    }

    public function test_chart_rules_and_postable_accounts(): void
    {
        $postedOn = $this->accountId('6101');
        $this->fund($this->cash, 'USD', 100);
        $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 10, 'account_id' => $postedOn]);

        $this->postJson('/api/v1/finance/accounts', ['code' => '6101-1', 'name' => 'x', 'type' => 'expense', 'parent_id' => $postedOn])
            ->assertUnprocessable(); // has postings
        $this->postJson('/api/v1/finance/accounts', ['code' => '6109-1', 'name' => 'x', 'type' => 'asset', 'parent_id' => $this->accountId('6100')])
            ->assertUnprocessable(); // type differs from the parent
        $leaf = $this->postJson('/api/v1/finance/accounts', ['code' => '6109', 'name' => 'إيجارات', 'type' => 'expense', 'parent_id' => $this->accountId('6100')])
            ->assertCreated()->json('data');
        $child = $this->postJson('/api/v1/finance/accounts', ['code' => '6109-1', 'name' => 'إيجار المكتب', 'type' => 'expense', 'parent_id' => $leaf['id']])
            ->assertCreated()->json('data');
        $tree = collect($this->getJson('/api/v1/finance/accounts')->assertOk()->json('data'))->firstWhere('code', '5');
        $this->assertNotEmpty($tree['children']);

        // A group does not take postings; an inactive account neither (R4).
        $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 1, 'account_id' => $leaf['id']], 422);
        $this->postJson('/api/v1/finance/accounts/'.$child['id'].'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 1, 'account_id' => $child['id']], 422);

        // Box accounts and role accounts stay active; requires_party is enforced.
        $this->postJson('/api/v1/finance/accounts/'.$this->cash->account_id.'/deactivate')->assertUnprocessable();
        $this->postJson('/api/v1/finance/accounts/'.$this->roleId('fx_position').'/deactivate')->assertUnprocessable();
        $this->voucher(['type' => 'opening_balance', 'lines' => [['account_id' => $this->roleId('other_receivables'), 'currency' => 'USD', 'debit' => 5]]], 422);
        $this->login($this->director)->postJson('/api/v1/finance/accounts', ['code' => '9', 'name' => 'x', 'type' => 'expense'])->assertForbidden();
    }

    public function test_system_account_roles_are_listed_and_needed_for_posting(): void
    {
        $roles = $this->getJson('/api/v1/finance/system-accounts')->assertOk()->json('data');
        $this->assertSame(0, $roles['unmapped_count']);
        $this->assertContains('rounding_differences', array_column($roles['roles'], 'role'));

        SystemAccount::where('role_key', 'fx_position')->delete();
        $roles = $this->getJson('/api/v1/finance/system-accounts')->json('data');
        $this->assertSame(1, $roles['unmapped_count']);
        $this->assertTrue(collect($roles['roles'])->firstWhere('role', 'fx_position')['unmapped']);

        $this->fund($this->cash, 'USD', 100);
        $error = $this->postJson('/api/v1/finance/vouchers', ['type' => 'exchange', 'voucher_date' => '2026-11-10', 'approve' => true,
            'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 10, 'counter_cash_box_id' => $this->cash->id,
            'counter_currency' => 'TRY', 'counter_amount' => 434])->assertUnprocessable()->json('errors.system_accounts.0');
        $this->assertSame('اربط الحساب "مركز تصريف العملات" من إعدادات الحسابات الإلزامية.', $error);

        $this->putJson('/api/v1/finance/system-accounts', ['mappings' => ['fx_position' => $this->cash->account_id]])->assertUnprocessable();
        $this->putJson('/api/v1/finance/system-accounts', ['mappings' => ['fx_position' => $this->accountId('6100')]])->assertUnprocessable(); // a group
        $this->putJson('/api/v1/finance/system-accounts', ['mappings' => ['fx_position' => $this->accountId('1700')]])->assertOk()
            ->assertJsonPath('data.unmapped_count', 0);
        $this->login($this->director)->putJson('/api/v1/finance/system-accounts', ['mappings' => ['fx_position' => $this->accountId('1700')]])->assertForbidden();
    }

    public function test_vouchers_carry_attachments_that_are_frozen_after_approval(): void
    {
        Storage::fake('local');
        $this->fund($this->cash, 'USD', 100);
        $draft = $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 10,
            'account_id' => $this->accountId('6101'), 'approve' => false]);
        $url = '/api/v1/finance/vouchers/'.$draft['id'].'/attachments';

        $this->post($url, ['file' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])->assertUnprocessable();
        $first = $this->post($url, ['file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $second = $this->post($url, ['file' => UploadedFile::fake()->image('receipt.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('path', $first);
        $this->assertCount(2, $this->getJson('/api/v1/finance/vouchers/'.$draft['id'])->assertOk()->json('data.attachments'));
        $this->get($url.'/'.$first['id'])->assertOk();
        $this->deleteJson($url.'/'.$second['id'])->assertOk();

        $this->postJson('/api/v1/finance/vouchers/'.$draft['id'].'/approve')->assertOk();
        $this->deleteJson($url.'/'.$first['id'])->assertStatus(409);
        $this->assertDatabaseHas('usr_audit_logs', ['action' => 'finance.attachment_added']);
    }

    public function test_audit_log_lists_finance_actions_for_holders_of_finance_audit(): void
    {
        $this->fund($this->cash, 'USD', 100);
        $actions = collect($this->login($this->director)->getJson('/api/v1/finance/audit-log?per_page=100')->assertOk()->json('data'))->pluck('action');
        $this->assertContains('finance.voucher_approved', $actions);
        $this->assertContains('finance.cash_box_created', $actions);
        $this->assertContains('fx.rate_set', $actions);
        $this->login($this->actor('hr_manager'))->getJson('/api/v1/finance/audit-log')->assertForbidden();
    }
}
