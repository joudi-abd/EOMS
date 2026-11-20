<?php

namespace App\Modules\Finance\Services\Assets;

use App\Modules\Finance\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Expenses carried by assets through the asset dimension (D13, spec §9.7, T20): live posted journal lines with an
 * asset_id on expense accounts (category accounts, asset_maintenance, project_costs with "asset operation").
 * Lines of reversed entries and of reversals are left out, so a reversed expense disappears. A line is "maintenance"
 * when its entry comes from an asset_maintenance voucher (#23), otherwise "operating" (fuel, ... — #8 with an asset).
 */
class AssetExpenses
{
    /**
     * The lines of one asset, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $assetId): array
    {
        return $this->base([$assetId])
            ->select(['l.id', 'l.entry_date', 'l.debit', 'l.credit', 'l.memo', 'l.project_id', 'l.currency_id', 'c.code as currency',
                'a.code as account_code', 'a.name as account_name', 'e.number as entry_number', 'v.id as voucher_id', 'v.number as voucher_number',
                'v.type as voucher_type'])
            ->orderByDesc('l.entry_date')->orderByDesc('l.id')->get()
            ->map(fn (object $l) => [
                'id' => (int) $l->id, 'date' => $l->entry_date, 'kind' => $l->voucher_type === 'asset_maintenance' ? 'maintenance' : 'operating',
                'currency' => $l->currency, 'amount' => Money::sub(Money::of((string) $l->debit), Money::of((string) $l->credit)),
                'account' => ['code' => $l->account_code, 'name' => $l->account_name], 'project_id' => $l->project_id === null ? null : (int) $l->project_id,
                'memo' => $l->memo, 'entry_number' => $l->entry_number, 'voucher_id' => $l->voucher_id === null ? null : (int) $l->voucher_id,
                'voucher_number' => $l->voucher_number,
            ])->all();
    }

    /**
     * Net totals per asset, kind and currency code.
     *
     * @param  list<int>  $assetIds
     * @return array<int, array{operating: array<string, string>, maintenance: array<string, string>}>
     */
    public function totals(array $assetIds): array
    {
        $totals = [];
        foreach ($assetIds as $id) {
            $totals[$id] = ['operating' => [], 'maintenance' => []];
        }
        if ($assetIds === []) {
            return $totals;
        }
        $rows = $this->base($assetIds)
            ->selectRaw("l.asset_id, c.code as currency, CASE WHEN v.type = 'asset_maintenance' THEN 'maintenance' ELSE 'operating' END as kind, SUM(l.debit) as debit, SUM(l.credit) as credit")
            ->groupBy('l.asset_id', 'c.code', 'kind')->get();
        foreach ($rows as $row) {
            $net = Money::round(Money::sub(Money::of($row->debit), Money::of($row->credit)), Money::SCALE);
            $totals[(int) $row->asset_id][$row->kind][$row->currency] = $net;
        }

        return $totals;
    }

    /** @param  list<int>  $assetIds */
    private function base(array $assetIds): Builder
    {
        return DB::table('fin_journal_lines as l')
            ->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->join('sys_currencies as c', 'c.id', '=', 'l.currency_id')
            ->leftJoin('fin_vouchers as v', fn ($join) => $join->on('v.id', '=', 'e.source_id')->where('e.source_type', '=', 'voucher'))
            ->whereIn('l.asset_id', $assetIds)
            ->where('a.type', 'expense')
            ->where('e.status', 'posted')
            ->whereNull('e.reversal_of_id');
    }
}
