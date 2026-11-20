<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Support\Money;

/**
 * Project profitability (spec §6.2, D13), in the contract currency, up to `$date` (default today):
 *
 *   revenue        = Σ project_revenue
 *   direct_costs   = Σ project_costs of billable categories
 *   loaded_costs   = Σ project_costs of the other categories (loaded payroll + allocated overhead, OP-2)
 *   gross_margin   = revenue − direct_costs
 *   net_margin     = revenue − direct_costs − loaded_costs
 *   client_balance = receipts − billed (positive = the client has money with us)
 *   fee            = company fee: cost_plus = fee_pct × fee-base costs; percentage_of_receipts = fee_pct × receipts
 *
 * Reused by the reports phase (T8, T11).
 */
class ProjectProfitability
{
    public function __construct(private ProjectLedger $ledger, private ProjectBillingCalculator $calculator) {}

    /**
     * @return array{project_id: int, currency: string, date: string, revenue: string, direct_costs: string, loaded_costs: string,
     *     total_costs: string, gross_margin: string, net_margin: string, receipts: string, billed: string, client_balance: string,
     *     fee_pct: string|null, fee: string|null, fee_base_costs: string, costs_by_category: list<array<string, mixed>>}
     */
    public function for(ProjectProfile $profile, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $f = $this->ledger->figures($profile, $date);
        $decimals = (int) $f['currency']->decimals;
        $feePct = $profile->fee_pct === null ? null : Money::of((string) $profile->fee_pct);
        $fee = match ($profile->contract_type) {
            'cost_plus' => $this->calculator->percent($f['fee_base'], $feePct),
            'percentage_of_receipts' => $this->calculator->percent($f['receipts'], $feePct),
            default => null,
        };
        $round = fn (string $v) => Money::round($v, $decimals);
        $categories = ProjectCostCategory::query()->orderBy('sort')->orderBy('id')->get();
        $byCategory = [];
        foreach ($categories as $category) {
            if (isset($f['by_category'][$category->id])) {
                $byCategory[] = ['id' => $category->id, 'name' => $category->name, 'system_key' => $category->system_key,
                    'is_billable' => $category->is_billable, 'amount' => $round($f['by_category'][$category->id])];
            }
        }
        if (isset($f['by_category'][0])) {
            $byCategory[] = ['id' => null, 'name' => 'بلا تصنيف', 'system_key' => null, 'is_billable' => false, 'amount' => $round($f['by_category'][0])];
        }

        return [
            'project_id' => $profile->project_id,
            'currency' => $f['currency']->code,
            'date' => $date,
            'revenue' => $round($f['billed']),
            'direct_costs' => $round($f['billable']),
            'loaded_costs' => $round($f['loaded']),
            'total_costs' => $round($f['total_costs']),
            'gross_margin' => $round(Money::sub($f['billed'], $f['billable'])),
            'net_margin' => $round(Money::sub($f['billed'], $f['total_costs'])),
            'receipts' => $round($f['receipts']),
            'billed' => $round($f['billed']),
            'client_balance' => $round(Money::sub($f['receipts'], $f['billed'])),
            'fee_pct' => $feePct,
            'fee' => $fee === null ? null : $round($fee),
            'fee_base_costs' => $round($f['fee_base']),
            'costs_by_category' => $byCategory,
        ];
    }
}
