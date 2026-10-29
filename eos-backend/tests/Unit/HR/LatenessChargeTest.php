<?php

namespace Tests\Unit\HR;

use App\Modules\HR\Services\Attendance\LatenessCharge;
use PHPUnit\Framework\TestCase;

class LatenessChargeTest extends TestCase
{
    public function test_exact_seconds_at_every_band_boundary(): void
    {
        $charge = new LatenessCharge;
        foreach ([[-1, 0], [0, 0], [2699, 0], [2700, 0], [2701, 60],
            [2760, 60], [3599, 60], [3600, 60], [7199, 60], [7200, 120],
            [10799, 120], [10800, 180], [14399, 180], [14400, 240]] as [$seconds, $expected]) {
            $this->assertSame($expected, $charge->minutes($seconds, 45, 'completed_blocks', 60), 'Seconds: '.$seconds);
        }
    }

    public function test_configurable_blocks_and_grace_do_not_assume_nine_oclock(): void
    {
        $charge = new LatenessCharge;
        $this->assertSame(0, $charge->minutes(900, 15, 'completed_blocks', 30));
        $this->assertSame(30, $charge->minutes(901, 15, 'completed_blocks', 30));
        $this->assertSame(30, $charge->minutes(3599, 15, 'completed_blocks', 30));
        $this->assertSame(60, $charge->minutes(3600, 15, 'completed_blocks', 30));
    }

    public function test_legacy_policy_modes_keep_their_previous_behavior(): void
    {
        $charge = new LatenessCharge;
        $this->assertSame(46, $charge->minutes(2760, 30, 'threshold_full'));
        $this->assertSame(16, $charge->minutes(2760, 30, 'excess_only'));
        $this->assertSame(0, $charge->minutes(1800, 30, 'excess_only'));
    }

    public function test_invalid_block_is_not_silently_used(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LatenessCharge)->minutes(3600, 45, 'completed_blocks', 0);
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LatenessCharge)->minutes(3600, 45, 'unexpected');
    }
}
