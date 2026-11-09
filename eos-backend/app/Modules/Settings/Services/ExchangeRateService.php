<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ExchangeRateService
{
    public function setRate(string $from, string $to, float $rate, string $date): ExchangeRate
    {
        $rateDate = Carbon::parse($date)->startOfDay()->toDateTimeString();

        $saved = ExchangeRate::updateOrCreate(
            [
                'from_currency_id' => Currency::where('code', $from)->value('id'),
                'to_currency_id' => Currency::where('code', $to)->value('id'),
                'rate_date' => $rateDate,
            ],
            ['rate' => $rate]
        );

        // السعر تغيّر، فنمسح الكاش ليُقرأ الجديد في المرة القادمة
        Cache::forget($this->cacheKey($from, $to));

        return $saved;
    }

    /**
     * The newest rate for a pair. The cache holds the row's plain attributes, not the model: the cache store refuses
     * to unserialize objects (`cache.serializable_classes` is false), so a cached model came back as an incomplete
     * class and the second request failed with a 500.
     */
    public function latestRate(string $from, string $to): ?ExchangeRate
    {
        $attributes = Cache::remember(
            $this->cacheKey($from, $to),
            now()->addHour(),
            fn () => ExchangeRate::query()
                ->where('from_currency_id', Currency::where('code', $from)->value('id'))
                ->where('to_currency_id', Currency::where('code', $to)->value('id'))
                ->orderByDesc('rate_date')
                ->first()
                ?->getAttributes()
        );

        return is_array($attributes) ? (new ExchangeRate)->newFromBuilder($attributes) : null;
    }

    /**
     * The rates recorded for one pair, newest first. Read straight from the table (not cached) so a rate saved a
     * moment ago shows at once.
     *
     * @return list<array{rate: string, rate_date: string}>
     */
    public function history(string $from, string $to, int $limit = 30): array
    {
        return ExchangeRate::query()
            ->where('from_currency_id', Currency::where('code', $from)->value('id'))
            ->where('to_currency_id', Currency::where('code', $to)->value('id'))
            ->orderByDesc('rate_date')
            ->limit($limit)
            ->get()
            ->map(fn (ExchangeRate $row) => ['rate' => (string) $row->rate, 'rate_date' => $row->rate_date->toDateString()])
            ->all();
    }

    /**
     * The rate recorded for exactly this date, read directly or as the inverse of the opposite pair.
     * Returns null when no rate exists for that day: callers must not guess with an older rate.
     */
    public function rateOn(string $from, string $to, string $date): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }
        $find = fn (string $a, string $b) => ExchangeRate::query()
            ->where('from_currency_id', Currency::where('code', $a)->value('id'))
            ->where('to_currency_id', Currency::where('code', $b)->value('id'))
            ->whereDate('rate_date', $date)->value('rate');
        $direct = $find($from, $to);
        if ($direct !== null && (float) $direct > 0) {
            return (float) $direct;
        }
        $inverse = $find($to, $from);

        return $inverse !== null && (float) $inverse > 0 ? 1 / (float) $inverse : null;
    }

    private function cacheKey(string $from, string $to): string
    {
        return 'fx:latest:'.strtoupper($from).':'.strtoupper($to);
    }

    public function currencies(): Collection
    {
        return Currency::orderBy('code')->get();
    }
}
