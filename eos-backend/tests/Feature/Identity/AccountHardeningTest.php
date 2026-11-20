<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Account takeover, token lifetime and settings regressions found in the backend audit. */
class AccountHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function clerk(): User
    {
        Role::create(['name' => 'accounts_clerk', 'guard_name' => 'web'])->givePermissionTo('users.manage');
        $user = User::factory()->create();
        $user->assignRole('accounts_clerk');
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function bearer(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_account_manager_cannot_reset_an_administrators_password(): void
    {
        $this->clerk();
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        $hash = $admin->password;

        $this->patchJson("/api/v1/users/{$admin->id}", ['password' => 'Takeover-2026!'])->assertForbidden();

        $this->assertSame($hash, $admin->fresh()->password);
    }

    public function test_account_manager_cannot_edit_an_account_holding_more_than_they_do(): void
    {
        $this->clerk();
        $finance = User::factory()->create();
        $finance->assignRole('finance_manager');

        $this->patchJson("/api/v1/users/{$finance->id}", ['email' => 'mine@test.example'])->assertForbidden();
        $this->patchJson("/api/v1/users/{$finance->id}/status", ['status' => 'disabled'])->assertForbidden();

        $this->assertSame('active', $finance->fresh()->status);
    }

    public function test_caller_without_the_permission_gets_403_before_validation(): void
    {
        $taken = User::factory()->create(['email' => 'taken@test.example']);
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/users', ['email' => $taken->email])->assertForbidden();
        $this->patchJson('/api/v1/users/999999', ['email' => 'x'])->assertForbidden();
    }

    public function test_password_change_revokes_existing_tokens(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        $target = User::factory()->create();
        $stolen = $this->bearer($target);

        $this->withToken($stolen)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($admin, ['*']);
        $this->patchJson("/api/v1/users/{$target->id}", ['password' => 'Brand-New-2026!'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($stolen)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_of_a_disabled_account_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $this->bearer($user);
        // Disabled behind the API's back (no token cleanup): the guard itself must refuse it.
        $user->forceFill(['status' => 'disabled'])->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_tokens_expire(): void
    {
        $this->assertNotNull(config('sanctum.expiration'));
    }

    public function test_user_list_rejects_array_filters_with_422(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/users?q[]=x')->assertUnprocessable();
    }

    public function test_notifications_accept_unread_true(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/v1/notifications?unread=true')->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_exchange_rate_date_is_returned_as_a_plain_date(): void
    {
        Currency::firstOrCreate(['code' => 'SYP'], ['name' => 'SYP', 'symbol' => 'ل.س']);
        Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD', 'symbol' => '$', 'is_base' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/settings/exchange-rates', ['date' => '2026-11-14', 'rates' => ['SYP' => 13000]])
            ->assertCreated()->assertJsonPath('data.date', '2026-11-14')->assertJsonPath('data.rates.0.rate_date', '2026-11-14');
        $this->getJson('/api/v1/settings/exchange-rates/history?currency=SYP')->assertJsonPath('data.0.rate_date', '2026-11-14');
        $this->assertDatabaseHas('usr_audit_logs', ['action' => 'fx.rate_set']);
    }

    public function test_updating_a_setting_keeps_its_group_and_public_settings_need_no_login(): void
    {
        Setting::create(['setting_key' => 'company.name', 'setting_value' => 'Old', 'setting_group' => 'company']);
        $admin = User::factory()->create();
        $admin->assignRole('system_admin');
        Sanctum::actingAs($admin, ['*']);

        $this->putJson('/api/v1/settings/config/company.name', ['value' => 'New'])->assertOk()->assertJsonPath('data.group', 'company');
        $this->getJson('/api/v1/settings/config/empty_group')->assertOk()->assertExactJson(['data' => []]);
        $this->app['auth']->forgetGuards();

        $this->flushHeaders()->getJson('/api/v1/settings/config/public')->assertOk()->assertJsonPath('data', ['company.name' => 'New']);
    }
}
