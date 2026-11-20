<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\AttendancePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\HR\Concerns\LeaveAttendanceFixture;
use Tests\TestCase;

/** Attendance regressions found in the backend audit. */
class AttendanceHardeningTest extends TestCase
{
    use LeaveAttendanceFixture, RefreshDatabase;

    public function test_nobody_approves_their_own_attendance_day(): void
    {
        $this->punches('2026-11-04', ['09:00', '16:00'], '2026-11-04T17:00:00');
        $day = $this->calculate('2026-11-04');
        // The HR manager is also the employee here.
        $this->employee->update(['user_id' => $this->hr->id]);
        Sanctum::actingAs($this->hr);

        $this->postJson("/api/v1/attendance/days/{$day->id}/approve", ['version' => $day->version])->assertForbidden();
        $this->assertNull($day->fresh()->approved_at);
    }

    public function test_a_new_device_runs_on_the_office_time_zone(): void
    {
        Sanctum::actingAs($this->actor('system_admin'));
        $office = AttendancePolicy::officeTimezone();

        $this->postJson('/api/v1/attendance/devices', ['name' => 'Gate', 'serial_number' => 'SN-TZ-1', 'timezone' => 'UTC'])
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->postJson('/api/v1/attendance/devices', ['name' => 'Gate', 'serial_number' => 'SN-TZ-2'])
            ->assertCreated()->assertJsonPath('data.device.timezone', $office);
    }

    public function test_a_disabled_device_can_still_be_attested_up_to_when_it_stopped(): void
    {
        $this->punches('2026-11-03', ['09:00', '16:00']);
        $this->device->update(['is_active' => false]);
        Sanctum::actingAs($this->actor('system_admin'));

        $this->postJson("/api/v1/attendance/devices/{$this->device->id}/confirm-sync", [
            'complete_through' => '2026-11-03T17:00:00+03:00', 'note' => 'Device died on the 4th; data checked on site.',
        ])->assertOk();
    }

    public function test_non_numeric_enrollment_id_is_404_not_500(): void
    {
        Sanctum::actingAs($this->actor('system_admin'));

        $this->getJson('/api/v1/attendance/fingerprint-enrollments/abc')->assertNotFound();
    }
}
