<?php

namespace App\Modules\Finance\Services\Treasury;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\LedgerService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\BranchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash boxes (D4, D5): safes, exchange offices, banks and wallets, each with its own ledger account (created under the
 * account mapped to cash_boxes_parent) and any number of currencies, each with its negative-balance setting (D6).
 */
class CashBoxService
{
    private const TX_ATTEMPTS = 3;

    /** A box is "near its limit" once its negative balance has used this share of the allowed limit. */
    private const NEAR_LIMIT_SHARE = '0.8';

    public function __construct(private SystemAccounts $roles, private LedgerService $ledger, private FinanceScope $scope,
        private BranchService $branches, private AuditLogger $audit) {}

    /** Boxes in the account's branches, with per-currency balances and flags. */
    public function list(User $user, array $filters = []): array
    {
        return $this->scope->apply(CashBox::query(), $user, 'finance.view')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when(array_key_exists('active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['active']))
            ->orderBy('name')->get()->map(fn (CashBox $box) => $this->present($box))->all();
    }

    public function present(CashBox $box): array
    {
        return [...$box->toArray(), 'account_code' => Account::query()->whereKey($box->account_id)->value('code'), 'currencies' => $this->balances($box)];
    }

    /** @return list<array<string, mixed>> */
    public function balances(CashBox $box): array
    {
        $settings = CashBoxCurrency::query()->where('cash_box_id', $box->id)->get();
        $currencies = Currency::query()->whereIn('id', $settings->pluck('currency_id'))->get()->keyBy('id');
        $rows = [];
        foreach ($settings->sortBy(fn ($s) => $currencies[$s->currency_id]->code) as $setting) {
            $currency = $currencies[$setting->currency_id];
            $balance = Money::round($this->ledger->balance($box->account_id, $currency->id), $currency->decimals);
            $limit = $setting->negative_limit === null ? null : (string) $setting->negative_limit;
            $available = match (true) {
                ! $setting->allow_negative => Money::max('0', $balance),
                $limit === null => null,
                default => Money::max('0', Money::add($balance, $limit)),
            };
            $rows[] = ['currency_id' => $currency->id, 'code' => $currency->code, 'balance' => $balance, 'allow_negative' => $setting->allow_negative,
                'negative_limit' => $limit, 'available' => $available, 'negative' => Money::isNegative($balance),
                'near_limit' => $setting->allow_negative && $limit !== null && Money::isNegative($balance)
                    && Money::cmp(Money::abs($balance), Money::mul($limit, self::NEAR_LIMIT_SHARE)) >= 0];
        }

        return $rows;
    }

    /** Balance of the box in one currency (code or id). */
    public function balance(CashBox $box, int|string $currency): string
    {
        $currencyId = is_int($currency) ? $currency : (int) Currency::query()->where('code', strtoupper($currency))->value('id');

        return $this->ledger->balance($box->account_id, $currencyId);
    }

    public function create(array $data, User $actor): CashBox
    {
        return DB::transaction(function () use ($data, $actor) {
            $parent = Account::query()->lockForUpdate()->findOrFail($this->roles->id('cash_boxes_parent'));
            if ($parent->is_postable) {
                abort_if(JournalLine::query()->where('account_id', $parent->id)->exists(), 422, 'لحساب أب الصناديق حركات؛ اختر أباً آخر في الحسابات الإلزامية.');
                $parent->update(['is_postable' => false]);
            }
            $this->assertBranch($data['branch_id']);
            $account = Account::create(['parent_id' => $parent->id, 'code' => 'tmp-'.uniqid(), 'name' => $data['name'], 'type' => 'asset',
                'is_postable' => true, 'is_system' => true, 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $box = CashBox::create([...array_intersect_key($data, array_flip(['name', 'type', 'branch_id', 'custodian_user_id', 'contact_info'])),
                'account_id' => $account->id, 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $account->update(['code' => $parent->code.'-'.$box->id]);
            $this->syncCurrencies($box, $data['currencies']);
            $this->audit->log('finance.cash_box_created', $box, null, [...$box->getAttributes(), 'currencies' => $data['currencies']], $actor->id);

            return $box->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function update(CashBox $box, array $data, User $actor): CashBox
    {
        return DB::transaction(function () use ($box, $data, $actor) {
            $box = CashBox::query()->lockForUpdate()->findOrFail($box->id);
            if (isset($data['branch_id'])) {
                $this->assertBranch($data['branch_id']);
            }
            $old = [...$box->getAttributes(), 'currencies' => CashBoxCurrency::query()->where('cash_box_id', $box->id)->get(['currency_id', 'allow_negative', 'negative_limit'])->toArray()];
            $box->update([...array_intersect_key($data, array_flip(['name', 'type', 'branch_id', 'custodian_user_id', 'contact_info', 'is_active'])),
                'updated_by' => $actor->id]);
            if (isset($data['name'])) {
                Account::query()->whereKey($box->account_id)->update(['name' => $data['name'], 'updated_by' => $actor->id]);
            }
            if (array_key_exists('currencies', $data)) {
                $this->syncCurrencies($box, $data['currencies']);
            }
            $this->audit->log('finance.cash_box_updated', $box, $old, [...$box->getAttributes(), 'currencies' => $data['currencies'] ?? null], $actor->id);

            return $box->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function deactivate(CashBox $box, User $actor): CashBox
    {
        return $this->update($box, ['is_active' => false], $actor);
    }

    /**
     * Replaces the box's currencies. A currency with a non-zero balance stays; limits are positive amounts.
     *
     * @param  list<array{currency_id?: int, currency?: string, allow_negative?: bool, negative_limit?: string|float|null}>  $currencies
     */
    private function syncCurrencies(CashBox $box, array $currencies): void
    {
        if ($currencies === []) {
            throw ValidationException::withMessages(['currencies' => ['فعّل عملة واحدة على الأقل للصندوق.']]);
        }
        $wanted = [];
        foreach (array_values($currencies) as $i => $row) {
            $currency = isset($row['currency_id']) ? Currency::query()->find($row['currency_id']) : Currency::query()->where('code', strtoupper((string) ($row['currency'] ?? '')))->first();
            if ($currency === null || ! $currency->is_active) {
                throw ValidationException::withMessages(["currencies.$i.currency_id" => ['العملة غير موجودة أو غير مفعّلة.']]);
            }
            $allow = (bool) ($row['allow_negative'] ?? false);
            $limit = $allow && isset($row['negative_limit']) && $row['negative_limit'] !== '' ? Money::of($row['negative_limit']) : null;
            if ($limit !== null && Money::isNegative($limit)) {
                throw ValidationException::withMessages(["currencies.$i.negative_limit" => ['حد السالب مبلغ موجب.']]);
            }
            $wanted[$currency->id] = ['allow_negative' => $allow, 'negative_limit' => $limit];
        }
        foreach (CashBoxCurrency::query()->where('cash_box_id', $box->id)->whereNotIn('currency_id', array_keys($wanted))->get() as $removed) {
            if (! Money::isZero($this->ledger->balance($box->account_id, $removed->currency_id))) {
                throw ValidationException::withMessages(['currencies' => ['لا تُزال من الصندوق عملة لها رصيد.']]);
            }
            $removed->delete();
        }
        foreach ($wanted as $currencyId => $settings) {
            CashBoxCurrency::query()->updateOrCreate(['cash_box_id' => $box->id, 'currency_id' => $currencyId], $settings);
        }
    }

    private function assertBranch(int $branchId): void
    {
        if ($this->branches->find($branchId)?->is_active !== true) {
            throw ValidationException::withMessages(['branch_id' => ['الفرع غير موجود أو غير فعّال.']]);
        }
    }
}
