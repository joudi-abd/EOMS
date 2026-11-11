<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalanceEntry;
use App\Modules\HR\Models\LeavePolicy;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveRequestDay;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\LeaveWorkflowService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $employeeUser;

    private User $hr;

    private User $director;

    private Employee $employee;

    private LeaveType $type;

    private LeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(8, 0, 0));
        $this->employeeUser = $this->actor('employee');
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('company_manager');
        $this->employee = Employee::create(['employee_no' => 'LV-1', 'user_id' => $this->employeeUser->id,
            'first_name' => 'Ali', 'last_name' => 'Hassan', 'hire_date' => '2025-01-01',
            'national_id' => 'LV-1', 'national_id_hash' => hash('sha256', 'LV-1')]);
        $this->employee->servicePeriods()->create(['start_date' => '2025-01-01']);
        $this->type = LeaveType::firstOrFail();
        $this->type->update(['active' => true]);
        $this->policy = LeavePolicy::create(['leave_type_id' => $this->type->id, 'effective_from' => '2026-01-01',
            'annual_days' => 2, 'pay_percent' => 100, 'grant_method' => 'upfront', 'year_basis' => 'fixed',
            'year_start' => '01-01', 'retroactive_days' => 10]);
        Sanctum::actingAs($this->employeeUser);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function input(array $extra = []): array
    {
        return [...['leave_type_id' => $this->type->id, 'start_date' => '2026-11-09',
            'end_date' => '2026-11-09', 'unit' => 'day', 'reason' => 'Family visit'], ...$extra];
    }

    private function submit(array $extra = [], bool $consent = false): array
    {
        $input = $this->input($extra);
        $quote = $this->postJson('/api/v1/leaves/preview', $input)->assertOk()->json('data');

        return $this->postJson('/api/v1/leaves', [...$input, 'quote_token' => $quote['quote_token'],
            'submission_key' => (string) Str::uuid(), 'unpaid_consent' => $consent])->assertCreated()->json('data');
    }

    private function action(array $row, string $action, array $extra = [])
    {
        return $this->postJson('/api/v1/leaves/'.$row['id'].'/'.$action,
            ['version' => $row['version'], 'reason' => 'Reviewed', ...$extra]);
    }

    private function balance(): int
    {
        return (int) LeaveBalanceEntry::where('service_period_id', $this->employee->servicePeriods()->first()->id)
            ->where('leave_type_id', $this->type->id)->whereDate('cycle_start', '2026-01-01')->sum('day_units');
    }

    public function test_upfront_grant_is_idempotent_and_manual_opening_prevents_double_credit(): void
    {
        $service = app(LeaveWorkflowService::class);
        $service->maintain($this->employee);
        $service->maintain($this->employee);
        $this->assertSame(2000000, $this->balance());
        $this->assertDatabaseCount('hr_leave_balance_entries', 1);
        $this->artisan('leaves:process')->assertSuccessful();
        $this->assertSame(2000000, $this->balance());
    }

    public function test_manual_opening_is_authoritative_for_the_cycle(): void
    {
        LeaveBalanceEntry::create(['service_period_id' => $this->employee->servicePeriods()->first()->id,
            'leave_type_id' => $this->type->id, 'leave_policy_id' => $this->policy->id,
            'cycle_start' => '2026-01-01', 'kind' => 'opening', 'day_units' => 500000,
            'reason' => 'Imported remainder', 'recorded_by_user_id' => $this->hr->id]);
        app(LeaveWorkflowService::class)->maintain($this->employee);
        $this->assertSame(500000, $this->balance());
        $this->assertDatabaseCount('hr_leave_balance_entries', 1);
    }

    public function test_first_year_monthly_remaining_instalments_total_the_full_entitlement(): void
    {
        $this->employee->update(['hire_date' => '2026-11-08']);
        $this->employee->servicePeriods()->first()->update(['start_date' => '2026-11-08']);
        $this->policy->update(['annual_days' => 12, 'grant_method' => 'monthly', 'first_year_method' => 'remaining']);
        app(LeaveWorkflowService::class)->maintain($this->employee);
        $this->assertSame(6000000, $this->balance());
        $this->travelTo(now()->setDate(2026, 12, 8));
        app(LeaveWorkflowService::class)->maintain($this->employee);
        app(LeaveWorkflowService::class)->maintain($this->employee);
        $this->assertSame(12000000, $this->balance());
    }

    public function test_workdays_exclude_friday_and_office_holidays(): void
    {
        DB::table('hr_office_holidays')->insert(['date' => '2026-11-14', 'name' => 'Holiday']);
        $this->postJson('/api/v1/leaves/preview', $this->input(['start_date' => '2026-11-12', 'end_date' => '2026-11-14']))
            ->assertOk()->assertJsonCount(1, 'data.days')->assertJsonPath('data.total_units', 1000000);
    }

    public function test_hour_and_unequal_half_day_use_actual_schedule_fraction(): void
    {
        $this->postJson('/api/v1/leaves/preview', $this->input(['unit' => 'hours', 'start_time' => '11:00', 'end_time' => '13:00']))
            ->assertOk()->assertJsonPath('data.total_units', 285714);
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/leaves/half-days', ['first_start' => '09:00', 'first_end' => '13:00',
            'second_start' => '13:00', 'second_end' => '16:00'])->assertOk();
        Sanctum::actingAs($this->employeeUser);
        $this->postJson('/api/v1/leaves/preview', $this->input(['unit' => 'half', 'half' => 'first']))
            ->assertOk()->assertJsonPath('data.total_units', 571429);
        $this->postJson('/api/v1/leaves/preview', $this->input(['unit' => 'hours', 'start_time' => '08:00', 'end_time' => '10:00']))->assertUnprocessable();
    }

    public function test_submission_reserves_and_rejecting_releases_exactly_once(): void
    {
        $row = $this->submit();
        $this->assertSame(1000000, $this->balance());
        Sanctum::actingAs($this->hr);
        $this->action($row, 'reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(2000000, $this->balance());
        $this->action($row, 'reject')->assertConflict();
        $this->assertSame(2000000, $this->balance());
    }

    public function test_approval_does_not_debit_twice_and_self_approval_is_forbidden(): void
    {
        $row = $this->submit();
        $this->action($row, 'approve')->assertForbidden();
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1000000, $this->balance());
        $this->action($row, 'approve')->assertConflict();
    }

    public function test_hr_employee_request_requires_director_and_cannot_be_reviewed_by_another_hr(): void
    {
        $this->employeeUser->syncRoles(['hr_manager']);
        $row = $this->submit();
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve')->assertForbidden();
        $this->getJson('/api/v1/leaves?scope=review')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->director);
        $this->action($row, 'approve')->assertOk();
    }

    public function test_one_request_per_day_even_for_non_overlapping_hour_ranges(): void
    {
        $this->submit(['unit' => 'hours', 'start_time' => '09:00', 'end_time' => '11:00']);
        $this->postJson('/api/v1/leaves/preview', $this->input(['unit' => 'hours', 'start_time' => '14:00', 'end_time' => '16:00']))->assertUnprocessable();
    }

    public function test_uncovered_part_requires_consent_and_is_allocated_chronologically(): void
    {
        $input = $this->input(['end_date' => '2026-11-11']);
        $quote = $this->postJson('/api/v1/leaves/preview', $input)->assertOk()
            ->assertJsonPath('data.days.2.unpaid_units', 1000000)->json('data');
        $body = [...$input, 'quote_token' => $quote['quote_token'], 'submission_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/leaves', $body)->assertUnprocessable();
        $this->postJson('/api/v1/leaves', [...$body, 'unpaid_consent' => true])->assertCreated();
        $this->assertSame(0, $this->balance());
    }

    public function test_retrying_same_submission_key_returns_original_without_reserving_twice(): void
    {
        $input = $this->input();
        $quote = $this->postJson('/api/v1/leaves/preview', $input)->assertOk()->json('data');
        $body = [...$input, 'quote_token' => $quote['quote_token'], 'submission_key' => (string) Str::uuid()];
        $first = $this->postJson('/api/v1/leaves', $body)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/leaves', $body)->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('hr_leave_requests', 1);
        $this->assertSame(1000000, $this->balance());
    }

    public function test_balance_change_after_preview_requires_new_preview(): void
    {
        $input = $this->input(['end_date' => '2026-11-10']);
        $quote = $this->postJson('/api/v1/leaves/preview', $input)->assertOk()->json('data');
        $this->submit(['start_date' => '2026-11-11', 'end_date' => '2026-11-11']);
        $this->postJson('/api/v1/leaves', [...$input, 'quote_token' => $quote['quote_token'], 'submission_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('hr_leave_requests', 1);
    }

    public function test_amendment_uses_original_policy_and_atomically_replaces_reservation(): void
    {
        $row = $this->submit();
        LeavePolicy::create(['leave_type_id' => $this->type->id, 'effective_from' => '2026-11-08',
            'annual_days' => 2, 'pay_percent' => 50, 'grant_method' => 'upfront', 'year_basis' => 'fixed', 'year_start' => '01-01', 'retroactive_days' => 0]);
        $input = $this->input(['start_date' => '2026-11-10', 'end_date' => '2026-11-11']);
        $quote = $this->postJson('/api/v1/leaves/'.$row['id'].'/preview', $input)->assertOk()
            ->assertJsonPath('data.pay_percent', 100)->json('data');
        $this->putJson('/api/v1/leaves/'.$row['id'], [...$input, 'version' => $row['version'], 'quote_token' => $quote['quote_token']])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame(0, $this->balance());
        $this->assertDatabaseHas('hr_leave_request_days', ['date' => '2026-11-09', 'active' => false]);
    }

    public function test_cross_year_request_never_uses_future_ungranted_balance_and_expires_as_a_whole(): void
    {
        $row = $this->submit(['start_date' => '2026-12-31', 'end_date' => '2027-01-02'], true);
        $this->assertSame(1000000, $row['days'][1]['unpaid_units']);
        $this->assertSame(1000000, $this->balance());
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->artisan('leaves:process')->assertSuccessful();
        $this->assertDatabaseHas('hr_leave_requests', ['id' => $row['id'], 'status' => 'expired']);
        $this->assertSame(2000000, $this->balance()); // historical cycle, not available in 2027
        $this->getJson('/api/v1/leaves/options')->assertOk()->assertJsonPath('data.types.0.available_units', 2000000);
    }

    public function test_approved_cancellation_requires_review_before_refund(): void
    {
        $row = $this->submit();
        Sanctum::actingAs($this->hr);
        $row = $this->action($row, 'approve')->assertOk()->json('data');
        Sanctum::actingAs($this->employeeUser);
        $row = $this->action($row, 'cancel')->assertOk()->json('data');
        $this->assertSame(1000000, $this->balance());
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve-change')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(2000000, $this->balance());
    }

    public function test_shortening_refunds_only_future_days_and_keeps_used_days(): void
    {
        $row = $this->submit(['end_date' => '2026-11-10']);
        Sanctum::actingAs($this->hr);
        $row = $this->action($row, 'approve')->assertOk()->json('data');
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(15, 0));
        Sanctum::actingAs($this->employeeUser);
        $this->action($row, 'cancel')->assertUnprocessable();
        $row = $this->action($row, 'shorten', ['from_date' => '2026-11-10'])->assertOk()->json('data');
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve-change')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1000000, $this->balance());
    }

    public function test_added_holiday_refunds_approved_day_and_preserves_audit_history(): void
    {
        $row = $this->submit();
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve')->assertOk();
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-09', 'name' => 'Office closed'])->assertCreated()->json('data');
        $this->assertSame(2000000, $this->balance());
        $this->assertDatabaseHas('hr_leave_balance_entries', ['kind' => 'holiday_refund', 'day_units' => 1000000]);
        $this->assertDatabaseHas('hr_leave_request_days', [
            'leave_request_id' => $row['id'], 'active' => false,
            'inactive_reason' => 'holiday', 'inactive_from_status' => 'approved',
        ]);
        // Deleting the holiday restores the leave day and re-debits the balance.
        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertDatabaseHas('hr_leave_request_days', [
            'leave_request_id' => $row['id'], 'date' => '2026-11-09', 'active' => true,
            'inactive_reason' => null, 'inactive_from_status' => null,
        ]);
        $this->assertSame(1000000, $this->balance());
        $this->assertSame('approved', LeaveRequest::find($row['id'])->status);
    }

    public function test_holiday_restore_still_finds_days_deactivated_before_inactive_reason(): void
    {
        $row = $this->submit();
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve')->assertOk();
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-09', 'name' => 'Office closed'])->assertCreated()->json('data');
        // Simulate a pre-migration row: holiday_refund exists, inactive_reason was never set.
        DB::table('hr_leave_request_days')->where('leave_request_id', $row['id'])->where('date', '2026-11-09')
            ->update(['inactive_reason' => null, 'inactive_from_status' => null]);
        $this->backfillLegacyDays();
        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertDatabaseHas('hr_leave_request_days', [
            'leave_request_id' => $row['id'], 'date' => '2026-11-09', 'active' => true,
        ]);
        $this->assertSame(1000000, $this->balance());
    }

    public function test_holiday_restore_skips_days_outside_the_service_period(): void
    {
        $row = $this->submit();
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve')->assertOk();
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-09', 'name' => 'Office closed'])->assertCreated()->json('data');
        $this->employee->servicePeriods()->first()->update(['end_date' => '2026-11-08']);
        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertDatabaseHas('hr_leave_request_days', [
            'leave_request_id' => $row['id'], 'date' => '2026-11-09', 'active' => false, 'inactive_reason' => 'holiday',
        ]);
        $this->assertSame(2000000, $this->balance());
    }

    public function test_rehire_does_not_inherit_old_balance_or_receive_an_automatic_opening(): void
    {
        app(LeaveWorkflowService::class)->maintain($this->employee);
        $this->employee->servicePeriods()->first()->update(['end_date' => '2026-10-31']);
        $this->employee->servicePeriods()->create(['start_date' => '2026-11-01']);
        $this->getJson('/api/v1/leaves/options')->assertOk()->assertJsonPath('data.types.0.available_units', 0);
    }

    public function test_another_employee_cannot_view_or_modify_requests(): void
    {
        $row = $this->submit();
        Sanctum::actingAs($this->actor('employee'));
        $this->getJson('/api/v1/leaves/'.$row['id'])->assertForbidden();
        $this->action($row, 'withdraw')->assertForbidden();
        $this->getJson('/api/v1/leaves?scope=review')->assertForbidden();
        $this->getJson('/api/v1/leaves')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_audit_failure_rolls_back_reservation_and_request(): void
    {
        $input = $this->input();
        $quote = $this->postJson('/api/v1/leaves/preview', $input)->assertOk()->json('data');
        $this->mock(AuditLogger::class, function ($mock) {
            $mock->shouldReceive('log')->andThrow(new \RuntimeException('Audit unavailable'));
        });
        $this->postJson('/api/v1/leaves', [...$input, 'quote_token' => $quote['quote_token'], 'submission_key' => (string) Str::uuid()])->assertStatus(500);
        $this->assertDatabaseCount('hr_leave_requests', 0);
        $this->assertSame(2000000, $this->balance());
    }

    public function test_untracked_type_needs_approval_but_does_not_reserve_numeric_balance(): void
    {
        $this->policy->update(['annual_days' => null, 'grant_method' => null, 'year_basis' => null, 'year_start' => null, 'pay_percent' => 0]);
        $row = $this->submit();
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, $row['days'][0]['pay_percent']);
        $this->assertDatabaseCount('hr_leave_balance_entries', 0);
    }

    public function test_termination_prevents_new_requests_and_approval_of_old_pending_request(): void
    {
        $row = $this->submit();
        $this->employee->update(['status' => 'terminated', 'termination_date' => '2026-11-08']);
        $this->employee->servicePeriods()->first()->update(['end_date' => '2026-11-08']);
        $this->postJson('/api/v1/leaves/preview', $this->input(['start_date' => '2026-11-10', 'end_date' => '2026-11-10']))->assertUnprocessable();
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve')->assertUnprocessable();
        $this->action($row, 'reject')->assertOk();
        $this->assertSame(2000000, $this->balance());
    }

    public function test_cancellation_rechecks_time_when_hr_approves_and_does_not_refund_used_time(): void
    {
        $row = $this->submit();
        Sanctum::actingAs($this->hr);
        $row = $this->action($row, 'approve')->assertOk()->json('data');
        Sanctum::actingAs($this->employeeUser);
        $row = $this->action($row, 'cancel')->assertOk()->json('data');
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(15, 0));
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve-change')->assertUnprocessable();
        $this->assertSame(1000000, $this->balance());
        $this->action($row, 'reject-change')->assertOk();
    }

    public function test_adjustment_cannot_spend_reserved_balance(): void
    {
        $this->submit(['end_date' => '2026-11-10']);
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/leave-settings/balance-entries', ['employee_id' => $this->employee->id,
            'leave_type_id' => $this->type->id, 'kind' => 'adjustment', 'days' => '-1', 'reason' => 'Correction'])
            ->assertUnprocessable();
        $this->assertSame(0, $this->balance());
    }

    public function test_rehire_cannot_amend_a_pending_request_from_an_old_service_period(): void
    {
        $row = $this->submit();
        $this->employee->servicePeriods()->first()->update(['end_date' => '2026-11-08']);
        $this->employee->servicePeriods()->create(['start_date' => '2026-11-09']);
        $this->travelTo(now()->setDate(2026, 11, 9));
        $input = $this->input(['start_date' => '2026-11-10', 'end_date' => '2026-11-10']);
        $this->postJson('/api/v1/leaves/'.$row['id'].'/preview', $input)->assertConflict();
        $this->assertSame(1000000, $this->balance());
    }

    public function test_creation_response_includes_persisted_defaults_and_matches_a_subsequent_read(): void
    {
        $row = $this->submit();
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, $row['version']);
        $this->assertArrayHasKey('change_request', $row);
        $this->assertArrayHasKey('reviewed_by', $row);
        $this->assertArrayHasKey('review_note', $row);
        $this->assertNull($row['change_request']);
        $this->assertNull($row['reviewed_by']);
        $this->assertNull($row['review_note']);
        $this->assertTrue($row['days'][0]['active']);

        $saved = $this->getJson('/api/v1/leaves/'.$row['id'])->assertOk()->json('data');
        foreach (['status', 'version', 'change_request', 'reviewed_by', 'review_note', 'days'] as $field) {
            $this->assertSame($saved[$field], $row[$field], $field);
        }
    }

    public function test_holiday_removal_does_not_revive_a_cancelled_request(): void
    {
        $row = $this->submit(['end_date' => '2026-11-10']);
        Sanctum::actingAs($this->hr);
        $row = $this->action($row, 'approve')->assertOk()->json('data');
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-09', 'name' => 'Office closed'])->assertCreated()->json('data');
        $row = $this->getJson('/api/v1/leaves/'.$row['id'])->json('data');
        Sanctum::actingAs($this->employeeUser);
        $row = $this->action($row, 'cancel')->assertOk()->json('data');
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve-change')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(2000000, $this->balance());

        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertSame('cancelled', LeaveRequest::find($row['id'])->status);
        $this->assertSame(2000000, $this->balance());
        $this->assertDatabaseHas('hr_leave_request_days', ['leave_request_id' => $row['id'], 'date' => '2026-11-09', 'active' => false]);
    }

    public function test_holiday_removal_keeps_a_day_cut_by_a_shortening(): void
    {
        // Holiday on the 11th, leave shortened from the 10th: the 11th is past the new end and stays off.
        $id = $this->shortenedAroundHoliday('2026-11-11', '2026-11-10');
        $this->assertSame(['2026-11-09'], $this->activeDays($id));
        $this->assertSame(4000000, $this->balance());
    }

    public function test_holiday_removal_restores_a_day_before_a_shortening(): void
    {
        // Holiday on the 9th, leave shortened from the 11th: the 9th is inside what remains and comes back.
        $id = $this->shortenedAroundHoliday('2026-11-09', '2026-11-11');
        $this->assertSame(['2026-11-09', '2026-11-10'], $this->activeDays($id));
        $this->assertSame(3000000, $this->balance());
    }

    public function test_holiday_removal_restores_a_day_of_a_request_edited_before_approval(): void
    {
        $this->policy->update(['annual_days' => 5]);
        $row = $this->submit(['end_date' => '2026-11-10']);
        Sanctum::actingAs($this->hr);
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-10', 'name' => 'Office closed'])->assertCreated()->json('data');
        // The employee extends the still-pending request to the 11th; the 10th is a holiday at that moment.
        Sanctum::actingAs($this->employeeUser);
        $row = $this->getJson('/api/v1/leaves/'.$row['id'])->json('data');
        $input = $this->input(['end_date' => '2026-11-11']);
        $quote = $this->postJson('/api/v1/leaves/'.$row['id'].'/preview', $input)->assertOk()->json('data');
        $this->putJson('/api/v1/leaves/'.$row['id'], [...$input, 'version' => $row['version'], 'quote_token' => $quote['quote_token']])->assertOk();
        $this->assertSame(['2026-11-09', '2026-11-11'], $this->activeDays($row['id']));

        Sanctum::actingAs($this->hr);
        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertSame(['2026-11-09', '2026-11-10', '2026-11-11'], $this->activeDays($row['id']));
        $this->assertSame('pending', LeaveRequest::find($row['id'])->status);
        $this->assertSame(2000000, $this->balance());
    }

    public function test_holiday_removal_does_not_restore_a_day_an_edit_moved_away_from(): void
    {
        $this->policy->update(['annual_days' => 5]);
        $row = $this->submit(['end_date' => '2026-11-10']);
        Sanctum::actingAs($this->hr);
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-09', 'name' => 'Office closed'])->assertCreated()->json('data');
        // The request now covers the 11th–12th only.
        Sanctum::actingAs($this->employeeUser);
        $row = $this->getJson('/api/v1/leaves/'.$row['id'])->json('data');
        $input = $this->input(['start_date' => '2026-11-11', 'end_date' => '2026-11-12']);
        $quote = $this->postJson('/api/v1/leaves/'.$row['id'].'/preview', $input)->assertOk()->json('data');
        $this->putJson('/api/v1/leaves/'.$row['id'], [...$input, 'version' => $row['version'], 'quote_token' => $quote['quote_token']])->assertOk();

        Sanctum::actingAs($this->hr);
        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertSame(['2026-11-11', '2026-11-12'], $this->activeDays($row['id']));
        $this->assertSame(3000000, $this->balance());
    }

    public function test_holiday_removal_restores_a_legacy_day_that_had_no_balance_entry(): void
    {
        // A leave type without an annual limit records no balance entries at all.
        $this->policy->update(['annual_days' => null]);
        $row = $this->submit(['end_date' => '2026-11-10']);
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve')->assertOk();
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-09', 'name' => 'Office closed'])->assertCreated()->json('data');
        // Simulate a row deactivated before inactive_reason existed.
        DB::table('hr_leave_request_days')->where('leave_request_id', $row['id'])->where('date', '2026-11-09')
            ->update(['inactive_reason' => null, 'inactive_from_status' => null]);
        $this->backfillLegacyDays();
        $this->assertSame(0, LeaveBalanceEntry::query()->whereIn('leave_request_day_id',
            LeaveRequestDay::query()->where('leave_request_id', $row['id'])->select('id'))->count());

        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertSame(['2026-11-09', '2026-11-10'], $this->activeDays($row['id']));
        $this->assertSame('approved', LeaveRequest::find($row['id'])->status);
    }

    public function test_legacy_day_cancelled_by_the_employee_is_not_restored(): void
    {
        $this->policy->update(['annual_days' => null]);
        $row = $this->submit(['end_date' => '2026-11-10']);
        Sanctum::actingAs($this->hr);
        $row = $this->action($row, 'approve')->assertOk()->json('data');
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-09', 'name' => 'Office closed'])->assertCreated()->json('data');
        $row = $this->getJson('/api/v1/leaves/'.$row['id'])->json('data');
        Sanctum::actingAs($this->employeeUser);
        $row = $this->action($row, 'cancel')->assertOk()->json('data');
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve-change')->assertOk();
        DB::table('hr_leave_request_days')->where('leave_request_id', $row['id'])->update(['inactive_reason' => null, 'inactive_from_status' => null]);
        $this->backfillLegacyDays();

        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertSame([], $this->activeDays($row['id']));
        $this->assertSame('cancelled', LeaveRequest::find($row['id'])->status);
    }

    public function test_holiday_removal_restores_a_day_of_a_request_edited_before_the_reason_column(): void
    {
        $this->policy->update(['annual_days' => 5]);
        $row = $this->submit(['end_date' => '2026-11-10']);
        Sanctum::actingAs($this->hr);
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-10', 'name' => 'Office closed'])->assertCreated()->json('data');
        Sanctum::actingAs($this->employeeUser);
        $row = $this->getJson('/api/v1/leaves/'.$row['id'])->json('data');
        $input = $this->input(['end_date' => '2026-11-11']);
        $quote = $this->postJson('/api/v1/leaves/'.$row['id'].'/preview', $input)->assertOk()->json('data');
        $this->putJson('/api/v1/leaves/'.$row['id'], [...$input, 'version' => $row['version'], 'quote_token' => $quote['quote_token']])->assertOk();
        // Before the column existed neither the holiday day nor the replaced day carried a reason.
        DB::table('hr_leave_request_days')->where('leave_request_id', $row['id'])->update(['inactive_reason' => null, 'inactive_from_status' => null]);
        $this->backfillLegacyDays();
        $this->assertSame(['edited', 'holiday'], LeaveRequestDay::query()->where('leave_request_id', $row['id'])->where('active', false)
            ->orderBy('inactive_reason')->pluck('inactive_reason')->all());

        Sanctum::actingAs($this->hr);
        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertSame(['2026-11-09', '2026-11-10', '2026-11-11'], $this->activeDays($row['id']));
        $this->assertSame('pending', LeaveRequest::find($row['id'])->status);
    }

    public function test_legacy_single_day_approved_leave_without_balance_comes_back_approved(): void
    {
        $this->policy->update(['annual_days' => null]);
        $row = $this->submit();
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve')->assertOk();
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => '2026-11-09', 'name' => 'Office closed'])->assertCreated()->json('data');
        $this->assertSame('cancelled', LeaveRequest::find($row['id'])->status, 'The holiday took its only day');
        DB::table('hr_leave_request_days')->where('leave_request_id', $row['id'])->update(['inactive_reason' => null, 'inactive_from_status' => null]);
        $this->backfillLegacyDays();

        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();
        $this->assertSame(['2026-11-09'], $this->activeDays($row['id']));
        $this->assertSame('approved', LeaveRequest::find($row['id'])->status);
    }

    private function backfillLegacyDays(): void
    {
        (require base_path('app/Modules/HR/Database/Migrations/2026_11_15_000002_backfill_leave_day_inactive_reason.php'))->up();
    }

    /** Approved 9–11 Nov leave (5-day policy), a holiday inside it, a shortening, then the holiday deleted. */
    private function shortenedAroundHoliday(string $holidayDate, string $from): int
    {
        $this->policy->update(['annual_days' => 5]);
        $row = $this->submit(['end_date' => '2026-11-11']);
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve')->assertOk();
        $holiday = $this->postJson('/api/v1/leave-settings/holidays', ['date' => $holidayDate, 'name' => 'Office closed'])->assertCreated()->json('data');
        $row = $this->getJson('/api/v1/leaves/'.$row['id'])->json('data');
        Sanctum::actingAs($this->employeeUser);
        $row = $this->action($row, 'shorten', ['from_date' => $from])->assertOk()->json('data');
        Sanctum::actingAs($this->hr);
        $this->action($row, 'approve-change')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->deleteJson('/api/v1/leave-settings/holidays/'.$holiday['id'])->assertOk();

        return $row['id'];
    }

    private function activeDays(int $requestId): array
    {
        return LeaveRequestDay::query()->where('leave_request_id', $requestId)->where('active', true)->orderBy('date')
            ->pluck('date')->map(fn ($date) => substr((string) $date, 0, 10))->all();
    }
}
