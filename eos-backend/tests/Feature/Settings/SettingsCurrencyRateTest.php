<?php

namespace Tests\Feature\Settings;

use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Database\Seeders\BranchSeeder;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use App\Modules\Settings\Database\Seeders\SettingsSeeder;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Models\ExchangeRate;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Services\CurrencyService;
use App\Modules\Settings\Services\ExchangeRateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Currencies (D3), the daily bulletin against USD (D7) and branches (OP-10). */
class SettingsCurrencyRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, CurrencySeeder::class, BranchSeeder::class]);
    }

    private function login(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_currencies_carry_decimals_rounding_base_and_payroll_flags(): void
    {
        $this->getJson('/api/v1/settings/currencies')->assertUnauthorized();
        $this->login('employee');
        $rows = collect($this->getJson('/api/v1/settings/currencies')->assertOk()->json('data'))->keyBy('code');

        $this->assertSame('USD', $rows->keys()->first(), 'base currency first');
        $this->assertSame([2, '1.0000', true, true], [$rows['USD']['decimals'], $rows['USD']['rounding_step'], $rows['USD']['is_base'], $rows['USD']['is_payroll_currency']]);
        $this->assertSame([0, '1000.0000', false, true], [$rows['SYP']['decimals'], $rows['SYP']['rounding_step'], $rows['SYP']['is_base'], $rows['SYP']['is_payroll_currency']]);
        $this->assertFalse($rows['TRY']['is_payroll_currency']);
        $this->assertSame(['SYP', 'USD'], app(CurrencyService::class)->payrollCodes());
    }

    public function test_finance_settings_manage_currencies_and_the_base_never_changes(): void
    {
        $this->login('hr_manager');
        $this->postJson('/api/v1/settings/currencies', ['code' => 'EUR', 'name' => 'يورو', 'symbol' => '€', 'decimals' => 2, 'rounding_step' => 1])->assertForbidden();

        $this->login('finance_manager');
        $eur = $this->postJson('/api/v1/settings/currencies', ['code' => 'eur', 'name' => 'يورو', 'symbol' => '€', 'decimals' => 2, 'rounding_step' => 1])
            ->assertCreated()->assertJsonPath('data.code', 'EUR')->json('data');
        $this->postJson('/api/v1/settings/currencies', ['code' => 'GBP', 'name' => 'جنيه', 'symbol' => '£', 'decimals' => 2, 'rounding_step' => 1, 'is_base' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_base');
        $this->patchJson('/api/v1/settings/currencies/'.$eur['id'], ['is_base' => true])->assertUnprocessable();

        $usd = Currency::where('code', 'USD')->value('id');
        $this->patchJson('/api/v1/settings/currencies/'.$usd, ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->patchJson('/api/v1/settings/currencies/'.$usd, ['is_base' => false])->assertUnprocessable();
        $this->patchJson('/api/v1/settings/currencies/'.$eur['id'], ['is_active' => false, 'rounding_step' => 5])->assertOk()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.rounding_step', '5.0000');
        $this->assertDatabaseHas('usr_audit_logs', ['action' => 'settings.currency_updated']);
    }

    public function test_bulletin_is_saved_per_day_replaced_on_the_same_day_and_lists_missing_currencies(): void
    {
        $this->login('employee');
        $this->postJson('/api/v1/settings/exchange-rates', ['date' => '2026-11-10', 'rates' => ['SYP' => 13000]])->assertForbidden();

        $this->login('finance_manager');
        $this->postJson('/api/v1/settings/exchange-rates', ['date' => '2026-11-10', 'rates' => ['SYP' => 13000]])->assertCreated()
            ->assertJsonPath('data.base', 'USD')->assertJsonPath('data.missing', ['TRY'])->assertJsonPath('data.rates.0.rate_to_base', '13000.00000000');
        $this->postJson('/api/v1/settings/exchange-rates', ['date' => '2026-11-10', 'rates' => ['SYP' => 13100, 'TRY' => 43.4]])->assertCreated()
            ->assertJsonPath('data.missing', []);
        $this->assertSame(2, ExchangeRate::count());
        $this->assertSame('13100.00000000', app(ExchangeRateService::class)->rateToBase('SYP', '2026-11-10'));

        $this->postJson('/api/v1/settings/exchange-rates', ['date' => '2026-11-10', 'rates' => ['USD' => 1]])->assertUnprocessable()->assertJsonValidationErrors('rates.USD');
        $this->postJson('/api/v1/settings/exchange-rates', ['date' => '2026-11-10', 'rates' => ['SYP' => -1]])->assertUnprocessable();
        $this->postJson('/api/v1/settings/exchange-rates', ['date' => '2026-11-10', 'rates' => ['XXX' => 3]])->assertUnprocessable()->assertJsonValidationErrors('rates.XXX');

        $this->getJson('/api/v1/settings/exchange-rates?date=2026-11-10')->assertOk()->assertJsonCount(2, 'data.rates');
        $this->getJson('/api/v1/settings/exchange-rates?date=2026-11-11')->assertOk()->assertJsonPath('data.missing', ['SYP', 'TRY']);
        $this->assertDatabaseHas('usr_audit_logs', ['action' => 'fx.rate_set']);
    }

    public function test_copy_previous_fills_only_missing_currencies_from_the_latest_earlier_day(): void
    {
        $this->login('finance_manager');
        $rates = app(ExchangeRateService::class);
        $rates->setRates('2026-11-08', ['SYP' => 12900, 'TRY' => 43]);
        $rates->setRates('2026-11-09', ['SYP' => 13000]);
        $rates->setRates('2026-11-10', ['TRY' => 44]);

        $bulletin = $this->postJson('/api/v1/settings/exchange-rates/copy-previous', ['date' => '2026-11-10'])->assertOk()->json('data');
        $this->assertSame(['SYP' => '13000.00000000', 'TRY' => '44.00000000'], collect($bulletin['rates'])->pluck('rate_to_base', 'code')->all());
    }

    public function test_rates_on_exact_day_on_or_before_and_cross_rates(): void
    {
        $rates = app(ExchangeRateService::class);
        $rates->setRates('2026-11-10', ['SYP' => 13000, 'TRY' => 43.4]);

        $this->assertSame(13000.0, $rates->rateOn('USD', 'SYP', '2026-11-10'));
        $this->assertEqualsWithDelta(1 / 13000, $rates->rateOn('SYP', 'USD', '2026-11-10'), 1e-12);
        $this->assertEqualsWithDelta(13000 / 43.4, $rates->rateOn('TRY', 'SYP', '2026-11-10'), 1e-6);
        $this->assertNull($rates->rateOn('USD', 'SYP', '2026-11-11'), 'payroll never guesses with an older rate');
        $this->assertSame(['rate' => '13000.00000000', 'date' => '2026-11-10'], $rates->rateToBaseOnOrBefore('SYP', '2026-11-15'));
        $this->assertNull($rates->rateToBaseOnOrBefore('SYP', '2026-11-09'));
        $this->assertSame(['rate' => '1', 'date' => '2026-01-01'], $rates->rateToBaseOnOrBefore('USD', '2026-01-01'));
        $this->assertSame('43.40000000', $rates->crossRateOnOrBefore('USD', 'TRY', '2026-11-20'));

        $this->login('employee');
        $rates->setRates('2026-11-11', ['SYP' => 13100]);
        $history = $this->getJson('/api/v1/settings/exchange-rates/history?currency=SYP')->assertOk()->json('data');
        $this->assertSame(['2026-11-11', '2026-11-10'], array_column($history, 'rate_date'));
        $this->assertCount(1, $this->getJson('/api/v1/settings/exchange-rates/history?currency=SYP&limit=1')->json('data'));
    }

    public function test_old_pair_rates_are_converted_to_the_bulletin(): void
    {
        Schema::drop('sys_exchange_rates');
        Schema::create('sys_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_currency_id');
            $table->foreignId('to_currency_id');
            $table->decimal('rate', 18, 6);
            $table->date('rate_date');
            $table->timestamps();
        });
        $id = Currency::pluck('id', 'code');
        DB::table('sys_exchange_rates')->insert([
            ['from_currency_id' => $id['USD'], 'to_currency_id' => $id['SYP'], 'rate' => 13000, 'rate_date' => '2026-11-01'],
            ['from_currency_id' => $id['TRY'], 'to_currency_id' => $id['USD'], 'rate' => 0.025, 'rate_date' => '2026-11-01'],
            ['from_currency_id' => $id['SYP'], 'to_currency_id' => $id['USD'], 'rate' => 0.0001, 'rate_date' => '2026-11-01'], // direct USD→SYP wins
            ['from_currency_id' => $id['SYP'], 'to_currency_id' => $id['TRY'], 'rate' => 0.003, 'rate_date' => '2026-11-02'], // no USD side: dropped
        ]);

        (require app_path('Modules/Settings/Database/Migrations/2026_11_21_100002_rebuild_sys_exchange_rates_as_daily_bulletin.php'))->up();

        $rates = app(ExchangeRateService::class);
        $this->assertSame('13000.00000000', $rates->rateToBase('SYP', '2026-11-01'));
        $this->assertSame('40.00000000', $rates->rateToBase('TRY', '2026-11-01'));
        $this->assertSame(2, ExchangeRate::count());
    }

    public function test_branches_have_at_most_one_head_office(): void
    {
        $this->login('hr_manager');
        $this->postJson('/api/v1/settings/branches', ['name' => 'فرع إدلب', 'code' => 'IDL'])->assertForbidden();
        $this->getJson('/api/v1/settings/branches')->assertOk()->assertJsonPath('data.0.code', 'HQ');

        $this->login('finance_manager');
        $idlib = $this->postJson('/api/v1/settings/branches', ['name' => 'فرع إدلب', 'code' => 'idl'])->assertCreated()->assertJsonPath('data.code', 'IDL')->json('data');
        $this->postJson('/api/v1/settings/branches', ['name' => 'مكرر', 'code' => 'IDL'])->assertUnprocessable();
        $hq = Branch::where('code', 'HQ')->firstOrFail();
        $this->patchJson('/api/v1/settings/branches/'.$hq->id, ['is_active' => false])->assertUnprocessable();

        $this->patchJson('/api/v1/settings/branches/'.$idlib['id'], ['is_head_office' => true])->assertOk()->assertJsonPath('data.is_head_office', true);
        $this->assertFalse($hq->fresh()->is_head_office);
        $this->assertSame(1, Branch::where('is_head_office', true)->count());
    }

    public function test_finance_setting_keys_are_seeded_and_payroll_rounding_decimals_is_retired(): void
    {
        Setting::create(['setting_key' => 'payroll.rounding_decimals', 'setting_value' => '2', 'setting_group' => 'payroll']);
        $this->seed(SettingsSeeder::class);

        $this->assertSame('agreement', Setting::find('finance.contractor_tracking_mode_default')->setting_value);
        $this->assertSame('5', Setting::find('finance.exchange_rate_tolerance_pct')->setting_value);
        $this->assertSame('company_manager', Setting::find('finance.count_difference_approver_role')->setting_value);
        $this->assertNull(Setting::find('payroll.rounding_decimals'));
    }
}
