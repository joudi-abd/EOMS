<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigureAttendanceDefaults extends Command
{
    protected $signature = 'attendance:configure-defaults {--from= : Future effective date YYYY-MM-DD; defaults to tomorrow}';

    protected $description = 'Create a future 09:00-16:00 policy with 45-minute grace and completed-hour charges';

    public function handle(AuditLogger $audit): int
    {
        return DB::transaction(function () use ($audit) {
            $first = AttendancePolicy::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $today = CarbonImmutable::now($first->timezone)->startOfDay();
            $date = $this->option('from') ?? $today->addDay()->toDateString();
            $parts = date_parse($date);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || $parts['error_count'] || $parts['warning_count']
                || ! checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])
                || $date <= $today->toDateString()) {
                $this->error('Effective date must be a valid date after today in the office timezone.');

                return self::FAILURE;
            }
            if (AttendancePolicy::query()->where('effective_from', $date)->exists()) {
                $this->error('A policy already exists on that date; no changes were made.');

                return self::FAILURE;
            }
            $policy = AttendancePolicy::create([
                'effective_from' => $date, 'timezone' => $first->timezone,
                'working_days' => [0, 1, 2, 3, 4, 6], 'start_time' => '09:00', 'end_time' => '16:00',
                'grace_minutes' => 45, 'grace_mode' => 'completed_blocks', 'late_block_minutes' => 60,
                'overtime_multiplier' => '2.00', 'pairing_mode' => 'first_last',
                'duplicate_seconds' => 60, 'finalize_after_minutes' => 120,
            ]);
            $audit->log('attendance.defaults_configured', $policy, null, ['effective_from' => $date]);
            $this->info('Attendance defaults effective '.$date.'. Earlier and later scheduled versions were preserved.');

            return self::SUCCESS;
        }, 3);
    }
}
