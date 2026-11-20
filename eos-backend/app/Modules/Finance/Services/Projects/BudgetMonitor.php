<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\ProjectBudgetLine;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\Scope;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SystemNotification;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Budget watch (D10, R13, OP-6). After every posting (and reversal) PostingService hands the entry here, inside the
 * posting transaction. For each (project, cost category) the entry touched on `project_costs`, every budget line of
 * that pair is re-measured: actual = Σ project_costs of the pair in the budget currency (lines in that currency at face
 * value, others through their base amounts and the budget currency's bulletin rate on or before the posting date).
 *
 * Levels: `warning` from `finance.budget_overrun_threshold_pct` of the budget, `exceeded` above 100%. A level rise
 * notifies `finance.vouchers` holders reaching the project's branch and the project manager once (after commit); a
 * fall (reversal, larger budget) is stored silently. Never blocks the posting.
 */
class BudgetMonitor
{
    public const LEVELS = ['none' => 0, 'warning' => 1, 'exceeded' => 2];

    public const COLOURS = ['none' => 'green', 'warning' => 'amber', 'exceeded' => 'red'];

    public function __construct(private SystemAccounts $roles, private ProjectLedger $ledger, private SettingService $settings,
        private Access $access, private FinanceScope $scope, private ProjectService $projects) {}

    /**
     * Re-measures the budget lines the entry touched; returns the warnings to show on the voucher.
     *
     * @return list<string>
     */
    public function afterPosting(JournalEntry $entry): array
    {
        $accountId = $this->roles->mappedId('project_costs');
        if ($accountId === null) {
            return [];
        }
        $pairs = JournalLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $accountId)
            ->whereNotNull('project_id')->whereNotNull('project_cost_category_id')
            ->get(['project_id', 'project_cost_category_id', 'debit', 'credit']);
        if ($pairs->isEmpty()) {
            return [];
        }
        $raised = $pairs->filter(fn (JournalLine $l) => Money::isPositive((string) $l->debit))
            ->map(fn (JournalLine $l) => $l->project_id.'|'.$l->project_cost_category_id)->unique()->all();
        $budgets = ProjectBudgetLine::query()
            ->where(function ($q) use ($pairs) {
                foreach ($pairs->unique(fn ($l) => $l->project_id.'|'.$l->project_cost_category_id) as $pair) {
                    $q->orWhere(fn ($q) => $q->where('project_id', $pair->project_id)->where('project_cost_category_id', $pair->project_cost_category_id));
                }
            })
            ->orderBy('id')->lockForUpdate()->get();
        $date = $entry->entry_date->toDateString();
        $warnings = [];
        foreach ($budgets as $budget) {
            $measure = $this->measure($budget, $date);
            if ($measure === null) {
                continue;
            }
            $old = $budget->alert_level ?? 'none';
            $new = $measure['level'];
            if ($new !== $old) {
                $budget->forceFill(['alert_level' => $new])->save();
            }
            if ($new === 'none' || ! in_array($budget->project_id.'|'.$budget->project_cost_category_id, $raised, true)) {
                continue;
            }
            $message = $this->message($budget, $measure);
            $warnings[] = $message;
            if (self::LEVELS[$new] > self::LEVELS[$old]) {
                $this->notify($budget, $new, $message, $measure);
            }
        }

        return $warnings;
    }

    /**
     * Actual against budget for one line on `$date` (null when the budget currency has no bulletin rate yet).
     *
     * @return array{actual: string, pct: string|null, level: string, colour: string}|null
     */
    public function measure(ProjectBudgetLine $budget, ?string $date = null): ?array
    {
        $date ??= now()->toDateString();
        $currency = Currency::query()->findOrFail($budget->currency_id);
        $lines = $this->ledger->lines('project_costs', $budget->project_id)->where('project_cost_category_id', $budget->project_cost_category_id);
        try {
            $actual = Money::round($this->ledger->net($lines, $currency, $date), (int) $currency->decimals);
        } catch (ValidationException) {
            return null;
        }
        $amount = Money::of((string) $budget->budget_amount);
        $pct = Money::isPositive($amount) ? Money::div(Money::mul($actual, '100', 8), $amount, 2) : null;
        $threshold = Money::of((string) $this->settings->get('finance.budget_overrun_threshold_pct', '90'));
        $level = match (true) {
            Money::cmp($actual, $amount) > 0 => 'exceeded',
            $pct !== null && Money::cmp($pct, $threshold) >= 0 => 'warning',
            default => 'none',
        };

        return ['actual' => $actual, 'pct' => $pct, 'level' => $level, 'colour' => self::COLOURS[$level]];
    }

    /** @param  array{actual: string, pct: string|null, level: string}  $measure */
    private function message(ProjectBudgetLine $budget, array $measure): string
    {
        $project = $this->projects->find($budget->project_id);
        $category = ProjectCostCategory::query()->whereKey($budget->project_cost_category_id)->value('name');
        $code = Currency::query()->whereKey($budget->currency_id)->value('code');
        $figures = Money::format($measure['actual']).' من '.Money::format((string) $budget->budget_amount).' '.$code;
        $pct = $measure['pct'] === null ? '' : ' ('.$measure['pct'].'%)';

        return $measure['level'] === 'exceeded'
            ? "تجاوزت تكاليف \"{$category}\" في المشروع {$project?->name} الموازنة: {$figures}{$pct}."
            : "اقتربت تكاليف \"{$category}\" في المشروع {$project?->name} من الموازنة: {$figures}{$pct}.";
    }

    /** @param  array{actual: string, pct: string|null, level: string}  $measure */
    private function notify(ProjectBudgetLine $budget, string $level, string $message, array $measure): void
    {
        $project = $this->projects->find($budget->project_id);
        $users = $this->access->usersWith('finance.vouchers', Scope::OWN)
            ->filter(fn (User $u) => $this->scope->allows($u, 'finance.vouchers', $project?->branch_id));
        if ($project?->manager_user_id !== null && ! $users->contains('id', $project->manager_user_id)) {
            $users = $users->merge(User::query()->whereKey($project->manager_user_id)->where('status', 'active')->get());
        }
        if ($users->isEmpty()) {
            return;
        }
        $notification = new SystemNotification('finance.budget_'.$level, $message, ['level' => $level, 'project_id' => $budget->project_id,
            'budget_line_id' => $budget->id, 'project_cost_category_id' => $budget->project_cost_category_id, 'actual' => $measure['actual'],
            'budget' => Money::of((string) $budget->budget_amount), 'pct' => $measure['pct']]);
        DB::afterCommit(fn () => Notification::send($users->values(), $notification));
    }
}
