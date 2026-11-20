<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Models\ExchangeRate;
use App\Support\AuditableAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The daily rate bulletin (D7): for each currency and day, how many units buy one unit of the base currency (USD).
 * It suggests rates on exchanges and gives every posted line its base equivalent; cross rates are derived from it.
 * The base currency is always 1 and is never stored.
 */
class ExchangeRateService
{
    /**
     * Saves the bulletin of one day: `$rates` is code => units per one base unit. Existing rows of that day are
     * replaced; every change is audited.
     *
     * @param  array<string, string|int|float>  $rates
     * @return array{date: string, base: ?string, rates: list<array<string, mixed>>, missing: list<string>}
     */
    public function setRates(string $date, array $rates, ?int $userId = null): array
    {
        $date = Carbon::parse($date)->toDateString();
        $currencies = Currency::query()->whereIn('code', array_map('strtoupper', array_keys($rates)))->get()->keyBy('code');
        $errors = [];
        $clean = [];
        foreach ($rates as $code => $rate) {
            $code = strtoupper((string) $code);
            $currency = $currencies->get($code);
            $value = is_string($rate) ? trim($rate) : (is_numeric($rate) ? rtrim(rtrim(sprintf('%.10F', $rate), '0'), '.') : '');
            if ($currency === null || ! $currency->is_active) {
                $errors["rates.$code"] = ['العملة غير موجودة أو غير مفعّلة.'];
            } elseif ($currency->is_base) {
                $errors["rates.$code"] = ['سعر العملة الأساسية 1 دائماً ولا يُدخل.'];
            } elseif (preg_match('/^\d+(\.\d+)?$/', $value) !== 1 || bccomp($value, '0', 8) <= 0) {
                $errors["rates.$code"] = ['السعر رقم موجب.'];
            } else {
                $clean[$currency->id] = [$currency, bcadd($value, '0', 8)];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($date, $clean, $userId) {
            ksort($clean);
            foreach ($clean as $currencyId => [$currency, $rate]) {
                $existing = ExchangeRate::query()->where('currency_id', $currencyId)->whereDate('rate_date', $date)->lockForUpdate()->first();
                $old = $existing === null ? null : ['currency' => $currency->code, 'date' => $date, 'rate_to_base' => (string) $existing->rate_to_base];
                $row = $existing ?? new ExchangeRate(['currency_id' => $currencyId, 'rate_date' => $date]);
                $row->fill(['rate_to_base' => $rate, 'source' => 'manual', 'created_by' => $userId ?? $row->created_by])->save();
                // Payroll and finance price with these rates, so every change leaves a trace.
                event(new AuditableAction('fx.rate_set', $row, $old, ['currency' => $currency->code, 'date' => $date, 'rate_to_base' => $rate]));
            }
        }, 3);

        return $this->bulletin($date);
    }

    /**
     * Fills `$date` with the latest earlier rate of every active non-base currency that has no rate that day yet.
     *
     * @return array{date: string, base: ?string, rates: list<array<string, mixed>>, missing: list<string>}
     */
    public function copyPrevious(string $date, ?int $userId = null): array
    {
        $date = Carbon::parse($date)->toDateString();
        $rates = [];
        foreach (Currency::query()->where('is_active', true)->where('is_base', false)->orderBy('code')->get() as $currency) {
            if (ExchangeRate::query()->where('currency_id', $currency->id)->whereDate('rate_date', $date)->exists()) {
                continue;
            }
            $previous = ExchangeRate::query()->where('currency_id', $currency->id)->whereDate('rate_date', '<', $date)
                ->orderByDesc('rate_date')->first(['rate_to_base']);
            if ($previous !== null) {
                $rates[$currency->code] = (string) $previous->rate_to_base;
            }
        }

        return $rates === [] ? $this->bulletin($date) : $this->setRates($date, $rates, $userId);
    }

    /**
     * The rates entered for one day, and the active currencies still missing from it.
     *
     * @return array{date: string, base: ?string, rates: list<array<string, mixed>>, missing: list<string>}
     */
    public function bulletin(string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $currencies = Currency::query()->where('is_active', true)->orderBy('code')->get();
        $rows = ExchangeRate::query()->whereDate('rate_date', $date)->get()->keyBy('currency_id');
        $rates = [];
        $missing = [];
        foreach ($currencies->where('is_base', false) as $currency) {
            $row = $rows->get($currency->id);
            if ($row === null) {
                $missing[] = $currency->code;

                continue;
            }
            $rates[] = ['currency_id' => $currency->id, 'code' => $currency->code, 'name' => $currency->name,
                'rate_to_base' => (string) $row->rate_to_base, 'source' => $row->source, 'rate_date' => $date];
        }

        return ['date' => $date, 'base' => $currencies->firstWhere('is_base', true)?->code, 'rates' => $rates, 'missing' => $missing];
    }

    /** The rate entered for exactly that day ('1' for the base currency), or null. */
    public function rateToBase(string $code, string $date): ?string
    {
        $currency = $this->currency($code);
        if ($currency === null) {
            return null;
        }
        if ($currency->is_base) {
            return '1';
        }
        $row = ExchangeRate::query()->where('currency_id', $currency->id)->whereDate('rate_date', $date)->first(['rate_to_base']);

        return $row === null ? null : (string) $row->rate_to_base;
    }

    /**
     * The latest rate on or before the day, with the day it was entered. The base currency is ['1', $date].
     *
     * @return array{rate: string, date: string}|null
     */
    public function rateToBaseOnOrBefore(string $code, string $date): ?array
    {
        $currency = $this->currency($code);
        if ($currency === null) {
            return null;
        }
        if ($currency->is_base) {
            return ['rate' => '1', 'date' => $date];
        }
        $row = ExchangeRate::query()->where('currency_id', $currency->id)->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')->first(['rate_to_base', 'rate_date']);

        return $row === null ? null : ['rate' => (string) $row->rate_to_base, 'date' => $row->rate_date->toDateString()];
    }

    /**
     * Units of `$to` per one unit of `$from`, from that exact day's bulletin. Null when either side has no rate that
     * day: callers (payroll) must not guess with an older rate.
     */
    public function rateOn(string $from, string $to, string $date): ?float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }
        $fromRate = $this->rateToBase($from, $date);
        $toRate = $this->rateToBase($to, $date);
        if ($fromRate === null || $toRate === null || bccomp($fromRate, '0', 8) <= 0) {
            return null;
        }

        return (float) bcdiv($toRate, $fromRate, 12);
    }

    /** Units of `$to` per one `$from` from the latest bulletin on or before the day (a suggestion), or null. */
    public function crossRateOnOrBefore(string $from, string $to, string $date): ?string
    {
        if (strtoupper($from) === strtoupper($to)) {
            return '1';
        }
        $fromRate = $this->rateToBaseOnOrBefore($from, $date);
        $toRate = $this->rateToBaseOnOrBefore($to, $date);
        if ($fromRate === null || $toRate === null || bccomp($fromRate['rate'], '0', 8) <= 0) {
            return null;
        }

        return bcdiv($toRate['rate'], $fromRate['rate'], 8);
    }

    /**
     * The rates recorded for one currency, newest first.
     *
     * @return list<array{rate_to_base: string, rate_date: string, source: string}>
     */
    public function history(string $code, int $limit = 30): array
    {
        $currency = $this->currency($code);
        if ($currency === null) {
            return [];
        }

        return ExchangeRate::query()->where('currency_id', $currency->id)->orderByDesc('rate_date')->limit($limit)->get()
            ->map(fn (ExchangeRate $row) => ['rate_to_base' => (string) $row->rate_to_base, 'rate_date' => $row->rate_date->toDateString(),
                'source' => $row->source])
            ->all();
    }

    private function currency(string $code): ?Currency
    {
        return Currency::query()->where('code', strtoupper($code))->first();
    }
}
