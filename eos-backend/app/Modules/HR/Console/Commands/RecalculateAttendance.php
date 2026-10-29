<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\Attendance\AttendanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RecalculateAttendance extends Command
{
    protected $signature = 'attendance:recalculate {--date= : YYYY-MM-DD; defaults to today and the preceding two days}';

    protected $description = 'Refresh attendance projections without changing approved results';

    public function handle(AttendanceCalculator $calculator): int
    {
        $zone = AttendancePolicy::query()->orderBy('effective_from')->value('timezone');
        $date = $this->option('date');
        if ($date && (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! CarbonImmutable::hasFormatWithModifiers($date, 'Y-m-d') || $date < '1970-01-01')) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return self::FAILURE;
        }
        $today = CarbonImmutable::now($zone)->startOfDay();
        $dates = $date ? [$date] : [$today->subDays(2)->toDateString(), $today->subDay()->toDateString(), $today->toDateString()];
        $failed = 0;
        Employee::query()->orderBy('id')->chunkById(100, function ($employees) use ($calculator, $dates, &$failed) {
            foreach ($employees as $employee) {
                foreach ($dates as $date) {
                    try {
                        $calculator->calculate($employee, $date);
                    } catch (\Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }
            }
        });
        $this->info("Attendance refreshed; {$failed} failures.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
