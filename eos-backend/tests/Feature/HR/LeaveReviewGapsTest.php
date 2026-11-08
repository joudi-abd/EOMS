<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalanceEntry;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\EmployeeRehireService;
use App\Modules\HR\Services\EmployeeTerminationService;
use App\Modules\HR\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\HR\Concerns\LeaveAttendanceFixture;
use Tests\TestCase;

class LeaveReviewGapsTest extends TestCase
{
    use LeaveAttendanceFixture, RefreshDatabase;

    private function periodId(): int
    {
        return (int) $this->employee->servicePeriods()->orderByDesc('start_date')->value('id');
    }

    private function onBehalf(array $input, array $extra = [], int $status = 201): TestResponse
    {
        Sanctum::actingAs($this->hr);
        $input = ['leave_type_id' => $this->type->id, 'unit' => 'day', 'reason' => 'Called in sick', 'employee_id' => $this->employee->id, ...$input];
        $input['end_date'] ??= $input['start_date'];
        $quote = $this->postJson('/api/v1/leaves/preview', $input)->assertOk()->json('data');

        return $this->postJson('/api/v1/leaves', [...$input, ...$extra, 'quote_token' => $quote['quote_token'],
            'submission_key' => (string) Str::uuid()])->assertStatus($status);
    }

    public function test_ledger_records_debit_on_approval_and_refund_on_cancellation(): void
    {
        $approved = $this->approvedLeave(['start_date' => '2026-11-05']);
        $this->leave(['start_date' => '2026-11-10']);
        $kinds = fn () => LeaveBalanceEntry::query()->orderBy('id')->where('kind', '!=', 'grant')->pluck('kind')->all();
        $this->assertSame(['reserve', 'release', 'debit', 'reserve'], $kinds());

        $balance = app(LeaveBalanceService::class)->breakdown($this->periodId(), $this->type->id, '2026-01-01');
        $this->assertSame(['granted_units' => 10_000_000, 'used_units' => 1_000_000, 'reserved_units' => 1_000_000,
            'available_units' => 8_000_000], $balance);
        Sanctum::actingAs($this->employeeUser);
        $this->getJson('/api/v1/leaves/options')->assertOk()
            ->assertJsonPath('data.types.0.balance.used_units', 1_000_000)
            ->assertJsonPath('data.types.0.available_units', 8_000_000)
            ->assertJsonPath('data.types.0.awaiting_opening', false);

        $row = $this->leaveAction($approved, 'cancel', $this->employeeUser);
        $this->leaveAction($row, 'approve-change', $this->hr);
        $this->assertSame('refund', LeaveBalanceEntry::query()->orderByDesc('id')->value('kind'));
        $this->assertSame(9_000_000, app(LeaveBalanceService::class)->available($this->periodId(), $this->type->id, '2026-01-01'));
    }

    public function test_backfill_adds_missing_debits_without_changing_the_balance(): void
    {
        $this->approvedLeave(['start_date' => '2026-11-05']);
        // Simulate leave approved before debit entries existed.
        LeaveBalanceEntry::query()->whereIn('kind', ['release', 'debit'])->delete();
        $before = app(LeaveBalanceService::class)->available($this->periodId(), $this->type->id, '2026-01-01');

        (require base_path('app/Modules/HR/Database/Migrations/2026_11_09_000004_backfill_leave_debit_entries.php'))->up();
        (require base_path('app/Modules/HR/Database/Migrations/2026_11_09_000004_backfill_leave_debit_entries.php'))->up();

        $this->assertSame(1, LeaveBalanceEntry::where('kind', 'debit')->count(), 'Running twice must not duplicate.');
        $this->assertSame($before, app(LeaveBalanceService::class)->available($this->periodId(), $this->type->id, '2026-01-01'));
    }

    public function test_rehired_employee_is_flagged_until_hr_records_the_opening_balance(): void
    {
        app(EmployeeTerminationService::class)->terminate($this->employee, ['termination_date' => '2026-10-31', 'reason' => 'Contract end'], $this->hr);
        app(EmployeeRehireService::class)->rehire($this->employee->refresh(), ['effective_date' => '2026-11-02', 'reason' => 'Returned'], $this->hr);
        $this->assertSame(1, $this->hr->notifications()->get()->where('data.kind', 'leave.opening_balance_needed')->count());

        Sanctum::actingAs($this->hr);
        $pending = $this->getJson('/api/v1/leave-settings/pending-openings')->assertOk()->json('data');
        $this->assertSame($this->employee->id, $pending[0]['employee_id']);
        $this->assertSame($this->type->id, $pending[0]['leave_types'][0]['leave_type_id']);
        $this->getJson('/api/v1/leaves/options?employee_id='.$this->employee->id)->assertOk()
            ->assertJsonPath('data.types.0.awaiting_opening', true);

        $this->postJson('/api/v1/leave-settings/balance-entries', ['employee_id' => $this->employee->id,
            'leave_type_id' => $this->type->id, 'kind' => 'opening', 'days' => '4', 'reason' => 'Agreed on return'])->assertSuccessful();
        $this->getJson('/api/v1/leave-settings/pending-openings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/leaves/options?employee_id='.$this->employee->id)->assertOk()
            ->assertJsonPath('data.types.0.awaiting_opening', false)
            ->assertJsonPath('data.types.0.available_units', 4_000_000);
    }

    public function test_leave_settings_need_leaves_manage_not_employees_manage(): void
    {
        $clerk = $this->actor('employee');
        $clerk->givePermissionTo('employees.manage');
        Sanctum::actingAs($clerk);
        $this->getJson('/api/v1/leave-settings')->assertForbidden();
        $this->getJson('/api/v1/leaves/options')->assertOk()->assertJsonPath('data.can_configure', false);
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/leave-settings')->assertOk();
        $this->getJson('/api/v1/leaves/options')->assertOk()->assertJsonPath('data.can_configure', true);
    }

    public function test_hr_enters_leave_for_an_employee_who_owns_the_request(): void
    {
        $row = $this->onBehalf(['start_date' => '2026-11-03'])->json('data');
        $this->assertSame('pending', $row['status']);
        $this->assertSame($this->employeeUser->id, $row['submitted_by']);
        $this->assertSame($this->hr->id, $row['entered_by']);
        $this->assertSame('hr_manager', $row['approver_role']);
        $this->assertSame('leave.entered_for_you', $this->employeeUser->notifications()->first()?->data['kind']);
        // The HR user who entered it is not asked to review it again.
        $this->assertSame(0, $this->hr->notifications()->get()->where('data.kind', 'leave.submitted')->count());

        Sanctum::actingAs($this->employeeUser);
        $this->assertSame([$row['id']], array_column($this->getJson('/api/v1/leaves')->assertOk()->json('data'), 'id'));
        // HR can act for the owner, e.g. withdraw it when the employee asks.
        $withdrawn = $this->leaveAction($row, 'withdraw', $this->hr);
        $this->assertSame('withdrawn', $withdrawn['status']);
    }

    public function test_hr_can_approve_on_entry_and_attendance_follows(): void
    {
        $row = $this->onBehalf(['start_date' => '2026-11-03'], ['approve_now' => true])->json('data');
        $this->assertSame('approved', $row['status']);
        $this->assertSame($this->hr->id, $row['reviewed_by']);
        $this->assertSame('on_leave', $this->calculate('2026-11-03')->status);
        $this->assertSame(1, LeaveBalanceEntry::where('kind', 'debit')->count());
    }

    public function test_on_behalf_entry_keeps_review_routing_and_needs_permission(): void
    {
        // An ordinary employee cannot file leave for a colleague.
        $colleagueUser = $this->actor('employee');
        Employee::create(['employee_no' => 'C-1', 'user_id' => $colleagueUser->id, 'first_name' => 'C', 'last_name' => 'One',
            'hire_date' => '2025-01-01', 'national_id' => 'C-1', 'national_id_hash' => hash('sha256', 'C-1')])
            ->servicePeriods()->create(['start_date' => '2025-01-01']);
        Sanctum::actingAs($colleagueUser);
        $this->postJson('/api/v1/leaves/preview', ['leave_type_id' => $this->type->id, 'unit' => 'day', 'reason' => 'x',
            'start_date' => '2026-11-03', 'end_date' => '2026-11-03', 'employee_id' => $this->employee->id])->assertForbidden();

        // Leave for an HR employee still goes to the company manager, so HR cannot approve it on entry.
        $this->employeeUser->assignRole('hr_manager');
        $this->onBehalf(['start_date' => '2026-11-03'], ['approve_now' => true], 403);
        $row = LeaveRequest::query()->latest('id')->firstOrFail();
        $this->assertSame('company_manager', $row->approver_role);
        $this->assertSame('pending', $row->status);
    }
}
