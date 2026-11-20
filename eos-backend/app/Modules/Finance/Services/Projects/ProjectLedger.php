<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Reads a project's money from the ledger (journal lines are the source of truth): costs on `project_costs`, revenue on
 * `project_revenue`, client money on `client_advances`. Amounts are brought into one currency: lines already in that
 * currency at face value, the others through their base (USD) amounts and that currency's bulletin rate on or before
 * the given day (spec §6.1, OP-6).
 */
class ProjectLedger
{
    public function __construct(private SystemAccounts $roles, private ExchangeRateService $rates) {}

    /** Lines on the account mapped to `$role` for the project, up to `$date` when given. */
    public function lines(string $role, int $projectId, ?string $date = null): Builder
    {
        return JournalLine::query()->where('account_id', $this->roles->mappedId($role) ?? 0)->where('project_id', $projectId)
            ->when($date !== null, fn (Builder $q) => $q->where('entry_date', '<=', $date));
    }

    /** Client money received for the project: `client_advances` movements that do not come from a project billing (#1, opening balances). */
    public function receiptLines(int $projectId, ?string $date = null): Builder
    {
        $billingEntries = JournalEntry::query()->select('id')->where('source_type', 'voucher')
            ->whereIn('source_id', Voucher::query()->select('id')->where('type', 'project_billing'));

        return $this->lines('client_advances', $projectId, $date)->whereNotIn('journal_entry_id', $billingEntries);
    }

    /** Σ(debit − credit) of the lines expressed in `$currency` (see class doc). */
    public function net(Builder $lines, Currency $currency, string $date): string
    {
        return $this->netBy($lines, null, $currency, $date)[0] ?? '0';
    }

    /**
     * Σ(debit − credit) per value of `$column` (null = one total under key 0), expressed in `$currency`.
     *
     * @return array<int, string>
     */
    public function netBy(Builder $lines, ?string $column, Currency $currency, string $date): array
    {
        $group = $column === null ? ['currency_id'] : [$column, 'currency_id'];
        $rows = (clone $lines)->toBase()->groupBy($group)
            ->selectRaw(($column === null ? '0 as grp' : $column.' as grp').', currency_id, SUM(debit) - SUM(credit) as net, SUM(base_debit) - SUM(base_credit) as base_net')
            ->get();
        $face = [];
        $base = [];
        foreach ($rows as $row) {
            $key = (int) $row->grp;
            if ((int) $row->currency_id === $currency->id) {
                $face[$key] = Money::add($face[$key] ?? '0', Money::round(Money::of($row->net), Money::SCALE));
            } else {
                $base[$key] = Money::add($base[$key] ?? '0', Money::round(Money::of($row->base_net), Money::SCALE));
            }
        }
        $result = $face;
        if (array_filter($base, fn (string $v) => ! Money::isZero($v)) !== []) {
            $rate = $this->rateOf($currency, $date);
            foreach ($base as $key => $amount) {
                $result[$key] = Money::add($result[$key] ?? '0', Money::mul($amount, $rate));
            }
        }

        return array_map(fn (string $v) => Money::round($v, Money::SCALE), $result);
    }

    /** Units of `$currency` per one base unit, on or before `$date` (422 when the bulletin has none). */
    public function rateOf(Currency $currency, string $date): string
    {
        if ($currency->is_base) {
            return '1';
        }
        $found = $this->rates->rateToBaseOnOrBefore($currency->code, $date);
        if ($found === null) {
            throw ValidationException::withMessages(['date' => ["لا يوجد سعر لـ {$currency->code} في النشرة حتى تاريخ {$date}؛ أدخل النشرة أولاً."]]);
        }

        return $found['rate'];
    }

    /**
     * A project's figures in its contract currency up to `$date`: costs per category, billable / fee-base / loaded
     * costs, client receipts, the amount already billed (revenue) and the client advances not yet billed.
     *
     * @return array{currency: Currency, by_category: array<int, string>, billable: string, fee_base: string, loaded: string,
     *     total_costs: string, receipts: string, billed: string, advances: string}
     */
    public function figures(ProjectProfile $profile, string $date): array
    {
        $currency = Currency::query()->findOrFail($profile->contract_currency_id);
        $byCategory = $this->netBy($this->lines('project_costs', $profile->project_id, $date), 'project_cost_category_id', $currency, $date);
        $billableIds = ProjectCostCategory::query()->where('is_billable', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $feeBaseIds = $profile->fee_base_category_ids === null || $profile->fee_base_category_ids === []
            ? $billableIds
            : array_values(array_intersect($billableIds, array_map('intval', $profile->fee_base_category_ids)));
        $billable = $feeBase = $loaded = '0';
        foreach ($byCategory as $categoryId => $amount) {
            if (in_array($categoryId, $billableIds, true)) {
                $billable = Money::add($billable, $amount);
            } else {
                $loaded = Money::add($loaded, $amount);
            }
            if (in_array($categoryId, $feeBaseIds, true)) {
                $feeBase = Money::add($feeBase, $amount);
            }
        }

        return [
            'currency' => $currency,
            'by_category' => $byCategory,
            'billable' => $billable,
            'fee_base' => $feeBase,
            'loaded' => $loaded,
            'total_costs' => Money::add($billable, $loaded),
            'receipts' => Money::neg($this->net($this->receiptLines($profile->project_id, $date), $currency, $date)),
            'billed' => Money::neg($this->net($this->lines('project_revenue', $profile->project_id, $date), $currency, $date)),
            'advances' => Money::neg($this->net($this->lines('client_advances', $profile->project_id, $date), $currency, $date)),
        ];
    }
}
