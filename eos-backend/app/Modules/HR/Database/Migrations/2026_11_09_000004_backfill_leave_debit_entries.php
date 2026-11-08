<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Leave approved before debit entries existed only kept its reservation. Add the same release + debit
     * pair approval now writes, so every approved day shows its debit. Net balance is unchanged.
     */
    public function up(): void
    {
        $now = now();
        DB::table('hr_leave_request_days as d')->join('hr_leave_requests as r', 'r.id', '=', 'd.leave_request_id')
            ->where('r.status', 'approved')->where('d.active', true)->where('d.covered_units', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('hr_leave_balance_entries as e')->whereColumn('e.leave_request_day_id', 'd.id')->where('e.kind', 'debit'))
            ->orderBy('d.id')->select('d.id', 'd.cycle_start', 'd.covered_units', 'r.service_period_id', 'r.leave_type_id', 'r.leave_policy_id', 'r.policy')
            ->chunk(500, function ($days) use ($now) {
                $rows = [];
                foreach ($days as $day) {
                    if ((json_decode($day->policy, true)['annual_days'] ?? null) === null) {
                        continue;
                    }
                    $base = ['service_period_id' => $day->service_period_id, 'leave_type_id' => $day->leave_type_id,
                        'leave_policy_id' => $day->leave_policy_id, 'cycle_start' => $day->cycle_start,
                        'leave_request_day_id' => $day->id, 'recorded_by_user_id' => 0, 'created_at' => $now, 'updated_at' => $now];
                    $rows[] = [...$base, 'kind' => 'release', 'day_units' => $day->covered_units, 'reason' => 'ترحيل: تحويل حجز إجازة معتمدة سابقاً إلى خصم'];
                    $rows[] = [...$base, 'kind' => 'debit', 'day_units' => -$day->covered_units, 'reason' => 'ترحيل: خصم إجازة معتمدة سابقاً'];
                }
                if ($rows !== []) {
                    DB::table('hr_leave_balance_entries')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('hr_leave_balance_entries')->where('recorded_by_user_id', 0)
            ->whereIn('reason', ['ترحيل: تحويل حجز إجازة معتمدة سابقاً إلى خصم', 'ترحيل: خصم إجازة معتمدة سابقاً'])->delete();
    }
};
