<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Box;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Item;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\StockMove;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Services\CashService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        // Supplier payment blocks cancel until reversed; after a sale, purchase cancel stays blocked (later stock moves).
        $pay = $this->api('transactions/payment', ['box_id' => $usd->id, 'amount' => 1_000, 'date' => '2026-11-07', 'description' => 'Pay',
            'party_id' => $supplier['id'], 'invoice_id' => $first['id']]);
        $this->api('invoices/'.$first['id'].'/cancel', ['date' => '2026-11-08', 'reason' => 'Returned'], 409);
        $this->api('transactions/'.$pay['id'].'/reverse', ['date' => '2026-11-08', 'reason' => 'Refunded'], 200);
        $this->postJson('/api/v1/finance/invoices/'.$first['id'].'/cancel', ['date' => '2026-11-08', 'reason' => 'Returned'])
            ->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $cement->refresh();
        $this->assertSame(['15.000', '115.000000'], [$cement->quantity, $cement->average_cost]);
        $inventory = Account::where('system_key', 'inventory')->value('id');
        $ledger = $this->getJson('/api/v1/finance/reports/ledger?account_id='.$inventory.'&currency=USD&from=2026-11-01&to=2026-11-30')->json('data');
        $this->assertEquals(1725, $ledger['closing_balance'], 'The inventory account always equals quantity × average cost');
    }

    public function test_purchase_cancel_only_as_the_latest_stock_move_and_never_to_a_negative_cost(): void
    {
        $this->limits();
        $supplier = $this->api('parties', ['kind' => 'supplier', 'name' => 'Steel Co.']);
        $customer = $this->api('parties', ['kind' => 'customer', 'name' => 'Buyer']);
        $inventory = Account::where('system_key', 'inventory')->value('id');
        $ledger = fn () => (float) $this->getJson('/api/v1/finance/reports/ledger?account_id='.$inventory.'&currency=USD&from=2026-11-01&to=2026-11-30')->json('data.closing_balance');
        $newItem = fn (string $name) => Item::find($this->api('items', ['name' => $name, 'unit' => 'pcs', 'currency_code' => 'USD', 'revenue_account_id' => $this->sales->id])['id']);
        $post = function (string $type, Item $item, float $qty, float $price, string $date) use ($supplier, $customer) {
            $invoice = $this->api('invoices', ['type' => $type, 'party_id' => ($type === 'sale' ? $customer : $supplier)['id'], 'date' => $date,
                'currency_code' => 'USD', 'lines' => [['item_id' => $item->id, 'quantity' => $qty, 'unit_price' => $price]]]);

            return $this->api('invoices/'.$invoice['id'].'/submit', [], 200);
        };
        $cancel = fn (array $invoice) => $this->postJson('/api/v1/finance/invoices/'.$invoice['id'].'/cancel', ['date' => '2026-11-09', 'reason' => 'Entered by mistake']);

        // Only the latest purchase can be cancelled.
        $beam = $newItem('Beam');
        $first = $post('purchase', $beam, 10, 100, '2026-11-02');
        $second = $post('purchase', $beam, 10, 130, '2026-11-03');
        $cancel($first)->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $cancel($second)->assertOk();
        $this->assertSame(['10.000', '100.000000'], [$beam->refresh()->quantity, $beam->average_cost]);
        $this->assertEquals(1000, $ledger());

        // A sale can be cancelled after a restock: the goods come back at the cost they left with.
        $sale = $post('sale', $beam, 4, 200, '2026-11-04');
        $post('purchase', $beam, 2, 140, '2026-11-05');
        $cancel($first)->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $cancel($sale)->assertOk();
        $this->assertSame('12.000', $beam->refresh()->quantity);
        $this->assertEquals(1280, $ledger());
        $this->assertEqualsWithDelta(1280, (float) $beam->quantity * (float) $beam->average_cost, 0.01);

        // Zero-cost stock plus a purchase whose average rounds down: cancelling it never stores a negative cost.
        $bolt = $newItem('Bolt');
        $this->api('items/'.$bolt->id.'/adjust', ['date' => '2026-11-02', 'quantity' => 1.936, 'unit_cost' => 0,
            'account_id' => Account::where('system_key', 'opening_balances')->value('id'), 'reason' => 'Opening count']);
        $odd = $post('purchase', $bolt, 1, 0.92, '2026-11-03');
        $this->assertSame('0.313351', $bolt->refresh()->average_cost);
        $cancel($odd)->assertOk();
        $this->assertSame(['1.936', '0.000000'], [$bolt->refresh()->quantity, $bolt->average_cost]);
        $this->assertGreaterThanOrEqual(0, (float) StockMove::query()->where('item_id', $bolt->id)->min('average_cost_after'));
    }

    public function test_cash_counterpart_rejects_system_accounts_except_opening_balances(): void
    {
        $syp = $this->box('SYP', 1_000_000);
        $salaries = Account::where('system_key', 'salaries')->firstOrFail();
        $this->api('transactions/payment', ['box_id' => $syp->id, 'amount' => 10, 'date' => '2026-11-05',
            'description' => 'Bad', 'account_id' => $salaries->id], 422);
        $supplier = $this->api('parties', ['kind' => 'supplier', 'name' => 'Temp Co.']);
        $pending = $this->api('transactions/payment', ['box_id' => $syp->id, 'amount' => 50, 'date' => '2026-11-05',
            'description' => 'Pending pay', 'party_id' => $supplier['id']]);
        $this->assertSame('pending', $pending['status']);
        $this->patchJson('/api/v1/finance/parties/'.$supplier['id'], ['active' => false])->assertStatus(422);
        Sanctum::actingAs($this->director);
        $this->api('transactions/'.$pending['id'].'/decision', ['decision' => 'reject', 'note' => 'ok'], 200);
        Sanctum::actingAs($this->finance);
        $this->patchJson('/api/v1/finance/parties/'.$supplier['id'], ['active' => false])->assertOk();
        // Form clients may send 0/1; boolean coercion must still deactivate.
        $this->patchJson('/api/v1/finance/parties/'.$supplier['id'], ['active' => true])->assertOk();
        $this->patchJson('/api/v1/finance/parties/'.$supplier['id'], ['active' => 0])->assertOk()
            ->assertJsonPath('data.active', false);
        // A deactivated party cannot receive a new payment (row lock shared with deactivate).
        $this->api('transactions/payment', ['box_id' => $syp->id, 'amount' => 10, 'date' => '2026-11-05',
            'description' => 'After deactivate', 'party_id' => $supplier['id']], 422);
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

    public function test_inventory_account_moves_only_with_stock_documents(): void
    {
        $box = $this->box('USD', 1_000);
        $inventory = (int) Account::where('system_key', 'inventory')->value('id');
        $receivables = (int) Account::where('system_key', 'receivables')->value('id');
        $item = Item::find($this->api('items', ['name' => 'Pipe', 'unit' => 'm', 'currency_code' => 'USD'])['id']);

        $this->postJson('/api/v1/finance/transactions/receipt', [
            'box_id' => $box->id, 'amount' => 50, 'date' => '2026-11-02', 'description' => 'Fake stock cash',
            'account_id' => $inventory,
        ])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson('/api/v1/finance/transactions/payment', [
            'box_id' => $box->id, 'amount' => 50, 'date' => '2026-11-02', 'description' => 'Fake stock pay',
            'account_id' => $inventory,
        ])->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $this->postJson('/api/v1/finance/items/'.$item->id.'/adjust', [
            'date' => '2026-11-02', 'quantity' => 1, 'unit_cost' => 10, 'account_id' => $inventory, 'reason' => 'Self offset',
        ])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson('/api/v1/finance/items/'.$item->id.'/adjust', [
            'date' => '2026-11-02', 'quantity' => 1, 'unit_cost' => 10, 'account_id' => $receivables, 'reason' => 'Receivable',
        ])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson('/api/v1/finance/items/'.$item->id.'/adjust', [
            'date' => '2026-11-02', 'quantity' => 1, 'unit_cost' => 10, 'account_id' => $box->account_id, 'reason' => 'Cash box',
        ])->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $supplier = $this->api('parties', ['kind' => 'supplier', 'name' => 'Vendor']);
        $this->postJson('/api/v1/finance/invoices', [
            'type' => 'purchase', 'party_id' => $supplier['id'], 'date' => '2026-11-02', 'currency_code' => 'USD',
            'lines' => [['description' => 'Free text stock', 'quantity' => 1, 'unit_price' => 10, 'account_id' => $inventory]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');

        // Legitimate stock document still posts inventory; the opening count goes against opening balances.
        $this->api('items/'.$item->id.'/adjust', [
            'date' => '2026-11-02', 'quantity' => 2, 'unit_cost' => 25,
            'account_id' => Account::where('system_key', 'opening_balances')->value('id'), 'reason' => 'Opening count',
        ]);
        $this->assertSame('2.000', $item->refresh()->quantity);
        $ledger = $this->getJson('/api/v1/finance/reports/ledger?account_id='.$inventory.'&currency=USD&from=2026-11-01&to=2026-11-30')->json('data');
        $this->assertEquals(50, $ledger['closing_balance']);
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
        // A new unsettled payment after the request must block final approval.
        $late = $this->api('transactions/payment', ['box_id' => $box->id, 'amount' => 10, 'date' => '2026-11-21', 'description' => 'Late pending', 'account_id' => $this->rent->id]);
        Sanctum::actingAs($this->director);
        $this->api('periods/2026-11/decision', ['decision' => 'approve'], 409);
        $this->api('transactions/'.$late['id'].'/decision', ['decision' => 'reject', 'note' => 'Clear close'], 200);
        $this->api('periods/2026-11/decision', ['decision' => 'approve'], 200);

        Sanctum::actingAs($this->finance);
        $this->api('transactions/receipt', ['box_id' => $box->id, 'amount' => 1, 'date' => '2026-11-30', 'description' => 'Late', 'account_id' => $this->capital->id], 422);
        $this->api('transactions/receipt', ['box_id' => $box->id, 'amount' => 1, 'date' => '2026-12-01', 'description' => 'Ok', 'account_id' => $this->capital->id]);
        // A zero-value stock count posts no entry but still changes quantity: the closed month refuses it too.
        $item = Item::find($this->api('items', ['name' => 'Gift sample', 'unit' => 'pcs', 'currency_code' => 'USD'])['id']);
        $this->postJson('/api/v1/finance/items/'.$item->id.'/adjust', ['date' => '2026-11-15', 'quantity' => 3, 'unit_cost' => 0,
            'account_id' => $this->capital->id, 'reason' => 'Free samples'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->assertSame('0.000', $item->refresh()->quantity);
        $this->assertSame(0, StockMove::count());
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

    public function test_ledger_guards_hold_on_every_posting_path(): void
    {
        $a = $this->box('SYP', 100);
        $b = $this->box('SYP');
        $receipt = Transaction::query()->where('box_id', $a->id)->firstOrFail();
        $this->api('transactions/transfer', ['box_id' => $a->id, 'counter_box_id' => $b->id, 'amount' => 100, 'date' => '2026-11-02', 'description' => 'Move']);
        // Reversing the receipt would leave box A negative.
        $this->api('transactions/'.$receipt->id.'/reverse', ['date' => '2026-11-03', 'reason' => 'Mistake'], 422);
        $this->assertSame(0.0, $this->balance($a));
        // Manual entries: no overdrawn box, receivables need a party, inventory moves only with stock.
        $manual = fn (int $account, array $extra = []) => ['date' => '2026-11-04', 'currency_code' => 'SYP', 'description' => 'Manual',
            'lines' => [['account_id' => $this->rent->id, 'debit' => 500], ['account_id' => $account, 'credit' => 500, ...$extra]]];
        $this->api('entries', $manual($b->account_id), 422);
        $this->api('entries', $manual(Account::where('system_key', 'receivables')->value('id')), 422);
        $this->api('entries', $manual(Account::where('system_key', 'inventory')->value('id')), 422);
        $customer = $this->api('parties', ['kind' => 'customer', 'name' => 'C']);
        $this->api('entries', $manual(Account::where('system_key', 'receivables')->value('id'), ['party_id' => $customer['id']]));
        // A manual correction of cash within the balance is allowed.
        $this->api('entries', ['date' => '2026-11-04', 'currency_code' => 'SYP', 'description' => 'Shortage',
            'lines' => [['account_id' => $this->rent->id, 'debit' => 40], ['account_id' => $b->account_id, 'credit' => 40]]]);
        $this->assertSame(60.0, $this->balance($b));
        // Zero invoices are refused when saved.
        $this->api('invoices', ['type' => 'sale', 'party_id' => $customer['id'], 'date' => '2026-11-05', 'currency_code' => 'SYP',
            'lines' => [['description' => 'Free', 'quantity' => 1, 'unit_price' => 0, 'account_id' => $this->sales->id]]], 422);
    }

    public function test_approvals_notify_approvers_and_decisions_notify_the_recorder(): void
    {
        $box = $this->box('SYP', 1_000_000);
        $tx = $this->api('transactions/payment', ['box_id' => $box->id, 'amount' => 10, 'date' => '2026-11-05', 'description' => 'Rent', 'account_id' => $this->rent->id]);
        $this->assertSame('finance.payment_pending', $this->director->notifications()->first()?->data['kind']);
        $this->patchJson('/api/v1/finance/boxes/'.$box->id, ['active' => false])->assertStatus(422);
        Sanctum::actingAs($this->director);
        $this->api('transactions/'.$tx['id'].'/decision', ['decision' => 'reject', 'note' => 'Not now'], 200);
        $this->assertSame('finance.payment_rejected', $this->finance->notifications()->first()?->data['kind']);

        Sanctum::actingAs($this->finance);
        $customer = $this->api('parties', ['kind' => 'customer', 'name' => 'C']);
        $invoice = $this->api('invoices', ['type' => 'sale', 'party_id' => $customer['id'], 'date' => '2026-11-05', 'currency_code' => 'SYP',
            'lines' => [['description' => 'Fee', 'quantity' => 1, 'unit_price' => 100, 'account_id' => $this->sales->id]]]);
        $this->api('invoices/'.$invoice['id'].'/submit', [], 200);
        $this->travelTo(CarbonImmutable::parse('2026-12-02T10:00:00+03:00'));
        Sanctum::actingAs($this->director);
        $this->api('invoices/'.$invoice['id'].'/decision', ['decision' => 'approve'], 200);
        Sanctum::actingAs($this->finance);
        $this->api('periods/close-request', ['month' => '2026-11'], 200);
        $kinds = $this->director->notifications()->get()->pluck('data.kind')->all();
        $this->assertContains('finance.invoice_pending', $kinds);
        $this->assertContains('finance.period_close_requested', $kinds);
        $this->assertContains('finance.invoice_approved', $this->finance->notifications()->get()->pluck('data.kind')->all());
    }

    public function test_a_new_box_can_start_with_an_opening_amount(): void
    {
        $box = $this->api('boxes', ['name' => 'Safe', 'kind' => 'cash', 'currency_code' => 'SYP', 'opening_amount' => 2_500_000, 'opening_date' => '2026-11-01']);
        $this->assertEquals(2_500_000, $box['balance']);
        $opening = Account::where('system_key', 'opening_balances')->firstOrFail();
        $entry = JournalEntry::where('source_type', 'transaction')->with('lines')->latest('id')->firstOrFail();
        $this->assertSame('2500000.00', $entry->lines->firstWhere('account_id', $opening->id)->credit);
        // Funded from a chosen equity account (capital) instead.
        $usd = $this->api('boxes', ['name' => 'USD safe', 'kind' => 'cash', 'currency_code' => 'USD', 'opening_amount' => 300, 'opening_account_id' => $this->capital->id]);
        $this->assertEquals(300, $usd['balance']);
        // The counter account must be equity; nothing is created when it fails.
        $this->api('boxes', ['name' => 'Bad', 'kind' => 'cash', 'currency_code' => 'USD', 'opening_amount' => 10, 'opening_account_id' => $this->rent->id], 422);
        $this->assertFalse(Box::where('name', 'Bad')->exists());
        // Without an amount the box starts empty, as before.
        $this->assertEquals(0, $this->api('boxes', ['name' => 'Empty', 'kind' => 'sham_cash', 'currency_code' => 'USD'])['balance']);
    }

    public function test_box_parent_uses_the_same_rules_as_chart_accounts(): void
    {
        $cash = $this->api('accounts', ['code' => '1100', 'name' => 'النقد وما في حكمه', 'type' => 'asset']);
        $ok = $this->api('boxes', ['name' => 'Main till', 'kind' => 'cash', 'currency_code' => 'SYP', 'parent_account_id' => $cash['id']]);
        $this->assertSame($cash['id'], Account::find(Box::find($ok['id'])->account_id)->parent_id);

        // Wrong type, system account, another box, or a parent that already has postings.
        $this->api('boxes', ['name' => 'Under equity', 'kind' => 'cash', 'currency_code' => 'SYP', 'parent_account_id' => $this->capital->id], 422);
        $receivables = Account::where('system_key', 'receivables')->firstOrFail();
        $this->api('boxes', ['name' => 'Under system', 'kind' => 'cash', 'currency_code' => 'SYP', 'parent_account_id' => $receivables->id], 422);
        $this->api('boxes', ['name' => 'Under box', 'kind' => 'cash', 'currency_code' => 'SYP', 'parent_account_id' => Box::find($ok['id'])->account_id], 422);

        $posted = $this->api('accounts', ['code' => '1110', 'name' => 'نقد مرحّل', 'type' => 'asset', 'parent_id' => $cash['id']]);
        $this->api('entries', ['date' => '2026-11-02', 'currency_code' => 'SYP', 'description' => 'Seed',
            'lines' => [['account_id' => $posted['id'], 'debit' => 10], ['account_id' => $this->capital->id, 'credit' => 10]]]);
        $this->api('boxes', ['name' => 'Under posted', 'kind' => 'cash', 'currency_code' => 'SYP', 'parent_account_id' => $posted['id']], 422);
        $this->assertFalse(Box::where('name', 'Under posted')->exists());
    }

    public function test_parties_carry_a_sham_cash_wallet(): void
    {
        $supplier = $this->api('parties', ['kind' => 'supplier', 'name' => 'Steel Co.', 'sham_cash_wallet' => '1234567890123456', 'sham_cash_name' => 'Steel Co. LLC']);
        $this->assertSame(['1234567890123456', 'Steel Co. LLC'], [$supplier['sham_cash_wallet'], $supplier['sham_cash_name']]);
        $this->api('parties', ['kind' => 'supplier', 'name' => 'Bad', 'sham_cash_wallet' => '12345'], 422);
        $this->patchJson('/api/v1/finance/parties/'.$supplier['id'], ['sham_cash_wallet' => '12AB567890123456'])->assertStatus(422);
        $this->patchJson('/api/v1/finance/parties/'.$supplier['id'], ['sham_cash_wallet' => '6543210987654321'])->assertOk()
            ->assertJsonPath('data.sham_cash_wallet', '6543210987654321');
    }

    public function test_invoice_lines_have_units_and_invoices_take_attachments(): void
    {
        Storage::fake('local');
        $this->box('USD', 5_000);
        $supplier = $this->api('parties', ['kind' => 'supplier', 'name' => 'Cables Co.']);
        $cement = Item::find($this->api('items', ['name' => 'Cement bag', 'unit' => 'bag', 'currency_code' => 'USD'])['id']);
        $invoice = $this->api('invoices', ['type' => 'purchase', 'party_id' => $supplier['id'], 'date' => '2026-11-02', 'currency_code' => 'USD',
            'lines' => [['description' => 'Cable', 'unit' => 'متر', 'quantity' => 12.5, 'unit_price' => 4, 'account_id' => $this->rent->id],
                ['item_id' => $cement->id, 'quantity' => 3, 'unit_price' => 10]]]);
        $this->assertSame(['متر', 'bag'], array_column($invoice['lines'], 'unit'));
        $this->assertSame(['50.00', '30.00'], array_column($invoice['lines'], 'amount'), 'quantity × unit price');
        $this->assertSame('80.00', $invoice['total']);
        // A stock item is counted in its own unit only.
        $this->api('invoices', ['type' => 'purchase', 'party_id' => $supplier['id'], 'date' => '2026-11-02', 'currency_code' => 'USD',
            'lines' => [['item_id' => $cement->id, 'unit' => 'kg', 'quantity' => 3, 'unit_price' => 10]]], 422);

        $photo = $this->post('/api/v1/finance/invoices/'.$invoice['id'].'/attachments', ['file' => UploadedFile::fake()->image('scan.jpg')])
            ->assertCreated()->json('data');
        $this->assertArrayNotHasKey('path', $photo);
        $this->post('/api/v1/finance/invoices/'.$invoice['id'].'/attachments', ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422);
        $this->getJson('/api/v1/finance/invoices/'.$invoice['id'])->assertJsonPath('data.attachments.0.original_name', 'scan.jpg');
        $this->get('/api/v1/finance/invoices/'.$invoice['id'].'/attachments/'.$photo['id'])->assertOk();
        // Posted invoices keep their documents.
        $this->api('invoices/'.$invoice['id'].'/submit', [], 200);
        $this->deleteJson('/api/v1/finance/invoices/'.$invoice['id'].'/attachments/'.$photo['id'])->assertStatus(409);
        // A user without finance access cannot read them.
        Sanctum::actingAs(User::factory()->create());
        $this->get('/api/v1/finance/invoices/'.$invoice['id'].'/attachments/'.$photo['id'])->assertForbidden();
    }

    public function test_manual_entries_post_with_differences(): void
    {
        $diff = Account::where('system_key', 'entry_differences')->firstOrFail();
        $entry = fn (array $lines) => ['date' => '2026-11-02', 'currency_code' => 'USD', 'description' => 'Settlement', 'lines' => $lines];
        // Debit 100, credit 90: 10 in the company's favour (credited to differences, a gain).
        $gain = $this->api('entries', $entry([['account_id' => $this->rent->id, 'debit' => 100], ['account_id' => $this->capital->id, 'credit' => 90]]));
        $this->assertSame('10.00', $gain['difference']);
        $this->assertSame('10.00', collect($gain['lines'])->firstWhere('account_id', $diff->id)['credit']);
        // Credit above debit: in the customer's favour (negative).
        $loss = $this->api('entries', $entry([['account_id' => $this->rent->id, 'debit' => 40], ['account_id' => $this->capital->id, 'credit' => 55]]));
        $this->assertSame('-15.00', $loss['difference']);
        $this->assertSame('15.00', collect($loss['lines'])->firstWhere('account_id', $diff->id)['debit']);
        // A single line is enough; a balanced entry has no difference.
        $single = $this->api('entries', $entry([['account_id' => $this->rent->id, 'debit' => 25]]));
        $this->assertSame(['25.00', 2], [$single['difference'], count($single['lines'])]);
        $this->assertNull($this->api('entries', $entry([['account_id' => $this->rent->id, 'debit' => 5], ['account_id' => $this->capital->id, 'credit' => 5]]))['difference']);
        // Reversal mirrors every line, the difference too.
        $reversal = $this->api('entries/'.$gain['id'].'/reverse', ['date' => '2026-11-03', 'reason' => 'Wrong amount']);
        $this->assertSame('-10.00', $reversal['difference']);
        $this->assertSame(0.0, (float) JournalLine::where('account_id', $diff->id)->whereIn('journal_entry_id', [$gain['id'], $reversal['id']])->sum(DB::raw('credit - debit')));
        // Generated entries still need exact balance; a manual entry still cannot touch a box below zero.
        $usd = $this->box('USD');
        $this->api('entries', $entry([['account_id' => $usd->account_id, 'credit' => 5]]), 422);
    }
}
