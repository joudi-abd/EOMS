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

    public function latestRate(string $from, string $to): ?ExchangeRate
    {
        return Cache::remember(
            $this->cacheKey($from, $to),
            now()->addHour(),
            fn () => ExchangeRate::query()
                ->where('from_currency_id', Currency::where('code', $from)->value('id'))
                ->where('to_currency_id', Currency::where('code', $to)->value('id'))
                ->orderByDesc('rate_date')
                ->first()
        );
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
