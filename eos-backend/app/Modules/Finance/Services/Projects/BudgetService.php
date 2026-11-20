<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\ProjectBudgetLine;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Settings\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project budget lines (D10): one amount per (project, cost category, currency). Budget against actual is measured by
 * BudgetMonitor; editing a budget re-measures its alert level silently (notifications only follow postings, R13).
 */
class BudgetService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private ProjectAccess $access, private BudgetMonitor $monitor, private AuditLogger $audit) {}

    /**
     * Budget against actual for a project (§9.4 tab 2, T10 data): per line the actual, the percentage and the colour.
     *
     * @return list<array<string, mixed>>
     */
    public function vsActual(int $projectId, User $user, ?string $date = null): array
    {
        $this->access->readable($user, $projectId);
        $codes = Currency::query()->pluck('code', 'id');

        return ProjectBudgetLine::query()->with('category:id,name,system_key,is_billable')->where('project_id', $projectId)
            ->get()->sortBy(fn (ProjectBudgetLine $l) => [$l->category?->id, $l->currency_id])->values()
            ->map(function (ProjectBudgetLine $line) use ($codes, $date) {
                $measure = $this->monitor->measure($line, $date);

                return [...$line->toArray(), 'currency' => $codes[$line->currency_id] ?? null, 'actual' => $measure['actual'] ?? null,
                    'remaining' => $measure === null ? null : Money::sub(Money::of((string) $line->budget_amount), $measure['actual']),
                    'pct' => $measure['pct'] ?? null, 'level' => $measure['level'] ?? null, 'colour' => $measure['colour'] ?? null];
            })->all();
    }

    public function create(int $projectId, array $data, User $actor): ProjectBudgetLine
    {
        return DB::transaction(function () use ($projectId, $data, $actor) {
            $project = $this->access->readable($actor, $projectId);
            $this->access->assertManage($actor, $project);
            $category = ProjectCostCategory::query()->find($data['project_cost_category_id'] ?? null);
            if ($category === null || ! $category->is_active) {
                throw ValidationException::withMessages(['project_cost_category_id' => ['اختر تصنيف تكلفة فعّالاً.']]);
            }
            $currency = Currency::query()->find($data['currency_id'] ?? null);
            if ($currency === null || ! $currency->is_active) {
                throw ValidationException::withMessages(['currency_id' => ['اختر عملة فعّالة.']]);
            }
            $exists = ProjectBudgetLine::query()->where(['project_id' => $projectId, 'project_cost_category_id' => $category->id,
                'currency_id' => $currency->id])->exists();
            if ($exists) {
                throw ValidationException::withMessages(['project_cost_category_id' => ['يوجد بند موازنة لهذا التصنيف بهذه العملة؛ عدّله بدل إضافة بند جديد.']]);
            }
            $line = ProjectBudgetLine::create(['project_id' => $projectId, 'project_cost_category_id' => $category->id, 'currency_id' => $currency->id,
                'budget_amount' => $this->amount($data['budget_amount'] ?? null, $currency), 'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->remeasure($line);
            $this->audit->log('finance.budget_line_created', $line, null, $line->getAttributes(), $actor->id);

            return $line->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function update(ProjectBudgetLine $line, array $data, User $actor): ProjectBudgetLine
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            $line = ProjectBudgetLine::query()->lockForUpdate()->findOrFail($line->id);
            $this->access->assertManage($actor, $this->access->readable($actor, $line->project_id));
            $old = $line->getAttributes();
            $currency = Currency::query()->findOrFail($line->currency_id);
            $line->update([
                'budget_amount' => array_key_exists('budget_amount', $data) ? $this->amount($data['budget_amount'], $currency) : $line->budget_amount,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $line->notes, 'updated_by' => $actor->id]);
            $this->remeasure($line);
            $this->audit->log('finance.budget_line_updated', $line, $old, $line->getAttributes(), $actor->id);

            return $line->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function delete(ProjectBudgetLine $line, User $actor): void
    {
        DB::transaction(function () use ($line, $actor) {
            $line = ProjectBudgetLine::query()->lockForUpdate()->findOrFail($line->id);
            $this->access->assertManage($actor, $this->access->readable($actor, $line->project_id));
            $this->audit->log('finance.budget_line_deleted', $line, $line->getAttributes(), null, $actor->id);
            $line->delete();
        }, self::TX_ATTEMPTS);
    }

    private function remeasure(ProjectBudgetLine $line): void
    {
        $measure = $this->monitor->measure($line);
        if ($measure !== null && $measure['level'] !== $line->alert_level) {
            $line->forceFill(['alert_level' => $measure['level']])->save();
        }
    }

    private function amount(mixed $value, Currency $currency): string
    {
        $amount = Money::round(Money::of((string) ($value ?? '0')), (int) $currency->decimals);
        if (Money::isNegative($amount)) {
            throw ValidationException::withMessages(['budget_amount' => ['مبلغ الموازنة لا يكون سالباً.']]);
        }

        return $amount;
    }
}
