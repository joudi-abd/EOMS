<?php

namespace App\Modules\Finance\Services\Assets;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\AssetCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The asset category tree (spec §5.8 asset_categories), managed with `finance.settings`. */
class AssetCategoryService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit) {}

    /** @return list<array<string, mixed>> flat, parents first, with children and asset counts */
    public function list(): array
    {
        return AssetCategory::query()->withCount(['children', 'assets'])->orderByRaw('parent_id IS NOT NULL')->orderBy('parent_id')->orderBy('name')
            ->get()->toArray();
    }

    public function create(array $data, User $actor): AssetCategory
    {
        return DB::transaction(function () use ($data, $actor) {
            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            $this->assertUniqueName($data['name'], $parentId);
            $category = AssetCategory::create(['name' => $data['name'], 'parent_id' => $parentId, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->audit->log('finance.asset_category_created', $category, null, $category->getAttributes(), $actor->id);

            return $category;
        }, self::TX_ATTEMPTS);
    }

    public function update(AssetCategory $category, array $data, User $actor): AssetCategory
    {
        return DB::transaction(function () use ($category, $data, $actor) {
            $category = AssetCategory::query()->lockForUpdate()->findOrFail($category->id);
            $parentId = array_key_exists('parent_id', $data) ? ($data['parent_id'] === null ? null : (int) $data['parent_id']) : $category->parent_id;
            if ($parentId !== null && in_array($parentId, $this->subtree($category->id), true)) {
                throw ValidationException::withMessages(['parent_id' => ['لا يكون التصنيف أباً لنفسه أو لأحد فروعه.']]);
            }
            $name = $data['name'] ?? $category->name;
            $this->assertUniqueName($name, $parentId, $category->id);
            $old = $category->only(['name', 'parent_id']);
            $category->update(['name' => $name, 'parent_id' => $parentId, 'updated_by' => $actor->id]);
            $this->audit->log('finance.asset_category_updated', $category, $old, $category->only(['name', 'parent_id']), $actor->id);

            return $category->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function delete(AssetCategory $category, User $actor): void
    {
        DB::transaction(function () use ($category, $actor) {
            $category = AssetCategory::query()->lockForUpdate()->findOrFail($category->id);
            abort_if($category->children()->exists() || Asset::query()->where('category_id', $category->id)->exists(), 409,
                'لا يُحذف تصنيف له فروع أو أصول.');
            $this->audit->log('finance.asset_category_deleted', $category, $category->getAttributes(), null, $actor->id);
            $category->delete();
        }, self::TX_ATTEMPTS);
    }

    /** @return list<int> the category and every descendant */
    public function subtree(int $categoryId): array
    {
        $ids = [$categoryId];
        $frontier = [$categoryId];
        while ($frontier !== []) {
            $frontier = AssetCategory::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->diff($ids)->values()->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    private function assertUniqueName(string $name, ?int $parentId, ?int $exceptId = null): void
    {
        $exists = AssetCategory::query()->where('name', $name)->where('parent_id', $parentId)
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => ['يوجد تصنيف بهذا الاسم في المستوى نفسه.']]);
        }
    }
}
