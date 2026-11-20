<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Support\Money;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Support\Collection;

/**
 * Project statement data (T9, D14, §9.4) — the frontend renders the PDF (OP-15). Amounts in the contract currency up to
 * `$date`: incoming = client payments (#1 and opening balances on client_advances), outgoing = project_costs grouped by
 * cost category in the Excel order (materials, labour, design, misc, project overhead, … then the loaded categories).
 *
 * Variants: `internal` (everything) and `client` (D14: billable categories only, no fee or profitability).
 * `hide_internal` hides the fee and profitability on the internal variant too.
 */
class ProjectStatementService
{
    public const SIGNATURES = ['إدارة المشاريع', 'إدارة التنفيذ', 'مهندس الموقع', 'المحاسبة العامة', 'الفرع', 'المدير العام'];

    public function __construct(private ProjectLedger $ledger, private ProjectProfitability $profitability, private ProjectService $projects,
        private SettingService $settings) {}

    public function build(ProjectProfile $profile, string $variant = 'internal', bool $hideInternal = false, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $client = $variant === 'client';
        $hide = $client || $hideInternal;
        $project = $this->projects->find($profile->project_id);
        $currency = Currency::query()->findOrFail($profile->contract_currency_id);
        $decimals = (int) $currency->decimals;
        $rate = null;
        $convert = function (JournalLine $l, string $sign) use ($currency, $date, &$rate, $decimals): string {
            $face = $sign === 'debit' ? Money::sub((string) $l->debit, (string) $l->credit) : Money::sub((string) $l->credit, (string) $l->debit);
            if ($l->currency_id === $currency->id) {
                return Money::round($face, $decimals);
            }
            $base = $sign === 'debit' ? Money::sub((string) $l->base_debit, (string) $l->base_credit) : Money::sub((string) $l->base_credit, (string) $l->base_debit);
            $rate ??= $this->ledger->rateOf($currency, $date);

            return Money::round(Money::mul($base, $rate), $decimals);
        };

        $incomingLines = $this->ledger->receiptLines($profile->project_id, $date)->orderBy('entry_date')->orderBy('id')->get();
        $outgoingLines = $this->ledger->lines('project_costs', $profile->project_id, $date)->orderBy('entry_date')->orderBy('id')->get();
        $sources = $this->sources($incomingLines->merge($outgoingLines));
        $codes = Currency::query()->pluck('code', 'id');
        $parties = Party::query()->whereIn('id', $incomingLines->merge($outgoingLines)->pluck('party_id')->filter()->unique())->pluck('name', 'id');

        $incoming = $incomingLines->map(fn (JournalLine $l) => [
            'date' => $l->entry_date->toDateString(), 'number' => $sources[$l->journal_entry_id]['number'] ?? null,
            'cash_box' => $sources[$l->journal_entry_id]['box'] ?? null, 'description' => $sources[$l->journal_entry_id]['description'] ?? $l->memo,
            'original_amount' => Money::sub((string) $l->credit, (string) $l->debit), 'original_currency' => $codes[$l->currency_id] ?? null,
            'amount' => $convert($l, 'credit'),
        ])->values();

        $categories = ProjectCostCategory::query()->orderBy('sort')->orderBy('id')->get()->keyBy('id');
        $groups = [];
        foreach ($outgoingLines->groupBy(fn (JournalLine $l) => (int) $l->project_cost_category_id) as $categoryId => $lines) {
            $category = $categories->get($categoryId);
            $billable = $category?->is_billable ?? false;
            if ($client && ! $billable) {
                continue;
            }
            $rows = $lines->map(fn (JournalLine $l) => [
                'date' => $l->entry_date->toDateString(), 'number' => $sources[$l->journal_entry_id]['number'] ?? null,
                'party' => $parties[$l->party_id] ?? null, 'description' => $l->memo ?? ($sources[$l->journal_entry_id]['description'] ?? null),
                'original_amount' => Money::sub((string) $l->debit, (string) $l->credit), 'original_currency' => $codes[$l->currency_id] ?? null,
                'amount' => $convert($l, 'debit'),
            ])->values();
            $groups[] = ['category_id' => $category?->id, 'name' => $category?->name ?? 'بلا تصنيف', 'system_key' => $category?->system_key,
                'is_billable' => $billable, 'sort' => $category?->sort ?? PHP_INT_MAX, 'lines' => $rows->all(),
                'total' => Money::round(Money::sum($rows->pluck('amount')), $decimals)];
        }
        usort($groups, fn ($a, $b) => [$a['is_billable'] ? 0 : 1, $a['sort']] <=> [$b['is_billable'] ? 0 : 1, $b['sort']]);
        $groups = array_map(fn ($g) => array_diff_key($g, ['sort' => true]), $groups);

        $totalIn = Money::round(Money::sum($incoming->pluck('amount')), $decimals);
        $totalOut = Money::round(Money::sum(array_column($groups, 'total')), $decimals);
        $statement = [
            'variant' => $variant,
            'hide_internal' => $hide,
            'header' => [
                'company' => (string) $this->settings->get('company.name', ''),
                'statement_code' => $profile->code,
                'project' => $project?->name,
                'project_id' => $profile->project_id,
                'client' => $profile->client_party_id === null ? null : Party::query()->whereKey($profile->client_party_id)->value('name'),
                'date' => $date,
                'currency' => $currency->code,
                'contract_type' => $profile->contract_type,
                'contract_value' => $profile->contract_value === null ? null : Money::of((string) $profile->contract_value),
            ],
            'incoming' => $incoming->all(),
            'outgoing' => $groups,
            'totals' => ['incoming' => $totalIn, 'outgoing' => $totalOut, 'remaining' => Money::sub($totalIn, $totalOut)],
            'signatures' => self::SIGNATURES,
        ];
        if (! $hide) {
            $profitability = $this->profitability->for($profile, $date);
            $statement['header']['fee_pct'] = $profitability['fee_pct'];
            $statement['totals']['fee'] = $profitability['fee'];
            $statement['totals']['remaining_after_fee'] = $profitability['fee'] === null ? null : Money::sub($statement['totals']['remaining'], $profitability['fee']);
            $statement['profitability'] = $profitability;
        }

        return $statement;
    }

    /**
     * Voucher number, box and description behind each entry (entries not made from a voucher show their own number).
     *
     * @param  Collection<int, JournalLine>  $lines
     * @return array<int, array{number: string|null, box: string|null, description: string|null}>
     */
    private function sources(Collection $lines): array
    {
        $entries = JournalEntry::query()->whereIn('id', $lines->pluck('journal_entry_id')->unique())->get()->keyBy('id');
        $vouchers = Voucher::query()->whereIn('id', $entries->where('source_type', 'voucher')->pluck('source_id')->unique())->get()->keyBy('id');
        $boxes = CashBox::query()->whereIn('id', $vouchers->pluck('cash_box_id')->filter()->unique())->pluck('name', 'id');
        $out = [];
        foreach ($entries as $id => $entry) {
            $voucher = $entry->source_type === 'voucher' ? $vouchers->get($entry->source_id) : null;
            $out[$id] = ['number' => $voucher?->number ?? $entry->number, 'box' => $voucher === null ? null : ($boxes[$voucher->cash_box_id] ?? null),
                'description' => $voucher?->description];
        }

        return $out;
    }
}
