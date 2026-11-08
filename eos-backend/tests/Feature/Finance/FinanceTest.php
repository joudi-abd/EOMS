<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Box;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Item;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\CashService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $director;

    private Account $capital;

    private Account $rent;

    private Account $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, CurrencySeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-11-15T10:00:00+03:00'));
        $this->finance = User::factory()->create();
        $this->finance->assignRole('finance_manager');
        $this->director = User::factory()->create();
        $this->director->assignRole('company_manager');
        Sanctum::actingAs($this->finance);
        $this->capital = Account::find($this->api('accounts', ['code' => '3100', 'name' => 'رأس المال', 'type' => 'equity'])['id']);
        $this->rent = Account::find($this->api('accounts', ['code' => '5300', 'name' => 'إيجارات', 'type' => 'expense'])['id']);
        $this->sales = Account::find($this->api('accounts', ['code' => '4100', 'name' => 'مبيعات مواد', 'type' => 'revenue'])['id']);
    }

    private function api(string $path, array $data, int $status = 201): ?array
    {
        return $this->postJson('/api/v1/finance/'.$path, $data)->assertStatus($status)->json('data');
    }

    private function box(string $currency, float $capital = 0): Box
    {
        $box = Box::find($this->api('boxes', ['name' => 'Cash '.$currency, 'kind' => 'cash', 'currency_code' => $currency])['id']);
        if ($capital > 0) {
            $this->api('transactions/receipt', ['box_id' => $box->id, 'amount' => $capital, 'date' => '2026-11-01',
                'description' => 'Capital', 'account_id' => $this->capital->id]);
        }

        return $box;
    }

    private function balance(Box $box): float
    {
        return app(CashService::class)->balance($box);
    }

    private function limits(): void
    {
        Sanctum::actingAs($this->director);
        $this->putJson('/api/v1/finance/approval-limits', ['currency_code' => 'SYP', 'amount' => 5_000_000])->assertOk();
        $this->putJson('/api/v1/finance/approval-limits', ['currency_code' => 'USD', 'amount' => 2_000])->assertOk();
        Sanctum::actingAs($this->finance);
    }

    public function test_chart_and_manual_entries_follow_double_entry_rules(): void
    {
        $assets = $this->api('accounts', ['code' => '1000', 'name' => 'أصول متداولة', 'type' => 'asset']);
        $this->api('accounts', ['code' => '1010', 'name' => 'سلف', 'type' => 'expense', 'parent_id' => $assets['id']], 422);
        $advances = $this->api('accounts', ['code' => '1010', 'name' => 'سلف', 'type' => 'asset', 'parent_id' => $assets['id']]);

        $lines = fn ($debitAccount, $d, $c) => ['date' => '2026-11-02', 'currency_code' => 'USD', 'description' => 'Test',
            'lines' => [['account_id' => $debitAccount, 'debit' => $d], ['account_id' => $this->capital->id, 'credit' => $c]]];
        $this->api('entries', $lines($advances['id'], 100, 90), 422); // unbalanced
        $this->api('entries', $lines($assets['id'], 100, 100), 422); // group account takes no postings
        $entry = $this->api('entries', $lines($advances['id'], 100, 100));
        $this->assertSame('JE-2026-000001', $entry['number']);

        $reversal = $this->api('entries/'.$entry['id'].'/reverse', ['date' => '2026-11-03', 'reason' => 'Wrong account']);
        $this->assertSame($entry['id'], $reversal['reverses_id']);
        $this->api('entries/'.$entry['id'].'/reverse', ['date' => '2026-11-03', 'reason' => 'Again'], 409);
        $this->api('entries/'.$reversal['id'].'/reverse', ['date' => '2026-11-03', 'reason' => 'Undo'], 409);
        // An account with postings cannot become a group.
        $this->api('accounts', ['code' => '1011', 'name' => 'x', 'type' => 'asset', 'parent_id' => $advances['id']], 422);
        // System accounts exist and cannot be deactivated.
        $receivables = Account::where('system_key', 'receivables')->firstOrFail();
        $this->patchJson('/api/v1/finance/accounts/'.$receivables->id, ['active' => false])->assertStatus(422);
        $this->patchJson('/api/v1/finance/accounts/'.$receivables->id, ['name' => 'ذمم مدينة', 'code' => '1210'])->assertOk();
    }

    public function test_cash_operations_respect_limits_balances_and_approval(): void
    {
        $syp = $this->box('SYP', 10_000_000);
        $usd = $this->box('USD', 3_000);
        $other = $this->box('SYP');

        // No limit set yet: every payment waits for the company manager.
        $pending = $this->api('transactions/payment', ['box_id' => $syp->id, 'amount' => 100_000, 'date' => '2026-11-05',
            'description' => 'Rent', 'account_id' => $this->rent->id]);
        $this->assertSame('pending', $pending['status']);
        $this->assertSame(10_000_000.0, $this->balance($syp));
        $this->api('transactions/'.$pending['id'].'/decision', ['decision' => 'approve'], 403); // finance cannot approve
        Sanctum::actingAs($this->director);
        $this->api('transactions/'.$pending['id'].'/decision', ['decision' => 'approve'], 200);
        $this->assertSame(9_900_000.0, $this->balance($syp));

        $this->limits();
        $this->assertSame('posted', $this->api('transactions/payment', ['box_id' => $syp->id, 'amount' => 900_000, 'date' => '2026-11-05',
            'description' => 'Rent', 'account_id' => $this->rent->id])['status']);
        $this->assertSame('pending', $this->api('transactions/payment', ['box_id' => $syp->id, 'amount' => 6_000_000, 'date' => '2026-11-05',
            'description' => 'Big rent', 'account_id' => $this->rent->id])['status']);
        $this->api('transactions/payment', ['box_id' => $usd->id, 'amount' => 1_500, 'date' => '2026-11-05',
            'description' => 'Too much', 'account_id' => $this->rent->id, 'counter_box_id' => $syp->id], 422);

        // Not enough money: refused, nothing recorded.
        $this->api('transactions/transfer', ['box_id' => $other->id, 'counter_box_id' => $syp->id, 'amount' => 1, 'date' => '2026-11-06', 'description' => 'x'], 422);
        $this->api('transactions/transfer', ['box_id' => $syp->id, 'counter_box_id' => $other->id, 'amount' => 1_000_000, 'date' => '2026-11-06', 'description' => 'Move']);
        $this->api('transactions/transfer', ['box_id' => $syp->id, 'counter_box_id' => $usd->id, 'amount' => 1, 'date' => '2026-11-06', 'description' => 'x'], 422);
        $this->assertSame(8_000_000.0, $this->balance($syp));
        $this->assertSame(1_000_000.0, $this->balance($other));

        // Currency exchange: 1,000 USD sold for 13,000,000 SYP; one balanced entry per currency through the clearing account.
        $exchange = $this->api('transactions/exchange', ['box_id' => $usd->id, 'counter_box_id' => $other->id, 'amount' => 1_000,
            'counter_amount' => 13_000_000, 'date' => '2026-11-07', 'description' => 'Sell dollars']);
        $this->assertSame('13000.000000', $exchange['rate']);
        $this->assertSame(2_000.0, $this->balance($usd));
        $this->assertSame(14_000_000.0, $this->balance($other));
        $this->assertSame(['SYP', 'USD'], JournalEntry::where('source_type', 'transaction')->where('source_id', $exchange['id'])
            ->orderBy('currency_code')->pluck('currency_code')->all());

        // Reversal restores the boxes; payroll payments cannot be reversed here.
        $this->api('transactions/'.$exchange['id'].'/reverse', ['date' => '2026-11-08', 'reason' => 'Cancelled deal'], 200);
        $this->assertSame(3_000.0, $this->balance($usd));
        $this->assertSame(1_000_000.0, $this->balance($other));
    }

    public function test_a_user_cannot_approve_what_they_recorded(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        Sanctum::actingAs($admin);
        $box = $this->box('SYP', 1_000_000);
        $tx = $this->api('transactions/payment', ['box_id' => $box->id, 'amount' => 10, 'date' => '2026-11-05', 'description' => 'x', 'account_id' => $this->rent->id]);
        $this->api('transactions/'.$tx['id'].'/decision', ['decision' => 'approve'], 403);
    }

    public function test_invoices_move_inventory_at_weighted_average_and_track_payments(): void
    {
        $this->limits();
        $usd = $this->box('USD', 5_000);
        $syp = $this->box('SYP');
        $supplier = $this->api('parties', ['kind' => 'supplier', 'name' => 'Cement Co.']);
        $customer = $this->api('parties', ['kind' => 'customer', 'name' => 'Client A']);
        $cement = Item::find($this->api('items', ['name' => 'Cement bag', 'unit' => 'bag', 'currency_code' => 'USD', 'revenue_account_id' => $this->sales->id])['id']);

        $purchase = fn (float $qty, float $price, string $date) => $this->api('invoices', ['type' => 'purchase', 'party_id' => $supplier['id'],
            'date' => $date, 'due_date' => '2026-11-20', 'currency_code' => 'USD', 'lines' => [['item_id' => $cement->id, 'quantity' => $qty, 'unit_price' => $price]]]);
        $first = $purchase(10, 100, '2026-11-02');
        $this->api('invoices/'.$first['id'].'/submit', [], 200);
        $second = $purchase(10, 130, '2026-11-03');
        $this->api('invoices/'.$second['id'].'/submit', [], 200);
        $cement->refresh();
        $this->assertSame(['20.000', '115.000000'], [$cement->quantity, $cement->average_cost]);
        $this->assertSame('P-2026-0001', Invoice::find($first['id'])->number);

        // Purchases only in the item's valuation currency; free-text lines need an account.
        $this->api('invoices', ['type' => 'purchase', 'party_id' => $supplier['id'], 'date' => '2026-11-04', 'currency_code' => 'SYP',
            'lines' => [['item_id' => $cement->id, 'quantity' => 1, 'unit_price' => 1]]], 422);
        $this->api('invoices', ['type' => 'purchase', 'party_id' => $supplier['id'], 'date' => '2026-11-04', 'currency_code' => 'USD',
            'lines' => [['description' => 'Transport', 'quantity' => 1, 'unit_price' => 50]]], 422);

        // Sale in SYP of a USD-valued item: revenue in SYP, cost of goods sold (5 × 115) in USD. No overselling.
        $sale = $this->api('invoices', ['type' => 'sale', 'party_id' => $customer['id'], 'date' => '2026-11-05', 'due_date' => '2026-11-10',
            'currency_code' => 'SYP', 'lines' => [['item_id' => $cement->id, 'quantity' => 5, 'unit_price' => 2_000_000]]]);
        $this->assertSame('pending', $this->api('invoices/'.$sale['id'].'/submit', [], 200)['status'], '10,000,000 SYP is above the limit');
        Sanctum::actingAs($this->director);
        $this->api('invoices/'.$sale['id'].'/decision', ['decision' => 'approve'], 200);
        Sanctum::actingAs($this->finance);
        $this->assertSame('15.000', $cement->refresh()->quantity);
        $cogs = JournalEntry::where('source_type', 'invoice')->where('source_id', $sale['id'])->where('currency_code', 'USD')->with('lines')->firstOrFail();
        $this->assertSame('575.00', $cogs->lines->firstWhere('debit', '>', 0)->debit);
        $tooMuch = $this->api('invoices', ['type' => 'sale', 'party_id' => $customer['id'], 'date' => '2026-11-05', 'currency_code' => 'USD',
            'lines' => [['item_id' => $cement->id, 'quantity' => 16, 'unit_price' => 200]]]);
        $this->api('invoices/'.$tooMuch['id'].'/submit', [], 422);

        // Payments against invoices: partial, then no overpayment; the customer is overdue after the due date.
        $this->api('transactions/receipt', ['box_id' => $syp->id, 'amount' => 4_000_000, 'date' => '2026-11-06', 'description' => 'Part',
            'party_id' => $customer['id'], 'invoice_id' => $sale['id']]);
        $this->api('transactions/receipt', ['box_id' => $syp->id, 'amount' => 7_000_000, 'date' => '2026-11-06', 'description' => 'Over',
            'party_id' => $customer['id'], 'invoice_id' => $sale['id']], 422);
        $invoice = $this->getJson('/api/v1/finance/invoices/'.$sale['id'])->assertOk()->json('data');
        $this->assertEquals(['partial', 6_000_000, true], [$invoice['payment_status'], $invoice['remaining'], $invoice['overdue']]);
        $statement = $this->getJson('/api/v1/finance/reports/party?party_id='.$customer['id'].'&currency=SYP&from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertSame(6_000_000, (int) $statement['closing_balance']);
        $this->assertSame(6_000_000, (int) $statement['overdue_total']);

        // Supplier payment, then cancellation needs the payment reversed first; cancelling returns the stock effect.
        $pay = $this->api('transactions/payment', ['box_id' => $usd->id, 'amount' => 1_000, 'date' => '2026-11-07', 'description' => 'Pay',
            'party_id' => $supplier['id'], 'invoice_id' => $first['id']]);
        $this->api('invoices/'.$first['id'].'/cancel', ['date' => '2026-11-08', 'reason' => 'Returned'], 409);
        $this->api('transactions/'.$pay['id'].'/reverse', ['date' => '2026-11-08', 'reason' => 'Refunded'], 200);
        $this->api('invoices/'.$first['id'].'/cancel', ['date' => '2026-11-08', 'reason' => 'Returned'], 200);
        $cement->refresh();
        $this->assertSame('5.000', $cement->quantity);
        // Weighted average: 15 bags worth 1,725 minus the cancelled 1,000 leaves 725 for 5 bags.
        $this->assertSame('145.000000', $cement->average_cost);
        $inventory = Account::where('system_key', 'inventory')->value('id');
        $ledger = $this->getJson('/api/v1/finance/reports/ledger?account_id='.$inventory.'&currency=USD&from=2026-11-01&to=2026-11-30')->json('data');
        $this->assertEquals(725, $ledger['closing_balance'], 'The inventory account always equals quantity × average cost');
    }

    public function test_stock_count_adjustments_post_to_the_chosen_account(): void
    {
        $loss = $this->api('accounts', ['code' => '5400', 'name' => 'فروقات جرد', 'type' => 'expense']);
        $item = Item::find($this->api('items', ['name' => 'Rebar', 'unit' => 'ton', 'currency_code' => 'USD'])['id']);
        $this->api('items/'.$item->id.'/adjust', ['date' => '2026-11-02', 'quantity' => 4, 'unit_cost' => 500, 'account_id' => $this->capital->id, 'reason' => 'Count']);
        $this->api('items/'.$item->id.'/adjust', ['date' => '2026-11-03', 'quantity' => -1, 'account_id' => $loss['id'], 'reason' => 'Damaged']);
        $this->postJson('/api/v1/finance/items/'.$item->id.'/adjust', ['date' => '2026-11-03', 'quantity' => -10,
            'account_id' => $loss['id'], 'reason' => 'Lost on site'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame('3.000', $item->refresh()->quantity);
        $pl = $this->getJson('/api/v1/finance/reports/income-statement?currency=USD&from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertEquals(500, $pl['total_expenses']);
    }

    public function test_month_close_needs_manager_approval_and_blocks_postings(): void
    {
        $box = $this->box('SYP', 1_000_000);
        $this->travelTo(CarbonImmutable::parse('2026-12-02T10:00:00+03:00'));
        $this->api('periods/close-request', ['month' => '2026-12'], 422); // not finished
        $pending = $this->api('transactions/payment', ['box_id' => $box->id, 'amount' => 10, 'date' => '2026-11-20', 'description' => 'x', 'account_id' => $this->rent->id]);
        $this->api('periods/close-request', ['month' => '2026-11'], 409); // undecided payment in the month
        Sanctum::actingAs($this->director);
        $this->api('transactions/'.$pending['id'].'/decision', ['decision' => 'reject', 'note' => 'No'], 200);
        Sanctum::actingAs($this->finance);
        $this->api('periods/close-request', ['month' => '2026-11'], 200);
        $this->api('periods/2026-11/decision', ['decision' => 'approve'], 403);
        Sanctum::actingAs($this->director);
        $this->api('periods/2026-11/decision', ['decision' => 'approve'], 200);

        Sanctum::actingAs($this->finance);
        $this->api('transactions/receipt', ['box_id' => $box->id, 'amount' => 1, 'date' => '2026-11-30', 'description' => 'Late', 'account_id' => $this->capital->id], 422);
        $this->api('transactions/receipt', ['box_id' => $box->id, 'amount' => 1, 'date' => '2026-12-01', 'description' => 'Ok', 'account_id' => $this->capital->id]);
        Sanctum::actingAs($this->director);
        $this->api('periods/2026-11/reopen', ['reason' => 'Missing invoice'], 200);
        Sanctum::actingAs($this->finance);
        $this->api('transactions/receipt', ['box_id' => $box->id, 'amount' => 1, 'date' => '2026-11-30', 'description' => 'Now', 'account_id' => $this->capital->id]);
    }

    public function test_reports_balance_per_currency(): void
    {
        $this->limits();
        $syp = $this->box('SYP', 10_000_000);
        $this->box('USD', 1_000);
        $customer = $this->api('parties', ['kind' => 'customer', 'name' => 'Client']);
        $sale = $this->api('invoices', ['type' => 'sale', 'party_id' => $customer['id'], 'date' => '2026-11-03', 'currency_code' => 'SYP',
            'lines' => [['description' => 'Design fee', 'quantity' => 1, 'unit_price' => 3_000_000, 'account_id' => $this->sales->id]]]);
        $this->api('invoices/'.$sale['id'].'/submit', [], 200);
        $this->api('transactions/payment', ['box_id' => $syp->id, 'amount' => 500_000, 'date' => '2026-11-04', 'description' => 'Rent', 'account_id' => $this->rent->id]);

        $q = '?currency=SYP&from=2026-11-01&to=2026-11-30';
        $trial = $this->getJson('/api/v1/finance/reports/trial-balance'.$q)->assertOk()->json('data');
        $this->assertSame($trial['debit'], $trial['credit']);
        $pl = $this->getJson('/api/v1/finance/reports/income-statement'.$q)->assertOk()->json('data');
        $this->assertEquals([3_000_000, 500_000, 2_500_000], [$pl['total_revenue'], $pl['total_expenses'], $pl['net_profit']]);
        $bs = $this->getJson('/api/v1/finance/reports/balance-sheet?currency=SYP&to=2026-11-30')->assertOk()->json('data');
        $this->assertEquals(12_500_000, $bs['total_assets'], '9,500,000 cash + 3,000,000 receivable');
        $this->assertEquals($bs['total_assets'], $bs['total_liabilities_and_equity']);
        $statement = $this->getJson('/api/v1/finance/reports/box?box_id='.$syp->id.'&from=2026-11-04&to=2026-11-30')->assertOk()->json('data');
        $this->assertEquals([10_000_000, 9_500_000], [$statement['opening_balance'], $statement['closing_balance']]);
        // USD books are separate.
        $usdTrial = $this->getJson('/api/v1/finance/reports/trial-balance?currency=USD&from=2026-11-01&to=2026-11-30')->json('data');
        $this->assertEquals(1_000, $usdTrial['debit']);
    }

    public function test_permissions(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('hr_manager');
        Sanctum::actingAs($hr);
        $this->getJson('/api/v1/finance/boxes')->assertForbidden();
        Sanctum::actingAs($this->director);
        $this->getJson('/api/v1/finance/boxes')->assertOk();
        $this->api('boxes', ['name' => 'x', 'kind' => 'cash', 'currency_code' => 'SYP'], 403);
        Sanctum::actingAs($this->finance);
        $this->putJson('/api/v1/finance/approval-limits', ['currency_code' => 'SYP', 'amount' => 1])->assertForbidden();
    }
}
