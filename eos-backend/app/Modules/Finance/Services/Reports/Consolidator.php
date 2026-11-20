<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Brings amounts of every currency into the base currency (USD) for a consolidated report (D8, spec §10.2).
 *
 * - `report_date` (default): an amount in currency X is divided by X's bulletin rate on the report date, or the latest
 *   earlier rate; the day actually used is reported.
 * - `historical`: the sum of the base amounts stored on the journal lines (base_debit / base_credit), i.e. each line at
 *   the bulletin rate of its own day.
 *
 * Every rate used is collected for the report header (`rates_used`). One instance serves one report.
 */
class Consolidator
{
    public const MODES = ['report_date', 'historical'];

    /** @var array<int, array{currency: string, rate: string|null, date: string|null}> */
    private array $used = [];

    /** @var array<int, array{rate: string, date: string}|null> */
    private array $rates = [];

    /** @var Collection<int, Currency> */
    private Collection $currencies;

    public function __construct(private ExchangeRateService $exchangeRates, public readonly string $mode, public readonly string $date)
    {
        $this->currencies = Currency::query()->get()->keyBy('id');
    }

    /**
     * The base equivalent of a currency amount: `$face` converted at the report-date rate, or `$base` (the stored base
     * amount) in historical mode. A missing rate is only an error when there is something to convert.
     */
    public function value(string $face, ?string $base, int $currencyId): string
    {
        $currency = $this->currencies->get($currencyId);
        if ($currency === null) {
            return '0';
        }
        if ($this->mode === 'historical' && $base !== null) {
            $this->used[$currencyId] ??= ['currency' => $currency->code, 'rate' => null, 'date' => null];

            return Money::round($base, Money::SCALE);
        }
        if ($currency->is_base) {
            $this->used[$currencyId] ??= ['currency' => $currency->code, 'rate' => '1', 'date' => $this->date];

            return Money::round($face, Money::SCALE);
        }
        $rate = $this->rateOf($currency);
        if ($rate === null) {
            if (Money::isZero($face)) {
                return '0';
            }
            throw ValidationException::withMessages(['date' => ["لا يوجد سعر لـ {$currency->code} في النشرة حتى تاريخ {$this->date}؛ أدخل النشرة أولاً."]]);
        }
        $this->used[$currencyId] = ['currency' => $currency->code, 'rate' => $rate['rate'], 'date' => $rate['date']];

        return Money::div($face, $rate['rate'], Money::SCALE);
    }

    /** Report-date conversion only (figures that are not sums of journal lines, e.g. a project's fee). */
    public function atReportDate(string $face, int $currencyId): string
    {
        $currency = $this->currencies->get($currencyId);
        if ($currency === null || $currency->is_base) {
            if ($currency !== null) {
                $this->used[$currencyId] ??= ['currency' => $currency->code, 'rate' => '1', 'date' => $this->date];
            }

            return Money::round($face, Money::SCALE);
        }
        $rate = $this->rateOf($currency);
        if ($rate === null) {
            if (Money::isZero($face)) {
                return '0';
            }
            throw ValidationException::withMessages(['date' => ["لا يوجد سعر لـ {$currency->code} في النشرة حتى تاريخ {$this->date}؛ أدخل النشرة أولاً."]]);
        }
        $this->used[$currencyId] = ['currency' => $currency->code, 'rate' => $rate['rate'], 'date' => $rate['date']];

        return Money::div($face, $rate['rate'], Money::SCALE);
    }

    /**
     * The rates the report used, one row per currency: `{currency, rate, date}`. In historical mode rate and date are
     * null (every line carries its own rate).
     *
     * @return list<array{currency: string, rate: string|null, date: string|null}>
     */
    public function ratesUsed(): array
    {
        $rows = array_values($this->used);
        usort($rows, fn ($a, $b) => strcmp($a['currency'], $b['currency']));

        return $rows;
    }

    /** Header block of a consolidated report. */
    public function header(): array
    {
        return ['base_currency' => $this->currencies->firstWhere('is_base', true)?->code ?? 'USD', 'rate_mode' => $this->mode,
            'rate_date' => $this->date, 'rates_used' => $this->ratesUsed()];
    }

    /** @return array{rate: string, date: string}|null */
    private function rateOf(Currency $currency): ?array
    {
        if (! array_key_exists($currency->id, $this->rates)) {
            $this->rates[$currency->id] = $this->exchangeRates->rateToBaseOnOrBefore($currency->code, $this->date);
        }

        return $this->rates[$currency->id];
    }
}
