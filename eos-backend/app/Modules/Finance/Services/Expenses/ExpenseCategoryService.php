<?php

namespace App\Modules\Finance\Services\Expenses;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Expense categories (D21, spec §5.5): a tree finance manages, tied to the chart. A leaf (a category without children)
 * is what vouchers use, so it needs an active, postable expense account; a parent only groups. A category already used
 * on a posting cannot get children (its postings would hide under a group). Categories are deactivated, not deleted.
 */
class ExpenseCategoryService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit) {}

    /**
     * The tree, children nested under `children`, each node with `is_leaf` and its account's code and name.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(?bool $active = null): array
    {
        $all = ExpenseCategory::query()->when($active !== null, fn ($q) => $q->where('is_active', $active))->orderBy('name')->get();
        $accounts = Account::query()->whereIn('id', $all->pluck('account_id')->filter()->unique())->get(['id', 'code', 'name'])->keyBy('id');
        $parents = ExpenseCategory::query()->whereNotNull('parent_id')->distinct()->pluck('parent_id')->map(fn ($id) => (int) $id)->flip();
        $ids = $all->pluck('id')->flip();
        // A node whose parent is filtered out is listed at the top.
        $byParent = $all->groupBy(fn (ExpenseCategory $c) => $c->parent_id !== null && $ids->has($c->parent_id) ? $c->parent_id : 0);
        $build = function (int $parentId) use (&$build, $byParent, $accounts, $parents): array {
            return $byParent->get($parentId, collect())->map(fn (ExpenseCategory $c) => [...$c->toArray(),
                'account_code' => $accounts->get($c->account_id)?->code, 'account_name' => $accounts->get($c->account_id)?->name,
                'is_leaf' => ! $parents->has($c->id), 'children' => $build($c->id)])->values()->all();
        };

        return $build(0);
    }

    public function create(array $data, User $actor): ExpenseCategory
    {
        return DB::transaction(function () use ($data, $actor) {
            $parentId = $data['parent_id'] ?? null;
            if ($parentId !== null) {
                $this->assertParent((int) $parentId, null);
            }
            // A new category is a leaf: it needs its expense account.
            $this->assertAccount($data['account_id'] ?? null, true);
            $category = ExpenseCategory::create(['parent_id' => $parentId, 'name' => $data['name'], 'account_id' => $data['account_id'] ?? null,
                'is_overhead_allocatable' => (bool) ($data['is_overhead_allocatable'] ?? false), 'is_active' => true,
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->audit->log('finance.expense_category_created', $category, null, $category->getAttributes(), $actor->id);

            return $category->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function update(ExpenseCategory $category, array $data, User $actor): ExpenseCategory
    {
        return DB::transaction(function () use ($category, $data, $actor) {
            $category = ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id);
            $old = $category->getAttributes();
            $changes = array_intersect_key($data, array_flip(['name', 'parent_id', 'account_id', 'is_overhead_allocatable', 'is_active']));
            if (array_key_exists('parent_id', $changes) && $changes['parent_id'] !== null && (int) $changes['parent_id'] !== $category->parent_id) {
                $this->assertParent((int) $changes['parent_id'], $category);
            }
            $isLeaf = ! ExpenseCategory::query()->where('parent_id', $category->id)->exists();
            $accountId = array_key_exists('account_id', $changes) ? $changes['account_id'] : $category->account_id;
            if ($isLeaf || $accountId !== null) {
                $this->assertAccount($accountId, $isLeaf);
            }
            if (($changes['is_active'] ?? true) === false) {
                $this->assertNoActiveChildren($category);
            }
            $category->update([...$changes, 'updated_by' => $actor->id]);
            $this->audit->log('finance.expense_category_updated', $category, $old, $category->getAttributes(), $actor->id);

            return $category->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function deactivate(ExpenseCategory $category, User $actor): ExpenseCategory
    {
        return $this->update($category, ['is_active' => false], $actor);
    }

    /** The parent exists, is active, is not the category or one of its descendants, and has no postings of its own. */
    private function assertParent(int $parentId, ?ExpenseCategory $category): void
    {
        $parent = ExpenseCategory::query()->lockForUpdate()->find($parentId);
        if ($parent === null || ! $parent->is_active) {
            throw ValidationException::withMessages(['parent_id' => ['التصنيف الأب غير موجود أو غير فعّال.']]);
        }
        for ($node = $parent; $category !== null && $node !== null; $node = $node->parent_id === null ? null : ExpenseCategory::query()->find($node->parent_id)) {
            if ($node->id === $category->id) {
                throw ValidationException::withMessages(['parent_id' => ['لا يكون التصنيف أباً لنفسه أو لأحد فروعه.']]);
            }
        }
        if (JournalLine::query()->where('expense_category_id', $parentId)->exists()
            || VoucherLine::query()->where('expense_category_id', $parentId)->exists()) {
            throw ValidationException::withMessages(['parent_id' => ["التصنيف {$parent->name} عليه حركات؛ لا تُضاف تحته تصنيفات فرعية."]]);
        }
    }

    /** A leaf needs an active, postable expense account that is not a box account. */
    private function assertAccount(mixed $accountId, bool $required): void
    {
        if ($accountId === null) {
            if ($required) {
                throw ValidationException::withMessages(['account_id' => ['اختر حساب المصروف للتصنيف.']]);
            }

            return;
        }
        $account = Account::query()->find($accountId);
        if ($account === null || ! $account->is_active || ! $account->is_postable || $account->type !== 'expense'
            || CashBox::query()->where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['account_id' => ['اختر حساب مصروفات فعّالاً يقبل القيود.']]);
        }
    }

    private function assertNoActiveChildren(ExpenseCategory $category): void
    {
        if (ExpenseCategory::query()->where('parent_id', $category->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['is_active' => ['عطّل التصنيفات الفرعية أولاً.']]);
        }
    }
}
