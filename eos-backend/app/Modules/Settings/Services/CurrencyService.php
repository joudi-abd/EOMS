<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Currency;
use App\Support\AuditableAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Currencies (D3). Exactly one active base currency (USD) carries the consolidated amounts; it can never be
 * deactivated or replaced, because every posted line keeps its base equivalent. Salaries are paid only in the
 * payroll currencies (D20), and each currency's rounding step rounds salary payments.
 */
class CurrencyService
{
    /** @return Collection<int, Currency> */
    public function list(): Collection
    {
        return Currency::query()->orderByDesc('is_base')->orderBy('code')->get();
    }

    /** @return Collection<int, Currency> */
    public function active(): Collection
    {
        return Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get();
    }

    public function find(int $id): ?Currency
    {
        return Currency::query()->find($id);
    }

    public function byCode(string $code): ?Currency
    {
        return Currency::query()->where('code', strtoupper($code))->first();
    }

    public function base(): ?Currency
    {
        return Currency::query()->where('is_base', true)->where('is_active', true)->first();
    }

    /** @return list<string> */
    public function payrollCodes(): array
    {
        return Currency::query()->where('is_payroll_currency', true)->where('is_active', true)->orderBy('code')->pluck('code')->all();
    }

    public function create(array $data): Currency
    {
        return DB::transaction(function () use ($data) {
            $data['code'] = strtoupper($data['code']);
            if (($data['is_base'] ?? false) && Currency::query()->where('is_base', true)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['is_base' => ['توجد عملة أساسية؛ لا تتغيّر العملة الأساسية.']]);
            }
            $currency = Currency::create($data);
            event(new AuditableAction('settings.currency_created', $currency, null, $currency->getAttributes()));

            return $currency->refresh();
        }, 3);
    }

    public function update(Currency $currency, array $data): Currency
    {
        return DB::transaction(function () use ($currency, $data) {
            $currency = Currency::query()->lockForUpdate()->findOrFail($currency->id);
            unset($data['code']);
            if (array_key_exists('is_base', $data) && (bool) $data['is_base'] !== $currency->is_base) {
                throw ValidationException::withMessages(['is_base' => ['العملة الأساسية ثابتة ولا تتغيّر.']]);
            }
            if ($currency->is_base && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => ['لا تُعطَّل العملة الأساسية.']]);
            }
            $old = $currency->getAttributes();
            $currency->update($data);
            event(new AuditableAction('settings.currency_updated', $currency, $old, $currency->getAttributes()));

            return $currency->refresh();
        }, 3);
    }
}
