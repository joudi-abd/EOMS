<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Settings\Models\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The common frame of every finance report (spec §10): the branches it covers (the requested branch within the
 * reader's reach, or the whole reach), the period (`from`–`to`) or the day (`as_of` = `to`), one currency or all, and
 * the view: every currency separately, or separately plus consolidated in the base currency (D8).
 */
final class ReportFrame
{
    /**
     * @param  list<int>|null  $branchIds  null = every branch
     */
    public function __construct(
        public readonly ?array $branchIds,
        public readonly ?int $branchId,
        public readonly ?string $from,
        public readonly string $to,
        public readonly ?Currency $currency,
        public readonly bool $consolidated,
        public readonly ?Consolidator $consolidator,
    ) {}

    public function currencyId(): ?int
    {
        return $this->currency?->id;
    }

    /** Limits journal lines (or any table with a branch column) to the frame's branches. */
    public function scopeBranches(Builder|QueryBuilder $query, string $column = 'fin_journal_lines.branch_id'): Builder|QueryBuilder
    {
        return $this->branchIds === null ? $query : $query->whereIn($column, $this->branchIds);
    }

    /** Limits journal lines to the frame's currency, when one is chosen. */
    public function scopeCurrency(Builder|QueryBuilder $query, string $column = 'fin_journal_lines.currency_id'): Builder|QueryBuilder
    {
        return $this->currency === null ? $query : $query->where($column, $this->currency->id);
    }

    /** The filters as applied, for the report header. */
    public function header(): array
    {
        $header = ['filters' => ['branch_id' => $this->branchId, 'branch_ids' => $this->branchIds, 'from' => $this->from, 'to' => $this->to,
            'currency' => $this->currency?->code, 'view' => $this->consolidated ? 'consolidated' : 'separate']];
        if ($this->consolidated && $this->consolidator !== null) {
            $header['consolidation'] = $this->consolidator->header();
        }

        return $header;
    }
}
