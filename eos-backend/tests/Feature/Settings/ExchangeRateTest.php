<?php

namespace Tests\Feature\Settings;

use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Models\ExchangeRate;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CurrencySeeder::class);
    }

    private function login(string $role = 'system_admin'): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);
    }
    private function ids(): array
    {
        return [
            'syp' => Currency::where('code', 'SYP')->value('id'),
            'usd' => Currency::where('code', 'USD')->value('id'),
        ];
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/settings/currencies')->assertUnauthorized();
    }

    public function test_lists_seeded_currencies(): void
    {
        $this->login();
        $this->getJson('/api/v1/settings/currencies')
            ->assertOk()
            ->assertJsonFragment(['code' => 'SYP'])
            ->assertJsonFragment(['code' => 'USD']);
    }

    public function test_can_store_exchange_rate(): void
    {
        $this->login();

        $this->postJson('/api/v1/settings/exchange-rates', [
            'from' => 'USD',
            'to' => 'SYP',
            'rate' => 15000,
            'rate_date' => '2026-10-22',
        ])->assertCreated();

        $c = $this->ids();
        $this->assertTrue(
            ExchangeRate::query()
                ->where('from_currency_id', $c['usd'])
                ->where('to_currency_id', $c['syp'])
                ->whereDate('rate_date', '2026-10-22')
                ->exists()
        );
    }

    public function test_same_day_rate_is_upserted(): void
    {
        $this->login();

        $payload = ['from' => 'USD', 'to' => 'SYP', 'rate_date' => '2026-10-22'];

        $this->postJson('/api/v1/settings/exchange-rates', $payload + ['rate' => 14000])->assertCreated();
        $this->postJson('/api/v1/settings/exchange-rates', $payload + ['rate' => 15000])->assertCreated();

        $this->assertSame(1, ExchangeRate::count());
        $this->assertEquals(15000, (float) ExchangeRate::first()->rate);
    }

    public function test_rejects_same_currency_pair(): void
    {
        $this->login();

        $this->postJson('/api/v1/settings/exchange-rates', [
            'from' => 'USD',
            'to' => 'USD',
            'rate' => 1,
            'rate_date' => '2026-10-22',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['to']);
    }

    public function test_latest_returns_newest_rate(): void
    {
        $this->login();
        $c = $this->ids();
        $base = ['from_currency_id' => $c['usd'], 'to_currency_id' => $c['syp']];

        ExchangeRate::updateOrCreate($base + ['rate' => 14000, 'rate_date' => '2026-10-20']);
        ExchangeRate::updateOrCreate($base + ['rate' => 15000, 'rate_date' => '2026-10-22']);

        $this->getJson('/api/v1/settings/exchange-rates/latest?from=USD&to=SYP')
            ->assertOk()
            ->assertJsonPath('data.rate', fn ($rate) => (float) $rate === 15000.0);
    }

    public function test_employee_without_finance_permission_cannot_store_rate(): void
    {
        $this->login('employee');

        $this->postJson('/api/v1/settings/exchange-rates', [
            'from' => 'USD',
            'to' => 'SYP',
            'rate' => 15000,
            'rate_date' => '2026-10-22',
        ])->assertForbidden();
    }
}