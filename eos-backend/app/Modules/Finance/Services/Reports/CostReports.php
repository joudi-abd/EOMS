<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\HR\Models\Employee;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Currency;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cost reports from the journal: T18 company expenses by category tree and month (the internal-expenses sheet) and
 * T19 payroll cost by employee, project and component (from the payroll accrual entries; Finance does not read the
 * Payroll module).
 */
class CostReports
{
    /** Expense accounts that are project costing or its contra, not company expenses. */
    private const NOT_COMPANY_EXPENSE = ['project_costs', 'payroll_allocation_contra', 'overhead_allocation_contra'];

    public function __construct(private SystemAccounts $roles, private ProjectService $projects) {}

    /**
     * T18: per currency (and consolidated), the expense category tree with one column per month of the period and
     * subtotals on every parent. A line's category is its `expense_category_id`, or else the category whose account it
     * is posted to (one category per account; e.g. asset maintenance). Expense lines that map to no category are
     * listed per account under `uncategorized`. Amounts are net (debit − credit), so reversals cancel out.
     */
    public function expensesByCategory(ReportFrame $frame): array
    {
        $months = PartyReports::months($frame->from, $frame->to);
        $excluded = array_values(array_filter(array_map(fn ($r) => $this->roles->mappedId($r), self::NOT_COMPANY_EXPENSE)));
        $rows = $frame->scopeCurrency($frame->scopeBranches(JournalLine::query()))
            ->join('fin_accounts as a', 'a.id', '=', 'fin_journal_lines.account_id')->where('a.type', 'expense')
            ->whereNotIn('fin_journal_lines.account_id', $excluded)
            ->whereBetween('fin_journal_lines.entry_date', [$frame->from, $frame->to])
            ->groupBy('fin_journal_lines.expense_category_id', 'fin_journal_lines.account_id', 'fin_journal_lines.currency_id', 'fin_journal_lines.entry_date')
            ->selectRaw('fin_journal_lines.expense_category_id, fin_journal_lines.account_id, fin_journal_lines.currency_id, fin_journal_lines.entry_date,
                SUM(fin_journal_lines.debit) - SUM(fin_journal_lines.credit) as net, SUM(fin_journal_lines.base_debit) - SUM(fin_journal_lines.base_credit) as base_net')
            ->get();
        $categories = ExpenseCategory::query()->orderBy('id')->get(['id', 'parent_id', 'name', 'account_id', 'is_active']);
        $byAccount = $categories->whereNotNull('account_id')->groupBy('account_id')->filter(fn ($g) => $g->count() === 1)->map(fn ($g) => $g->first()->id);
        $currencies = Currency::query()->get()->keyBy('id');
        $c = $frame->consolidator;
        // [currency key][category id or 'acc:ID'][month] => amount; currency key 0 = consolidated
        $cells = [];
        foreach ($rows as $r) {
            $categoryId = $r->expense_category_id !== null ? (int) $r->expense_category_id : ($byAccount[$r->account_id] ?? null);
            $key = $categoryId ?? 'acc:'.$r->account_id;
            $month = substr((string) $r->entry_date, 0, 7);
            $net = Sums::num($r->net);
            $cells[(int) $r->currency_id][$key][$month] = Money::add($cells[(int) $r->currency_id][$key][$month] ?? '0', $net);
            if ($frame->consolidated) {
                $cells[0][$key][$month] = Money::add($cells[0][$key][$month] ?? '0', $c->value($net, Sums::num($r->base_net), (int) $r->currency_id));
            }
        }
        $children = $categories->groupBy(fn ($cat) => $cat->parent_id ?? 0);
        $accounts = Account::query()->whereIn('id', $rows->pluck('account_id')->unique())->get(['id', 'code', 'name'])->keyBy('id');
        $build = function (array $amounts) use ($months, $children, $accounts): array {
            $node = function (ExpenseCategory $cat, int $depth) use (&$node, $amounts, $months, $children): ?array {
                $kids = [];
                foreach ($children->get($cat->id, collect()) as $child) {
                    if (($built = $node($child, $depth + 1)) !== null) {
                        $kids[] = $built;
                    }
                }
                $own = [];
                foreach ($months as $m) {
                    $own[$m] = Money::add($amounts[$cat->id][$m] ?? '0', Money::sum(array_map(fn ($k) => $k['months'][$m], $kids)));
                }
                if ($kids === [] && ! isset($amounts[$cat->id])) {
                    return null;
                }

                return ['category_id' => $cat->id, 'name' => $cat->name, 'depth' => $depth, 'is_subtotal' => $kids !== [], 'months' => $own,
                    'total' => Money::sum($own), 'children' => $kids];
            };
            $tree = [];
            foreach ($children->get(0, collect()) as $root) {
                if (($built = $node($root, 0)) !== null) {
                    $tree[] = $built;
                }
            }
            $uncategorized = [];
            foreach ($amounts as $key => $byMonth) {
                if (is_string($key) && str_starts_with($key, 'acc:')) {
                    $account = $accounts[(int) substr($key, 4)];
                    $cellsRow = array_map(fn ($m) => $byMonth[$m] ?? '0', array_combine($months, $months));
                    $uncategorized[] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'months' => $cellsRow,
                        'total' => Money::sum($cellsRow)];
                }
            }
            $totals = [];
            foreach ($months as $m) {
                $totals[$m] = Money::add(Money::sum(array_map(fn ($n) => $n['months'][$m], $tree)), Money::sum(array_map(fn ($u) => $u['months'][$m], $uncategorized)));
            }

            return ['categories' => $tree, 'uncategorized' => $uncategorized, 'month_totals' => $totals, 'total' => Money::sum($totals)];
        };
        $blocks = [];
        foreach ($cells as $currencyId => $amounts) {
            if ($currencyId !== 0) {
                $blocks[] = ['currency_id' => $currencyId, 'code' => $currencies[$currencyId]->code, ...$build($amounts)];
            }
        }
        usort($blocks, fn ($a, $b) => strcmp($a['code'], $b['code']));
        $result = ['months' => $months, 'currencies' => $blocks];
        if ($frame->consolidated) {
            $result['consolidated'] = $build($cells[0] ?? []);
        }

        return [...$frame->header(), ...$result];
    }

    /**
     * T19: payroll cost of the period from the payroll accrual entries (vouchers `payroll_accrual`): the expense side of
     * each line (debit − credit on expense accounts: salaries expense, project costs, component accounts), by employee,
     * project and component. The component is the line memo the accrual writes, or the account's name when there is no
     * memo. Also the payroll allocated to projects in the period (allocation entries on the loaded-payroll category).
     */
    public function payrollCost(ReportFrame $frame, ?int $employeeId = null, ?int $projectId = null): array
    {
        $accrualEntries = JournalEntry::query()->select('id')->where('source_type', 'voucher')
            ->whereIn('source_id', Voucher::query()->select('id')->where('type', 'payroll_accrual'));
        $rows = $this->expenseLines($frame)->whereIn('fin_journal_lines.journal_entry_id', $accrualEntries)
            ->when($employeeId, fn ($q, $id) => $q->where('fin_journal_lines.employee_id', $id))
            ->when($projectId, fn ($q, $id) => $q->where('fin_journal_lines.project_id', $id))
            ->groupBy('fin_journal_lines.employee_id', 'fin_journal_lines.project_id', 'fin_journal_lines.account_id', 'fin_journal_lines.memo', 'fin_journal_lines.currency_id')
            ->selectRaw('fin_journal_lines.employee_id, fin_journal_lines.project_id, fin_journal_lines.account_id, fin_journal_lines.memo, fin_journal_lines.currency_id,
                SUM(fin_journal_lines.debit) - SUM(fin_journal_lines.credit) as net, SUM(fin_journal_lines.base_debit) - SUM(fin_journal_lines.base_credit) as base_net')
            ->get();
        $loadedPayroll = ProjectCostCategory::query()->where('system_key', 'loaded_payroll')->value('id');
        $allocationEntries = JournalEntry::query()->select('id')->where('source_type', 'voucher')
            ->whereIn('source_id', Voucher::query()->select('id')->where('type', 'allocation'));
        $allocated = $this->expenseLines($frame)->whereIn('fin_journal_lines.journal_entry_id', $allocationEntries)
            ->where('fin_journal_lines.account_id', $this->roles->mappedId('project_costs') ?? 0)->where('fin_journal_lines.project_cost_category_id', $loadedPayroll ?? 0)
            ->when($projectId, fn ($q, $id) => $q->where('fin_journal_lines.project_id', $id))
            ->groupBy('fin_journal_lines.project_id', 'fin_journal_lines.currency_id')
            ->selectRaw('fin_journal_lines.project_id, fin_journal_lines.currency_id, SUM(fin_journal_lines.debit) - SUM(fin_journal_lines.credit) as net,
                SUM(fin_journal_lines.base_debit) - SUM(fin_journal_lines.base_credit) as base_net')
            ->get();

        $codes = Currency::query()->pluck('code', 'id');
        $accounts = Account::query()->whereIn('id', $rows->pluck('account_id')->unique())->pluck('name', 'id');
        $employees = Employee::query()->whereIn('id', $rows->pluck('employee_id')->filter()->unique())->get()->mapWithKeys(fn ($e) => [$e->id => $e->full_name]);
        $projectNames = $this->projects->query()->whereIn('id', $rows->pluck('project_id')->merge($allocated->pluck('project_id'))->filter()->unique())->pluck('name', 'id');
        $c = $frame->consolidator;
        $detail = [];
        foreach ($rows as $r) {
            $amount = Sums::num($r->net);
            if (Money::isZero($amount)) {
                continue;
            }
            $row = ['employee_id' => $r->employee_id === null ? null : (int) $r->employee_id, 'employee' => $employees[$r->employee_id] ?? null,
                'project_id' => $r->project_id === null ? null : (int) $r->project_id, 'project' => $projectNames[$r->project_id] ?? null,
                'account_id' => (int) $r->account_id, 'component' => $r->memo ?: ($accounts[$r->account_id] ?? null), 'currency' => $codes[$r->currency_id] ?? null,
                'amount' => $amount];
            if ($frame->consolidated) {
                $row['amount_base'] = $c->value($amount, Sums::num($r->base_net), (int) $r->currency_id);
            }
            $detail[] = $row;
        }
        $group = function (array $keys) use ($detail, $frame): array {
            $out = [];
            foreach ($detail as $row) {
                $id = implode('|', array_map(fn ($k) => (string) $row[$k], [...$keys, 'currency']));
                $out[$id] ??= [...array_intersect_key($row, array_flip([...$keys, 'currency'])), 'amount' => '0', ...($frame->consolidated ? ['amount_base' => '0'] : [])];
                $out[$id]['amount'] = Money::add($out[$id]['amount'], $row['amount']);
                if ($frame->consolidated) {
                    $out[$id]['amount_base'] = Money::add($out[$id]['amount_base'], $row['amount_base']);
                }
            }
            ksort($out);

            return array_values($out);
        };
        $allocations = $allocated->map(fn ($a) => ['project_id' => (int) $a->project_id, 'project' => $projectNames[$a->project_id] ?? null,
            'currency' => $codes[$a->currency_id] ?? null, 'amount' => Sums::num($a->net)])->values()->all();
        $result = ['lines' => $detail, 'by_employee' => $group(['employee_id', 'employee']), 'by_project' => $group(['project_id', 'project']),
            'by_component' => $group(['component']), 'totals' => $group([]), 'allocated_to_projects' => $allocations];
        if ($frame->consolidated) {
            $result['consolidated'] = ['total' => Money::sum(array_column($detail, 'amount_base'))];
        }

        return [...$frame->header(), ...$result];
    }

    /** Lines of the frame on expense accounts within the period. */
    private function expenseLines(ReportFrame $frame): Builder
    {
        return $frame->scopeCurrency($frame->scopeBranches(JournalLine::query()))
            ->join('fin_accounts as a', 'a.id', '=', 'fin_journal_lines.account_id')->where('a.type', 'expense')
            ->whereBetween('fin_journal_lines.entry_date', [$frame->from, $frame->to]);
    }
}
