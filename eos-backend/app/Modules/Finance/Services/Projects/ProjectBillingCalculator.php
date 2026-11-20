<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Support\Money;

/**
 * Suggested project billing (spec §6.1, D9). All amounts in the contract currency, up to the billing date:
 *
 *   billable_costs = Σ project_costs of billable categories
 *   fee_base_costs = billable costs in `fee_base_category_ids` (cost_plus; null = every billable category)
 *   already_billed = Σ project_revenue
 *   cost_plus:               suggested = billable_costs + fee_pct × fee_base_costs − already_billed
 *                            (= billable_costs × (1 + fee_pct) − already_billed when the fee base is every billable category)
 *   percentage_of_receipts:  suggested = billable_costs + fee_pct × receipts − already_billed
 *   lump_sum:                manual (OP-3); max = contract_value − already_billed
 *   internal:                not available (422)
 *
 * `fee_pct` is a percentage (8 = 8%). The accountant edits the suggestion before approving.
 */
class ProjectBillingCalculator
{
    public function __construct(private ProjectLedger $ledger) {}

    /**
     * @return array{project_id: int, contract_type: string, currency: string, date: string, billable_costs: string, fee_base_costs: string,
     *     fee_pct: string|null, fee: string|null, receipts: string, already_billed: string, suggested: string|null, max: string|null,
     *     available_advances: string}
     */
    public function suggest(ProjectProfile $profile, string $date): array
    {
        abort_if($profile->contract_type === 'internal', 422, 'المشروع داخلي ولا تُصدر له مطالبات.');
        $f = $this->ledger->figures($profile, $date);
        $decimals = (int) $f['currency']->decimals;
        $feePct = $profile->fee_pct === null ? null : Money::of((string) $profile->fee_pct);
        $fee = match ($profile->contract_type) {
            'cost_plus' => $this->percent($f['fee_base'], $feePct),
            'percentage_of_receipts' => $this->percent($f['receipts'], $feePct),
            default => null,
        };
        $suggested = null;
        $max = null;
        if ($profile->contract_type === 'lump_sum') {
            $max = Money::max('0', Money::sub(Money::of((string) $profile->contract_value), $f['billed']));
        } else {
            $suggested = Money::max('0', Money::sub(Money::add($f['billable'], $fee ?? '0'), $f['billed']));
        }

        return [
            'project_id' => $profile->project_id,
            'contract_type' => $profile->contract_type,
            'currency' => $f['currency']->code,
            'date' => $date,
            'billable_costs' => Money::round($f['billable'], $decimals),
            'fee_base_costs' => Money::round($f['fee_base'], $decimals),
            'fee_pct' => $feePct,
            'fee' => $fee === null ? null : Money::round($fee, $decimals),
            'receipts' => Money::round($f['receipts'], $decimals),
            'already_billed' => Money::round($f['billed'], $decimals),
            'suggested' => $suggested === null ? null : Money::round($suggested, $decimals),
            'max' => $max === null ? null : Money::round($max, $decimals),
            'available_advances' => Money::round(Money::max('0', $f['advances']), $decimals),
        ];
    }

    /** `$amount × $pct / 100`. */
    public function percent(string $amount, ?string $pct): ?string
    {
        return $pct === null ? null : Money::div(Money::mul($amount, $pct, 8), '100', Money::SCALE);
    }
}
