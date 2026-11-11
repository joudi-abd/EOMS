<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Runs prepared before explicit decisions stored undecided items at their full amount. In every run that is
 * not approved yet, an undecided item now counts as zero; line totals are rebuilt from the remaining items.
 * Approved runs are history and stay as they were locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        $openLines = DB::table('pay_run_lines')->join('pay_runs', 'pay_runs.id', '=', 'pay_run_lines.pay_run_id')
            ->whereIn('pay_runs.status', ['draft', 'submitted'])->pluck('pay_run_lines.id');
        foreach ($openLines->chunk(500) as $chunk) {
            DB::table('pay_run_items')->whereIn('pay_run_line_id', $chunk->all())->where('status', 'proposed')->update(['amount' => 0]);
            foreach ($chunk as $lineId) {
                $items = DB::table('pay_run_items')->where('pay_run_line_id', $lineId)->where('status', '!=', 'proposed')->get(['category', 'amount']);
                $earnings = round($items->where('category', 'earning')->sum(fn ($i) => (float) $i->amount), 2);
                $deductions = round($items->where('category', 'deduction')->sum(fn ($i) => (float) $i->amount), 2);
                DB::table('pay_run_lines')->where('id', $lineId)
                    ->update(['earnings' => $earnings, 'deductions' => $deductions, 'net' => round($earnings - $deductions, 2)]);
            }
        }
    }

    public function down(): void
    {
        // Data correction only; the previous amounts are still in proposed_amount.
    }
};
