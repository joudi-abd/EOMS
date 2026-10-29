<?php

namespace App\Modules\HR\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AttendanceClock
{
    public static function utc(string $value): CarbonImmutable
    {
        // Disallow naive times: the internet server's zone must not change device time.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw ValidationException::withMessages(['occurred_at' => ['يجب إرسال ISO 8601 مع المنطقة الزمنية، مثل 2026-10-31T09:00:00+03:00.']]);
        }
        $parts = date_parse($value);
        if ($parts['error_count'] || $parts['warning_count'] || ! checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])) {
            throw ValidationException::withMessages(['occurred_at' => ['تاريخ أو وقت غير صالح.']]);
        }
        try {
            $result = CarbonImmutable::parse($value)->utc()->startOfSecond();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['occurred_at' => ['وقت غير صالح.']]);
        }
        if ($result->greaterThan(CarbonImmutable::now('UTC')->addMinutes(5))) {
            throw ValidationException::withMessages(['occurred_at' => ['وقت الحركة في المستقبل؛ تحقق من ساعة الجهاز.']]);
        }

        return $result;
    }

    public static function at(string $date, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time.':00', $timezone)->utc();
    }
}
