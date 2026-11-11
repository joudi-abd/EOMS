<?php

use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Days deactivated before inactive_reason existed get their reason once, from what each path left behind,
 * so holiday removal reads the columns only:
 * - holiday: a holiday_refund balance entry for the day, or the request's leave.holiday_refunded audit for that date;
 *   the request was "approved" at that moment if it had been approved before the holiday's audit (or the day was debited);
 * - edited: the request has a newer day row (only an edit creates rows after submission);
 * - released: anything else (withdrawn, rejected, cancelled, shortened, expired, service end).
 */
return new class extends Migration
{
    public function up(): void
    {
        $type = (new LeaveRequest)->getMorphClass();
        $days = DB::table('hr_leave_request_days')->where('active', false)->whereNull('inactive_reason')->orderBy('id')->get();
        foreach ($days as $day) {
            $date = substr((string) $day->date, 0, 10);
            $holidayAudit = DB::table('usr_audit_logs')->where('action', 'leave.holiday_refunded')
                ->where('auditable_type', $type)->where('auditable_id', $day->leave_request_id)
                ->where('new_values->date', $date)->orderBy('id')->first(['id', 'created_at']);
            $refunded = DB::table('hr_leave_balance_entries')->where('leave_request_day_id', $day->id)->where('kind', 'holiday_refund')->exists();
            if ($holidayAudit || $refunded) {
                $approved = DB::table('hr_leave_balance_entries')->where('leave_request_day_id', $day->id)->where('kind', 'debit')->exists()
                    || DB::table('usr_audit_logs')->whereIn('action', ['leave.approve', 'leave.auto_approved'])
                        ->where('auditable_type', $type)->where('auditable_id', $day->leave_request_id)
                        ->when($holidayAudit, fn ($q) => $q->where('id', '<', $holidayAudit->id))->exists();
                $values = ['inactive_reason' => 'holiday', 'inactive_from_status' => $approved ? 'approved' : 'pending'];
            } elseif (DB::table('hr_leave_request_days')->where('leave_request_id', $day->leave_request_id)->where('id', '>', $day->id)->exists()) {
                $values = ['inactive_reason' => 'edited'];
            } else {
                $values = ['inactive_reason' => 'released'];
            }
            DB::table('hr_leave_request_days')->where('id', $day->id)->update($values);
        }
    }

    public function down(): void
    {
        // Backfill only.
    }
};
