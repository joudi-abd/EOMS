<?php

namespace Tests\Feature\Settings;

use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Models\ApiClient;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::firstOrCreate(['code' => 'SYP'], ['name' => 'الليرة السورية', 'symbol' => 'ل.س']);
        Currency::firstOrCreate(['code' => 'USD'], ['name' => 'الدولار الأمريكي', 'symbol' => '$']);

        $this->seed(RolePermissionSeeder::class);
    }

    private function actAs(string $role): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);
    }

    public function test_admin_can_update_a_setting(): void
    {
        $this->actAs('system_admin');

        $this->putJson('/api/v1/settings/config/company.name', [
            'value' => 'شركة اختبار',
            'group' => 'company',
        ])->assertOk();

        $this->assertDatabaseHas('sys_settings', [
            'setting_key' => 'company.name',
            'setting_value' => 'شركة اختبار',
        ]);
    }

    public function test_employee_cannot_update_a_setting(): void
    {
        $this->actAs('employee');

        $this->putJson('/api/v1/settings/config/company.name', ['value' => 'x'])
            ->assertForbidden();
    }

    public function test_employee_can_read_only_public_settings(): void
    {
        $this->actAs('employee');

        Setting::create([
            'setting_key' => 'company.name',
            'setting_value' => 'EOMS',
            'setting_group' => 'company',
        ]);

        Setting::create([
            'setting_key' => 'internal.secret',
            'setting_value' => 'private-value',
            'setting_group' => 'company',
        ]);

        $response = $this->getJson('/api/v1/settings/config/public')
            ->assertOk();

        $this->assertSame(
            'EOMS',
            $response->json()['data']['company.name']
        );

        $this->assertArrayNotHasKey(
            'internal.secret',
            $response->json()['data']
        );

        $this->getJson('/api/v1/settings/config/company')
            ->assertForbidden();
    }

    public function test_admin_can_read_internal_settings(): void
    {
        $this->actAs('system_admin');

        Setting::create([
            'setting_key' => 'internal.secret',
            'setting_value' => 'private-value',
            'setting_group' => 'internal',
        ]);

        $response = $this->getJson('/api/v1/settings/config/internal')
            ->assertOk();

        $this->assertSame(
            'private-value',
            $response->json()['data']['internal.secret']
        );
    }

    public function test_api_client_command_stores_hashed_token(): void
    {
        Artisan::call('api-client:create', [
            'name' => 'website',
            '--scope' => ['projects.create'],
        ]);

        $client = ApiClient::where('name', 'website')->firstOrFail();

        $this->assertSame(['projects.create'], $client->scopes);
        $this->assertSame(64, strlen($client->token_hash));
    }

    public function test_latest_rate_cache_is_refreshed_after_update(): void
    {
        $service = app(ExchangeRateService::class);

        $service->setRate('USD', 'SYP', 14000, '2026-10-21');
        $this->assertEquals(14000, (float) $service->latestRate('USD', 'SYP')->rate);

        $service->setRate('USD', 'SYP', 15000, '2026-10-22');
        $this->assertEquals(15000, (float) $service->latestRate('USD', 'SYP')->rate);
    }
}
