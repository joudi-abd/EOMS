<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendancePolicy;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class AttendancePolicyMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_preserves_existing_policy_and_approved_day(): void
    {
        $migration = require app_path('Modules/HR/Database/Migrations/2026_10_30_000002_add_attendance_lateness_blocks.php');
        $migration->down();
        $policy = AttendancePolicy::firstOrFail();
        $policy->update(['grace_minutes' => 30, 'grace_mode' => 'threshold_full']);
        $employee = Employee::create([
            'employee_no' => 'U-11223344', 'first_name' => 'Ali', 'father_name' => 'Omar', 'last_name' => 'Hassan',
            'national_id' => '11223344', 'national_id_hash' => hash('sha256', '11223344'), 'hire_date' => '2026-01-01',
        ]);
        $day = AttendanceDay::create([
            'employee_id' => $employee->id, 'work_date' => '2026-10-29', 'policy_id' => $policy->id,
            'status' => 'present', 'worked_minutes' => 374, 'late_actual_minutes' => 46, 'late_minutes' => 46,
            'flags' => [], 'approved_at' => now(), 'version' => 4, 'source_signature' => str_repeat('a', 64),
        ]);
        $snapshot = $day->fresh()->toArray();
        $migration->up();
        $this->assertSame(30, $policy->fresh()->grace_minutes);
        $this->assertSame('threshold_full', $policy->fresh()->grace_mode);
        $this->assertSame($snapshot, $day->fresh()->toArray());
        $this->assertSame(60, $policy->fresh()->late_block_minutes);
    }

    public function test_upgrade_does_not_replace_custom_unused_policy(): void
    {
        $migration = require app_path('Modules/HR/Database/Migrations/2026_10_30_000002_add_attendance_lateness_blocks.php');
        $migration->down();
        $policy = AttendancePolicy::firstOrFail();
        $policy->update(['grace_minutes' => 15, 'grace_mode' => 'excess_only', 'start_time' => '08:00']);
        $migration->up();
        $policy->refresh();
        $this->assertSame(15, $policy->grace_minutes);
        $this->assertSame('08:00', $policy->start_time);
        $this->assertSame('excess_only', $policy->grace_mode);
    }
}
