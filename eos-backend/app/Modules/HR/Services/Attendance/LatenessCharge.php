<?php

namespace App\Modules\HR\Services\Attendance;

class LatenessCharge
{
    /** Calculate attendance charge units only; no financial deduction is performed. */
    public function minutes(int $delaySeconds, int $graceMinutes, string $mode, int $blockMinutes = 60): int
    {
        $delaySeconds = max(0, $delaySeconds);
        if ($graceMinutes < 0 || $blockMinutes < 1) {
            throw new \InvalidArgumentException('Invalid lateness policy values.');
        }
        if ($delaySeconds <= $graceMinutes * 60) {
            return 0;
        }

        return match ($mode) {
            // Do not floor a rounded-up minute count: 10:59:59 must still charge one hour.
            'completed_blocks' => max(1, intdiv($delaySeconds, $blockMinutes * 60)) * $blockMinutes,
            'threshold_full' => (int) ceil($delaySeconds / 60),
            'excess_only' => (int) ceil(($delaySeconds - $graceMinutes * 60) / 60),
            default => throw new \InvalidArgumentException('Unknown lateness policy mode.'),
        };
    }
}
