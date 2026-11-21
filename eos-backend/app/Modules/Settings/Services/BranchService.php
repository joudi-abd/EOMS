<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Branch;
use App\Support\AuditableAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Company branches (OP-10). At most one head office; marking another one moves the flag. */
class BranchService
{
    /** @return Collection<int, Branch> */
    public function list(bool $activeOnly = false): Collection
    {
        return Branch::query()->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_head_office')->orderBy('name')->get();
    }

    public function find(int $id): ?Branch
    {
        return Branch::query()->find($id);
    }

    public function headOffice(): ?Branch
    {
        return Branch::query()->where('is_head_office', true)->where('is_active', true)->first();
    }

    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $data['code'] = strtoupper(trim($data['code']));
            if ($data['is_head_office'] ?? false) {
                Branch::query()->where('is_head_office', true)->lockForUpdate()->update(['is_head_office' => false]);
            }
            $branch = Branch::create($data);
            event(new AuditableAction('settings.branch_created', $branch, null, $branch->getAttributes()));

            return $branch->refresh();
        }, 3);
    }

    public function update(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            if (isset($data['code'])) {
                $data['code'] = strtoupper(trim($data['code']));
            }
            $headOffice = $data['is_head_office'] ?? $branch->is_head_office;
            if ($headOffice && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => ['لا تُعطَّل الشركة الرئيسية؛ عيّن شركةً رئيسيةً أخرى أولاً.']]);
            }
            if (($data['is_head_office'] ?? false) && ! $branch->is_head_office) {
                Branch::query()->where('is_head_office', true)->where('id', '!=', $branch->id)->lockForUpdate()->update(['is_head_office' => false]);
            }
            $old = $branch->getAttributes();
            $branch->update($data);
            event(new AuditableAction('settings.branch_updated', $branch, $old, $branch->getAttributes()));

            return $branch->refresh();
        }, 3);
    }
}
