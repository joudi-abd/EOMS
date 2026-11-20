<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Project cost categories (§5.6): how project costs are organised; `is_billable` decides what the client is billed
 * (OP-2: loaded payroll and allocated overhead are not). Seeded with system keys; deactivated, never deleted.
 */
class ProjectCostCategoryService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit) {}

    public function list(?bool $active = null): Collection
    {
        return ProjectCostCategory::query()->when($active !== null, fn ($q) => $q->where('is_active', $active))
            ->orderBy('sort')->orderBy('id')->get();
    }

    public function create(array $data, User $actor): ProjectCostCategory
    {
        return DB::transaction(function () use ($data, $actor) {
            $category = ProjectCostCategory::create([
                'name' => $data['name'], 'is_billable' => (bool) ($data['is_billable'] ?? true), 'is_active' => (bool) ($data['is_active'] ?? true),
                'sort' => (int) ($data['sort'] ?? ((int) ProjectCostCategory::query()->max('sort') + 1)),
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->audit->log('finance.project_cost_category_created', $category, null, $category->getAttributes(), $actor->id);

            return $category->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function update(ProjectCostCategory $category, array $data, User $actor): ProjectCostCategory
    {
        return DB::transaction(function () use ($category, $data, $actor) {
            $category = ProjectCostCategory::query()->lockForUpdate()->findOrFail($category->id);
            $old = $category->getAttributes();
            $category->update([...array_intersect_key($data, array_flip(['name', 'is_billable', 'is_active', 'sort'])), 'updated_by' => $actor->id]);
            $this->audit->log('finance.project_cost_category_updated', $category, $old, $category->getAttributes(), $actor->id);

            return $category->refresh();
        }, self::TX_ATTEMPTS);
    }
}
