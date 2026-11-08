<?php

namespace Tests\Feature\HR\Concerns;

use App\Modules\HR\Models\AttendanceBinding;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendanceDevice;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeavePolicy;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\Attendance\AttendanceCalculator;
use App\Modules\HR\Services\Attendance\AttendanceImportService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** Shared fixture: one employee, an active leave type with a 10-day policy, HR actor and a fingerprint device. */
trait LeaveAttendanceFixture
{
    protected User $employeeUser;

    protected User $hr;

    protected Employee $employee;

    protected LeaveType $type;

    protected LeavePolicy $policy;

    protected ?AttendanceDevice $device = null;

    protected int $event = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Wednesday evening, after the 16:00 end and the two-hour finalisation window.
        $this->travelTo(CarbonImmutable::parse('2026-11-04T18:30:00+03:00'));
        $this->employeeUser = $this->actor('employee');
        $this->hr = $this->actor('hr_manager');
        $this->employee = Employee::create(['employee_no' => 'LA-1', 'user_id' => $this->employeeUser->id,
            'first_name' => 'Ali', 'last_name' => 'Hassan', 'hire_date' => '2025-01-01',
            'national_id' => 'LA-1', 'national_id_hash' => hash('sha256', 'LA-1')]);
        $this->employee->servicePeriods()->create(['start_date' => '2025-01-01']);
        $this->type = LeaveType::firstOrFail();
        $this->type->update(['active' => true]);
        $this->policy = LeavePolicy::create(['leave_type_id' => $this->type->id, 'effective_from' => '2026-01-01',
            'annual_days' => 10, 'pay_percent' => 100, 'grant_method' => 'upfront', 'year_basis' => 'fixed',
            'year_start' => '01-01', 'retroactive_days' => 10]);
    }

    protected function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Device whose sync is confirmed through $through (local time), with optional punches. */
    protected function punches(string $date, array $times, ?string $through = null): void
    {
        if (! $this->device) {
            $this->device = AttendanceDevice::create(['name' => 'Office', 'serial_number' => 'SN-LA',
                'timezone' => 'Asia/Damascus', 'token_hash' => hash('sha256', 'token')]);
            AttendanceBinding::create(['device_id' => $this->device->id, 'employee_id' => $this->employee->id,
                'device_user_id' => '7', 'valid_from' => '2025-01-01']);
        }
        $events = array_map(fn ($time) => ['external_id' => 'e'.(++$this->event), 'device_user_id' => '7',
            'occurred_at' => $date.'T'.$time.':00+03:00', 'direction' => 'unknown'], $times);
        app(AttendanceImportService::class)->import($this->device, $events, $through ? $through.'+03:00' : null);
    }

    protected function calculate(string $date): AttendanceDay
    {
        return app(AttendanceCalculator::class)->calculate($this->employee, $date);
    }

    protected function leave(array $input, bool $consent = false): array
    {
        Sanctum::actingAs($this->employeeUser);
        $input = ['leave_type_id' => $this->type->id, 'unit' => 'day', 'reason' => 'Personal', ...$input];
        $input['end_date'] ??= $input['start_date'];
        $quote = $this->postJson('/api/v1/leaves/preview', $input)->assertOk()->json('data');

        return $this->postJson('/api/v1/leaves', [...$input, 'quote_token' => $quote['quote_token'],
            'submission_key' => (string) Str::uuid(), 'unpaid_consent' => $consent])->assertCreated()->json('data');
    }

    protected function leaveAction(array $row, string $action, User $actor, array $extra = []): array
    {
        Sanctum::actingAs($actor);

        return $this->postJson('/api/v1/leaves/'.$row['id'].'/'.$action,
            ['version' => $row['version'], 'reason' => 'Reviewed', ...$extra])->assertOk()->json('data');
    }

    protected function approvedLeave(array $input, bool $consent = false): array
    {
        return $this->leaveAction($this->leave($input, $consent), 'approve', $this->hr);
    }
}
