<?php

namespace App\Modules\Finance\Services\Assets;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;

/**
 * T20 asset register data (spec §10): assets filtered by status, holder, category and branch (within the caller's
 * `finance.reports` reach), each with its cost and its operating and maintenance expenses per currency, plus group
 * totals by status, holder, category or branch. Currencies stay separate (D8); no depreciation (D22).
 */
class AssetReportService
{
    public const GROUPS = ['status', 'holder', 'category', 'branch'];

    public function __construct(private AssetService $assets, private AssetExpenses $expenses) {}

    /** @param  array<string, mixed>  $filters  status, holder_employee_id, category_id, branch_id, search, group_by */
    public function register(array $filters, User $user): array
    {
        $assets = $this->assets->filtered(Asset::query(), $filters, $user, 'finance.reports')->orderBy('code')->get();
        $rows = $this->assets->presentMany($assets);
        $totals = $this->expenses->totals($assets->pluck('id')->map(fn ($id) => (int) $id)->all());
        $groupBy = in_array($filters['group_by'] ?? null, self::GROUPS, true) ? $filters['group_by'] : 'status';
        $groups = [];
        foreach ($rows as $i => $row) {
            $row = [...$row, 'operating_expenses' => $totals[$row['id']]['operating'], 'maintenance_expenses' => $totals[$row['id']]['maintenance']];
            $rows[$i] = $row;
            [$key, $label] = match ($groupBy) {
                'holder' => [$row['holder']['id'] ?? 0, $row['holder']['name'] ?? 'بلا حائز'],
                'category' => [$row['category']['id'] ?? 0, $row['category']['name'] ?? 'بلا تصنيف'],
                'branch' => [$row['branch']['id'], $row['branch']['name']],
                default => [$row['status'], $row['status']],
            };
            $group = $groups[$key] ?? ['key' => $key, 'label' => $label, 'count' => 0, 'cost' => [], 'book_value' => [],
                'operating_expenses' => [], 'maintenance_expenses' => []];
            $group['count']++;
            $group['cost'] = $this->add($group['cost'], [$row['currency'] => (string) $row['total_cost']]);
            $group['book_value'] = $this->add($group['book_value'], [$row['currency'] => $row['book_value']]);
            $group['operating_expenses'] = $this->add($group['operating_expenses'], $row['operating_expenses']);
            $group['maintenance_expenses'] = $this->add($group['maintenance_expenses'], $row['maintenance_expenses']);
            $groups[$key] = $group;
        }

        return ['group_by' => $groupBy, 'groups' => array_values($groups), 'assets' => $rows];
    }

    /**
     * @param  array<string, string>  $into
     * @param  array<string, string>  $amounts
     * @return array<string, string>
     */
    private function add(array $into, array $amounts): array
    {
        foreach ($amounts as $currency => $amount) {
            $into[$currency] = Money::add($into[$currency] ?? '0', Money::of($amount));
        }
        ksort($into);

        return $into;
    }
}
