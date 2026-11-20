<?php

namespace Tests\Feature\Finance\Concerns;

use App\Modules\Finance\Database\Seeders\FinanceChartSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Services\Core\LedgerService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Treasury\CashBoxService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Database\Seeders\BranchSeeder;
use App\Modules\Settings\Database\Seeders\CurrencySeeder;
use App\Modules\Settings\Database\Seeders\SettingsSeeder;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/**
 * Finance test world on 15 Nov 2026: currencies USD (base), SYP, TRY; the head office branch; the starter chart with
 * every system role mapped; bulletin rates from 1 Sep (SYP 13,000, TRY 43.4 per USD); a chief accountant and a
 * company manager.
 */
trait FinanceFixture
{
    protected User $finance;

    protected User $director;

    protected Branch $hq;

    protected function setUpFinance(): void
    {
        $this->seed([RolePermissionSeeder::class, CurrencySeeder::class, BranchSeeder::class, SettingsSeeder::class, FinanceChartSeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-11-15T10:00:00+03:00'));
        app(ExchangeRateService::class)->setRates('2026-09-01', ['SYP' => 13000, 'TRY' => 43.4]);
        $this->hq = Branch::where('code', 'HQ')->firstOrFail();
        $this->finance = $this->actor('finance_manager');
        $this->director = $this->actor('company_manager');
        Sanctum::actingAs($this->finance);
    }

    protected function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function login(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * A box through the API. `$currencies`: code => null (no negative) | 'unlimited' | limit amount.
     *
     * @param  array<string, string|int|null>  $currencies
     */
    protected function box(string $name, array $currencies = ['USD' => null], ?int $branchId = null): CashBox
    {
        $rows = [];
        foreach ($currencies as $code => $negative) {
            $rows[] = ['currency' => $code, 'allow_negative' => $negative !== null, 'negative_limit' => is_numeric($negative) ? $negative : null];
        }
        $id = $this->login($this->finance)->postJson('/api/v1/finance/cash-boxes', ['name' => $name, 'type' => 'cash_safe',
            'branch_id' => $branchId ?? $this->hq->id, 'currencies' => $rows])->assertCreated()->json('data.id');

        return CashBox::findOrFail($id);
    }

    /** Puts money in a box with a posted opening-balance voucher. */
    protected function fund(CashBox $box, string $currency, string|int|float $amount, string $date = '2026-11-01'): void
    {
        $this->login($this->finance)->postJson('/api/v1/finance/vouchers', ['type' => 'opening_balance', 'voucher_date' => $date, 'approve' => true,
            'lines' => [['cash_box_id' => $box->id, 'currency' => $currency, 'debit' => $amount]]])->assertCreated();
    }

    /** POSTs a voucher (posted unless `approve` is false) and returns the response data. */
    protected function voucher(array $payload, int $status = 201): ?array
    {
        $response = $this->postJson('/api/v1/finance/vouchers', $payload + ['voucher_date' => '2026-11-10', 'approve' => true])->assertStatus($status);

        return $status < 300 ? $response->json('data') : null;
    }

    protected function boxBalance(CashBox $box, string $currency): string
    {
        return app(CashBoxService::class)->balance($box, $currency);
    }

    protected function roleId(string $role): int
    {
        return app(SystemAccounts::class)->id($role);
    }

    protected function roleBalance(string $role, string $currency, ?int $partyId = null): string
    {
        return app(LedgerService::class)->balance($this->roleId($role), $this->currencyId($currency), $partyId);
    }

    protected function accountId(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    protected function currencyId(string $code): int
    {
        return Currency::where('code', $code)->value('id');
    }
}
