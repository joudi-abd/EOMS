<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\AssetCategory;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\HR\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Settings\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\TestCase;

/** Fixed assets (phase 9, D22, no depreciation): purchase #22, maintenance #23, sale #24, scrap/loss #25, custody, opening, T20. */
class AssetsTest extends TestCase
{
    use FinanceFixture, RefreshDatabase;

    private CashBox $safe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->safe = $this->box('Safe', ['USD' => null, 'TRY' => null]);
        $this->fund($this->safe, 'USD', 50000);
    }

    private function purchase(array $extra = [], int $status = 201): ?array
    {
        $response = $this->postJson('/api/v1/finance/assets/purchase', [...['name' => 'سيارة كيا', 'category_id' => $this->category('سيارات'),
            'quantity' => 1, 'unit_cost' => 1000, 'currency' => 'USD', 'purchase_date' => '2026-11-05', 'branch_id' => $this->hq->id,
            'cash_box_id' => $this->safe->id], ...$extra])->assertStatus($status);

        return $status < 300 ? $response->json('data') : null;
    }

    private function category(string $name): int
    {
        return AssetCategory::where('name', $name)->value('id');
    }

    private function employee(string $number, string $first, string $last, string $status = 'active'): Employee
    {
        return Employee::create(['employee_no' => $number, 'first_name' => $first, 'last_name' => $last, 'national_id' => $number,
            'national_id_hash' => hash('sha256', $number), 'hire_date' => '2026-01-01', 'status' => $status]);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: int|null}> code, debit, credit, asset per line of a posted voucher */
    private function entryLines(int $voucherId): array
    {
        $voucher = Voucher::findOrFail($voucherId);

        return JournalLine::where('journal_entry_id', $voucher->journal_entry_id)->orderBy('line_no')->with('account')->get()
            ->map(fn (JournalLine $l) => [$l->account->code, (string) (float) $l->debit, (string) (float) $l->credit, $l->asset_id])->all();
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('assets.csv', $content);
    }

    public function test_purchase_posts_entry_22_and_numbers_assets_in_sequence(): void
    {
        $car = $this->purchase(['unit_cost' => 15500]);
        $laptops = $this->purchase(['name' => 'لابتوب', 'category_id' => $this->category('أجهزة حاسوب'), 'quantity' => 3, 'unit_cost' => 500.555]);

        $this->assertSame(['AS-0001', 'AS-0002'], [$car['asset']['code'], $laptops['asset']['code']]);
        $this->assertSame(['in_service', '1501.6700', '1501.67'], [$laptops['asset']['status'], $laptops['asset']['total_cost'], $laptops['asset']['book_value']]);
        $this->assertSame(['asset_purchase', 'approved', 'AP-2026-000001'], [$car['voucher']['type'], $car['voucher']['status'], $car['voucher']['number']]);
        $this->assertSame([['1800', '15500', '0', $car['asset']['id']], ['1100-'.$this->safe->id, '0', '15500', null]], $this->entryLines($car['voucher']['id']));
        $this->assertSame($car['voucher']['id'], Asset::findOrFail($car['asset']['id'])->purchase_voucher_id);
        $this->assertSame('17001.67', $this->roleBalance('fixed_assets', 'USD'));
        $this->assertSame('32998.33', $this->boxBalance($this->safe, 'USD'));

        // The box must hold the asset's currency and enough money (R7/R8 through the ledger).
        $this->purchase(['currency' => 'SYP'], 422);
        $this->purchase(['unit_cost' => 40000], 422);
        $this->assertSame(2, Asset::count());
    }

    public function test_a_draft_purchase_waits_for_approval_and_can_be_deleted_with_its_asset(): void
    {
        $draft = $this->purchase(['approve' => false]);
        $this->assertSame(['draft', '0', 'draft'], [$draft['voucher']['status'], $draft['asset']['book_value'], $draft['asset']['acquisition']['status']]);
        $holder = $this->employee('E-1', 'Ali', 'Hasan');
        $this->postJson('/api/v1/finance/assets/'.$draft['asset']['id'].'/transfer', ['to_employee_id' => $holder->id, 'date' => '2026-11-06'])
            ->assertUnprocessable()->assertJsonValidationErrors('asset_id');

        $this->postJson('/api/v1/finance/vouchers/'.$draft['voucher']['id'].'/approve')->assertOk();
        $this->assertSame($draft['voucher']['id'], Asset::findOrFail($draft['asset']['id'])->purchase_voucher_id);
        $this->deleteJson('/api/v1/finance/assets/'.$draft['asset']['id'])->assertConflict();

        $other = $this->purchase(['name' => 'طابعة', 'approve' => false]);
        $this->deleteJson('/api/v1/finance/assets/'.$other['asset']['id'])->assertOk();
        $this->assertNull(Asset::find($other['asset']['id']));
        $this->assertNull(Voucher::find($other['voucher']['id']));
    }

    public function test_maintenance_posts_to_asset_maintenance_or_to_the_project_as_asset_operation(): void
    {
        $asset = $this->purchase()['asset'];
        $project = Project::create(['name' => 'شقق القاسم', 'status' => 'active', 'branch_id' => $this->hq->id]);
        $plain = $this->postJson('/api/v1/finance/assets/'.$asset['id'].'/maintenance', ['cash_box_id' => $this->safe->id, 'currency' => 'USD',
            'amount' => 200, 'date' => '2026-11-08', 'description' => 'تبديل زيت'])->assertCreated()->json('data');
        $onProject = $this->postJson('/api/v1/finance/assets/'.$asset['id'].'/maintenance', ['cash_box_id' => $this->safe->id, 'currency' => 'USD',
            'amount' => 300, 'date' => '2026-11-09', 'description' => 'إصلاح بالموقع', 'project_id' => $project->id])->assertCreated()->json('data');

        $this->assertSame(['AM-2026-000001', [['5600', '200', '0', $asset['id']], ['1100-'.$this->safe->id, '0', '200', null]]],
            [$plain['voucher']['number'], $this->entryLines($plain['voucher']['id'])]);
        $projectLine = JournalLine::where('journal_entry_id', Voucher::find($onProject['voucher']['id'])->journal_entry_id)->where('debit', '>', 0)->firstOrFail();
        $this->assertSame([$this->roleId('project_costs'), $project->id, ProjectCostCategory::where('system_key', 'asset_operation')->value('id'), $asset['id']],
            [$projectLine->account_id, $projectLine->project_id, $projectLine->project_cost_category_id, $projectLine->asset_id]);

        // An operating expense carrying the asset (here a manual journal line: fuel) shows on the card with the maintenance.
        $this->voucher(['type' => 'manual_journal', 'lines' => [['account_id' => $this->accountId('6104'), 'currency' => 'USD', 'debit' => 75, 'asset_id' => $asset['id']],
            ['account_id' => $this->accountId('3100'), 'currency' => 'USD', 'credit' => 75]]]);
        $card = $this->getJson('/api/v1/finance/assets/'.$asset['id'])->assertOk()->json('data');
        $this->assertSame([['date' => '2026-11-09', 'project_id' => $project->id], ['date' => '2026-11-08', 'project_id' => null]],
            array_map(fn ($m) => ['date' => $m['date'], 'project_id' => $m['project_id']], $card['maintenance']));
        $this->assertSame(['operating' => ['USD' => '75'], 'maintenance' => ['USD' => '500']], $card['expenses']['totals']);
        $this->assertCount(3, $card['expenses']['lines']);

        // Reversing a maintenance removes its record; the status toggle needs no entry.
        $this->postJson('/api/v1/finance/vouchers/'.$plain['voucher']['id'].'/reverse', ['date' => '2026-11-10', 'reason' => 'خطأ'])->assertOk();
        $this->assertSame(['USD' => '300'], $this->getJson('/api/v1/finance/assets/'.$asset['id'])->json('data.expenses.totals.maintenance'));
        $this->postJson('/api/v1/finance/assets/'.$asset['id'].'/status', ['status' => 'under_maintenance'])->assertOk()->assertJsonPath('data.status', 'under_maintenance');
        $this->postJson('/api/v1/finance/assets/'.$asset['id'].'/status', ['status' => 'sold'])->assertUnprocessable();
    }

    public function test_sale_books_the_gain_or_the_loss_and_requires_the_asset_currency(): void
    {
        $gain = $this->purchase()['asset'];
        $loss = $this->purchase(['name' => 'مولدة', 'category_id' => $this->category('مولدات')])['asset'];

        $this->postJson('/api/v1/finance/assets/'.$gain['id'].'/dispose', ['type' => 'sale', 'date' => '2026-11-12', 'sale_amount' => 1200,
            'currency' => 'TRY', 'cash_box_id' => $this->safe->id])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $sold = $this->postJson('/api/v1/finance/assets/'.$gain['id'].'/dispose', ['type' => 'sale', 'date' => '2026-11-12', 'sale_amount' => 1200,
            'cash_box_id' => $this->safe->id])->assertCreated()->json('data');
        $this->assertSame([['1100-'.$this->safe->id, '1200', '0', null], ['1800', '0', '1000', $gain['id']], ['4200', '0', '200', $gain['id']]],
            $this->entryLines($sold['voucher']['id']));
        $this->assertSame(['sold', '0', 'sale'], [$sold['asset']['status'], $sold['asset']['book_value'], $sold['asset']['disposals'][0]['type']]);

        $lost = $this->postJson('/api/v1/finance/assets/'.$loss['id'].'/dispose', ['type' => 'sale', 'date' => '2026-11-12', 'sale_amount' => 700,
            'cash_box_id' => $this->safe->id])->assertCreated()->json('data');
        $this->assertSame([['1100-'.$this->safe->id, '700', '0', null], ['1800', '0', '1000', $loss['id']], ['4200', '300', '0', $loss['id']]],
            $this->entryLines($lost['voucher']['id']));
        $this->assertSame('0', $this->roleBalance('fixed_assets', 'USD'));
        $this->assertSame('100', $this->roleBalance('asset_disposal_gain_loss', 'USD')); // net loss 100 (debit)

        // A disposed asset accepts no further operation.
        $this->postJson('/api/v1/finance/assets/'.$gain['id'].'/dispose', ['type' => 'scrap', 'date' => '2026-11-13'])->assertUnprocessable();
        $this->postJson('/api/v1/finance/assets/'.$gain['id'].'/maintenance', ['cash_box_id' => $this->safe->id, 'currency' => 'USD', 'amount' => 5,
            'date' => '2026-11-13', 'description' => 'x'])->assertUnprocessable();
    }

    public function test_scrap_and_loss_write_off_the_cost_and_reversal_puts_the_asset_back_in_service(): void
    {
        $scrapped = $this->purchase()['asset'];
        $missing = $this->purchase(['name' => 'درون'])['asset'];

        $scrap = $this->postJson('/api/v1/finance/assets/'.$scrapped['id'].'/dispose', ['type' => 'scrap', 'date' => '2026-11-12', 'notes' => 'تالف'])
            ->assertCreated()->json('data');
        $this->assertSame([['4200', '1000', '0', $scrapped['id']], ['1800', '0', '1000', $scrapped['id']]], $this->entryLines($scrap['voucher']['id']));
        $this->assertSame(['scrapped', 'AD-2026-000001'], [$scrap['asset']['status'], $scrap['voucher']['number']]);
        $this->postJson('/api/v1/finance/assets/'.$missing['id'].'/dispose', ['type' => 'loss', 'date' => '2026-11-12'])
            ->assertCreated()->assertJsonPath('data.asset.status', 'lost');
        $this->postJson('/api/v1/finance/assets/'.$missing['id'].'/dispose', ['type' => 'scrap', 'date' => '2026-11-12', 'sale_amount' => 5])
            ->assertUnprocessable();

        $this->postJson('/api/v1/finance/vouchers/'.$scrap['voucher']['id'].'/reverse', ['date' => '2026-11-14', 'reason' => 'أُصلح'])->assertOk();
        $card = $this->getJson('/api/v1/finance/assets/'.$scrapped['id'])->json('data');
        $this->assertSame(['in_service', [], '1000'], [$card['status'], $card['disposals'], $card['book_value']]);
        $this->assertSame('1000', $this->roleBalance('fixed_assets', 'USD'));
    }

    public function test_custody_transfer_records_the_history_without_an_entry(): void
    {
        $ali = $this->employee('E-1', 'Ali', 'Hasan');
        $omar = $this->employee('E-2', 'Omar', 'Saleh');
        $gone = $this->employee('E-3', 'Sami', 'Old', 'terminated');
        $asset = $this->purchase(['holder_employee_id' => $ali->id])['asset'];
        $this->purchase(['holder_employee_id' => $gone->id], 422);
        $entries = JournalLine::count();

        $card = $this->postJson('/api/v1/finance/assets/'.$asset['id'].'/transfer', ['to_employee_id' => $omar->id, 'date' => '2026-11-10', 'notes' => 'للموقع'])
            ->assertOk()->json('data');
        $this->assertSame(['Omar Saleh', 'Ali Hasan', 'Omar Saleh', '2026-11-10'], [$card['holder']['name'], $card['custody_history'][0]['from']['name'],
            $card['custody_history'][0]['to']['name'], $card['custody_history'][0]['transferred_at']]);
        $this->assertSame($entries, JournalLine::count());
        $this->postJson('/api/v1/finance/assets/'.$asset['id'].'/transfer', ['to_employee_id' => $omar->id, 'date' => '2026-11-11'])->assertUnprocessable();
        $this->postJson('/api/v1/finance/assets/'.$asset['id'].'/transfer', ['to_employee_id' => $gone->id, 'date' => '2026-11-11'])->assertUnprocessable();
        $this->assertSame([$asset['id']], array_column($this->getJson('/api/v1/finance/assets?holder_employee_id='.$omar->id)->json('data'), 'id'));

        // Descriptive edits only.
        $this->patchJson('/api/v1/finance/assets/'.$asset['id'], ['name' => 'سيارة كيا ريو', 'serial_no' => 'SN-9', 'total_cost' => 1])
            ->assertOk()->assertJsonPath('data.name', 'سيارة كيا ريو')->assertJsonPath('data.serial_no', 'SN-9')->assertJsonPath('data.total_cost', '1000.0000');
    }

    public function test_purchase_reversal_cancels_the_asset_unless_it_has_later_operations(): void
    {
        $plain = $this->purchase();
        $this->postJson('/api/v1/finance/vouchers/'.$plain['voucher']['id'].'/reverse', ['date' => '2026-11-10', 'reason' => 'إلغاء'])->assertOk();
        $this->assertSame('cancelled', Asset::findOrFail($plain['asset']['id'])->status);
        $this->assertSame([], $this->getJson('/api/v1/finance/assets')->json('data'));
        $this->assertSame([$plain['asset']['id']], array_column($this->getJson('/api/v1/finance/assets?status=cancelled')->json('data'), 'id'));
        $this->postJson('/api/v1/finance/assets/'.$plain['asset']['id'].'/status', ['status' => 'under_maintenance'])->assertUnprocessable();

        $maintained = $this->purchase(['name' => 'مولدة']);
        $repair = $this->postJson('/api/v1/finance/assets/'.$maintained['asset']['id'].'/maintenance', ['cash_box_id' => $this->safe->id, 'currency' => 'USD',
            'amount' => 50, 'date' => '2026-11-08', 'description' => 'صيانة'])->assertCreated()->json('data');
        $this->postJson('/api/v1/finance/vouchers/'.$maintained['voucher']['id'].'/reverse', ['date' => '2026-11-10', 'reason' => 'إلغاء'])->assertConflict();
        $this->assertSame(['approved', 'in_service'], [Voucher::find($maintained['voucher']['id'])->status, Asset::find($maintained['asset']['id'])->status]);

        $this->postJson('/api/v1/finance/vouchers/'.$repair['voucher']['id'].'/reverse', ['date' => '2026-11-10', 'reason' => 'خطأ'])->assertOk();
        $this->postJson('/api/v1/finance/vouchers/'.$maintained['voucher']['id'].'/reverse', ['date' => '2026-11-10', 'reason' => 'إلغاء'])->assertOk();
        $this->assertSame('0', $this->roleBalance('fixed_assets', 'USD'));

        $transferred = $this->purchase(['name' => 'لابتوب']);
        $this->postJson('/api/v1/finance/assets/'.$transferred['asset']['id'].'/transfer', ['to_employee_id' => $this->employee('E-1', 'Ali', 'Hasan')->id,
            'date' => '2026-11-09'])->assertOk();
        $this->postJson('/api/v1/finance/vouchers/'.$transferred['voucher']['id'].'/reverse', ['date' => '2026-11-10', 'reason' => 'إلغاء'])->assertConflict();
    }

    public function test_a_branch_accountant_sees_and_operates_only_the_assigned_branch_assets(): void
    {
        $idlib = Branch::create(['name' => 'فرع إدلب', 'code' => 'IDL']);
        $idlibSafe = $this->box('Idlib safe', ['USD' => null], $idlib->id);
        $this->fund($idlibSafe, 'USD', 5000);
        $hqAsset = $this->purchase()['asset'];
        $idlibAsset = $this->purchase(['name' => 'مولدة إدلب', 'branch_id' => $idlib->id, 'cash_box_id' => $idlibSafe->id])['asset'];
        $accountant = $this->actor('branch_accountant');
        $this->putJson('/api/v1/finance/users/'.$accountant->id.'/branches', ['branch_ids' => [$idlib->id]])->assertOk();

        $this->login($accountant);
        $this->assertSame([$idlibAsset['id']], array_column($this->getJson('/api/v1/finance/assets')->assertOk()->json('data'), 'id'));
        $this->getJson('/api/v1/finance/assets/'.$hqAsset['id'])->assertForbidden();
        $this->postJson('/api/v1/finance/assets/'.$hqAsset['id'].'/dispose', ['type' => 'scrap', 'date' => '2026-11-12'])->assertForbidden();
        $this->purchase(['branch_id' => $idlib->id], 403);
        $this->purchase(['name' => 'شاشة', 'branch_id' => $idlib->id, 'cash_box_id' => $idlibSafe->id, 'unit_cost' => 100]);
        $this->postJson('/api/v1/finance/asset-categories', ['name' => 'طابعات'])->assertForbidden();

        // The company manager views the register and the T20 report (spec §8: assets — view) but operates nothing.
        $this->login($this->director);
        $this->assertCount(3, $this->getJson('/api/v1/finance/assets')->assertOk()->json('data'));
        $this->getJson('/api/v1/finance/assets/'.$hqAsset['id'])->assertOk();
        $this->purchase(['name' => 'طابعة'], 403);
        $this->postJson('/api/v1/finance/assets/'.$hqAsset['id'].'/transfer', ['to_employee_id' => 1, 'date' => '2026-11-12'])->assertForbidden();
        $report = $this->getJson('/api/v1/finance/reports/asset-register?group_by=branch')->assertOk()->json('data');
        $this->assertSame([[$this->hq->id, 1, ['USD' => '1000']], [$idlib->id, 2, ['USD' => '1100']]],
            array_map(fn ($g) => [$g['key'], $g['count'], $g['cost']], $report['groups']));
    }

    public function test_register_report_groups_assets_with_their_operating_and_maintenance_expenses(): void
    {
        $car = $this->purchase()['asset'];
        $this->purchase(['name' => 'لابتوب', 'category_id' => $this->category('أجهزة حاسوب'), 'unit_cost' => 600]);
        $this->postJson('/api/v1/finance/assets/'.$car['id'].'/maintenance', ['cash_box_id' => $this->safe->id, 'currency' => 'USD', 'amount' => 120,
            'date' => '2026-11-08', 'description' => 'إطارات'])->assertCreated();
        $this->fund($this->safe, 'TRY', 5000);
        $this->voucher(['type' => 'manual_journal', 'lines' => [['account_id' => $this->accountId('6104'), 'currency' => 'TRY', 'debit' => 900, 'asset_id' => $car['id']],
            ['account_id' => $this->accountId('3100'), 'currency' => 'TRY', 'credit' => 900]]]);

        $report = $this->getJson('/api/v1/finance/reports/asset-register?group_by=category')->assertOk()->json('data');
        $cars = collect($report['groups'])->firstWhere('key', $this->category('سيارات'));
        $this->assertSame([1, ['TRY' => '900'], ['USD' => '120']], [$cars['count'], $cars['operating_expenses'], $cars['maintenance_expenses']]);
        $this->assertSame([$car['code']], array_column($this->getJson('/api/v1/finance/reports/asset-register?category_id='.$this->category('سيارات'))
            ->json('data.assets'), 'code'));
    }

    public function test_opening_assets_post_against_opening_equity_and_a_csv_import_is_all_or_nothing(): void
    {
        $ali = $this->employee('E-7', 'Ali', 'Hasan');
        $opening = $this->postJson('/api/v1/finance/assets/opening', ['name' => 'سيارة قائمة', 'unit_cost' => 15500, 'currency' => 'USD',
            'branch_id' => $this->hq->id, 'date' => '2026-10-01', 'purchase_date' => '2024-03-01', 'holder_employee_id' => $ali->id])->assertCreated()->json('data');
        $this->assertSame(['opening_balance', 'AS-0001', '2024-03-01'], [$opening['voucher']['type'], $opening['asset']['code'], $opening['asset']['purchase_date']]);
        $this->assertSame([['1800', '15500', '0', $opening['asset']['id']], ['3900', '0', '15500', null]], $this->entryLines($opening['voucher']['id']));

        $bad = "name,category,quantity,unit_cost,currency,purchase_date,holder,payment_method,status\n"
            ."لابتوب,أجهزة حاسوب,2,800,USD,2025-01-10,E-7,نقدي,في الخدمة\n"
            ."درون,غير موجود,1,abc,XYZ,2025-13-01,مجهول,,مباع\n";
        $errors = $this->post('/api/v1/finance/assets/import', ['file' => $this->csv($bad), 'date' => '2026-10-01', 'branch_id' => $this->hq->id],
            ['Accept' => 'application/json'])->assertUnprocessable()->json('errors');
        $this->assertSame(['rows.3'], array_keys($errors));
        $this->assertCount(6, $errors['rows.3']);
        $this->assertSame(1, Asset::count());

        $good = "name,category,quantity,unit_cost,currency,purchase_date,holder,payment_method,status\n"
            ."لابتوب,أجهزة حاسوب,2,800,USD,2025-01-10,E-7,نقدي,في الخدمة\n"
            ."مولدة,مولدات,1,43400,TRY,,Ali Hasan,شام كاش,قيد الصيانة\n";
        $import = $this->post('/api/v1/finance/assets/import', ['file' => $this->csv($good), 'date' => '2026-10-01', 'branch_id' => $this->hq->id],
            ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame([2, ['AS-0002', 'AS-0003'], [$ali->id, $ali->id], ['in_service', 'under_maintenance']], [$import['count'],
            array_column($import['assets'], 'code'), array_column($import['assets'], 'current_holder_employee_id'), array_column($import['assets'], 'status')]);
        $this->assertSame(['1600', '43400'], [$import['assets'][0]['book_value'], $import['assets'][1]['book_value']]);
        $this->assertSame('-67100', $this->roleBalance('opening_balance_equity', 'USD')); // 50,000 box + 17,100 assets
        $this->assertSame('-43400', $this->roleBalance('opening_balance_equity', 'TRY'));
    }

    public function test_asset_categories_form_a_tree_managed_with_finance_settings(): void
    {
        $parent = $this->postJson('/api/v1/finance/asset-categories', ['name' => 'معدات ثقيلة'])->assertCreated()->json('data');
        $child = $this->postJson('/api/v1/finance/asset-categories', ['name' => 'رافعات', 'parent_id' => $parent['id']])->assertCreated()->json('data');
        $this->postJson('/api/v1/finance/asset-categories', ['name' => 'رافعات', 'parent_id' => $parent['id']])->assertUnprocessable();
        $this->patchJson('/api/v1/finance/asset-categories/'.$parent['id'], ['parent_id' => $child['id']])->assertUnprocessable();
        $this->purchase(['category_id' => $child['id']]);
        $this->assertCount(1, $this->getJson('/api/v1/finance/assets?category_id='.$parent['id'])->json('data'));
        $this->deleteJson('/api/v1/finance/asset-categories/'.$child['id'])->assertConflict();
        $this->deleteJson('/api/v1/finance/asset-categories/'.$parent['id'])->assertConflict();
        $row = collect($this->getJson('/api/v1/finance/asset-categories')->assertOk()->json('data'))->firstWhere('id', $parent['id']);
        $this->assertSame([1, 0], [$row['children_count'], $row['assets_count']]);
    }
}
