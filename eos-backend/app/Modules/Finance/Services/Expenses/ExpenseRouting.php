<?php

namespace App\Modules\Finance\Services\Expenses;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Validation\ValidationException;

/**
 * Where an expense line is debited (spec #8, design §6): with a project → `project_costs` (project, cost category; when
 * an asset is set and no cost category is given, the `asset_operation` system category); without a project → the
 * expense category's own account. The expense category, the asset and the party ride along as dimensions.
 * Used by the expense voucher, the internal-expense payment, the partner drawing classified as company expense
 * (#12) and available to custody settlements (#20).
 */
class ExpenseRouting
{
    public function __construct(private SystemAccounts $roles, private ProjectService $projects) {}

    /**
     * Checks one expense line and returns the debit line.
     *
     * @param  array{expense_category_id?: int|null, project_id?: int|null, project_cost_category_id?: int|null, asset_id?: int|null, party_id?: int|null}  $line
     * @param  string  $field  error key prefix, e.g. "lines.0." or ""
     */
    public function debit(array $line, int $currencyId, string $amount, ?string $memo, string $field = ''): LineDraft
    {
        $categoryId = $line['expense_category_id'] ?? null;
        $projectId = $line['project_id'] ?? null;
        $costCategoryId = $line['project_cost_category_id'] ?? null;
        $assetId = $line['asset_id'] ?? null;
        $category = $categoryId === null ? null : $this->category((int) $categoryId, $field.'expense_category_id', $projectId === null);
        if ($assetId !== null && ! Asset::query()->whereKey($assetId)->exists()) {
            $this->fail($field.'asset_id', 'الأصل غير موجود.');
        }
        $dims = ['expense_category_id' => $categoryId, 'asset_id' => $assetId, 'party_id' => $line['party_id'] ?? null];

        if ($projectId === null) {
            if ($category === null) {
                $this->fail($field.'expense_category_id', 'اختر تصنيف المصروف.');
            }
            if ($costCategoryId !== null) {
                $this->fail($field.'project_cost_category_id', 'تصنيف تكلفة المشروع يُستخدم مع المشروع فقط.');
            }

            return LineDraft::debit((int) $category->account_id, $currencyId, $amount, $dims, $memo);
        }

        if ($this->projects->find((int) $projectId) === null) {
            $this->fail($field.'project_id', 'المشروع غير موجود.');
        }
        if ($costCategoryId === null && $assetId !== null) {
            $costCategoryId = ProjectCostCategory::query()->where('system_key', 'asset_operation')->value('id');
        }
        $cost = $costCategoryId === null ? null : ProjectCostCategory::query()->find($costCategoryId);
        if ($cost === null || ! $cost->is_active) {
            $this->fail($field.'project_cost_category_id', 'حدّد تصنيف تكلفة المشروع.');
        }

        return LineDraft::debit($this->roles->id('project_costs'), $currencyId, $amount,
            [...$dims, 'project_id' => $projectId, 'project_cost_category_id' => $cost->id], $memo);
    }

    /**
     * An active leaf expense category with an active, postable expense account (D21).
     * `$needsAccount` false: a category used only as a dimension on a project line still has to be an active leaf.
     */
    public function category(int $categoryId, string $field = 'expense_category_id', bool $needsAccount = true): ExpenseCategory
    {
        $category = ExpenseCategory::query()->find($categoryId);
        if ($category === null || ! $category->is_active) {
            $this->fail($field, 'تصنيف المصروف غير موجود أو غير فعّال.');
        }
        if (ExpenseCategory::query()->where('parent_id', $category->id)->exists()) {
            $this->fail($field, 'اختر تصنيفاً فرعياً، لا تصنيفاً رئيسياً.');
        }
        if ($needsAccount) {
            $account = $category->account_id === null ? null : Account::query()->find($category->account_id);
            if ($account === null || ! $account->is_active || ! $account->is_postable) {
                $this->fail($field, "اربط التصنيف {$category->name} بحساب مصروف فعّال من إعدادات تصنيفات المصاريف.");
            }
        }

        return $category;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
