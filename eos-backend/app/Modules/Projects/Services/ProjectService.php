<?php

namespace App\Modules\Projects\Services;

use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\Scope;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Contracts\ProjectBranchScope;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Settings\Services\BranchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The project registry other modules link to. Reach: `finance.projects.view` at all scope (or `finance.projects`
 * at all) sees every project; otherwise an account sees the projects it manages, plus — when it holds
 * `finance.projects` at own scope — the projects of the branches assigned to it.
 */
class ProjectService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit, private Access $access, private ProjectBranchScope $branches,
        private BranchService $branchService) {}

    public function query(): Builder
    {
        return Project::query();
    }

    public function find(int $id): ?Project
    {
        return Project::query()->find($id);
    }

    /** @return list<int> */
    public function managedBy(int $userId): array
    {
        return Project::query()->where('manager_user_id', $userId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    public function supervisedBy(int $userId): array
    {
        return ProjectMember::query()->where('user_id', $userId)->where('role', 'supervisor')->orderBy('project_id')
            ->pluck('project_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Projects the account may read. */
    public function visibleTo(User $user): Builder
    {
        if ($this->access->covers($user, 'finance.projects.view', Scope::ALL) || $this->access->covers($user, 'finance.projects', Scope::ALL)) {
            return Project::query();
        }
        $branchIds = $this->access->scope($user, 'finance.projects') !== null ? ($this->branches->branchIdsFor($user, 'finance.projects') ?? []) : [];

        return Project::query()->where(fn (Builder $q) => $q->where('manager_user_id', $user->id)
            ->when($branchIds !== [], fn (Builder $q) => $q->orWhereIn('branch_id', $branchIds)));
    }

    public function create(array $data, User $actor): Project
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertBranch($actor, $data['branch_id'] ?? null);
            $project = Project::create([...$this->only($data), 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            if (isset($data['members'])) {
                $this->writeMembers($project, $data['members']);
            }
            $this->audit->log('projects.created', $project, null, $project->getAttributes(), $actor->id);

            return $project->refresh()->load('members');
        }, self::TX_ATTEMPTS);
    }

    public function update(Project $project, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($project, $data, $actor) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $this->assertBranch($actor, $project->branch_id);
            if (array_key_exists('branch_id', $data)) {
                $this->assertBranch($actor, $data['branch_id']);
            }
            $old = $project->getAttributes();
            $project->update([...$this->only($data), 'updated_by' => $actor->id]);
            $this->audit->log('projects.updated', $project, $old, $project->getAttributes(), $actor->id);

            return $project->refresh()->load('members');
        }, self::TX_ATTEMPTS);
    }

    public function setStatus(Project $project, string $status, User $actor): Project
    {
        abort_unless(in_array($status, Project::STATUSES, true), 422, 'حالة المشروع غير معروفة.');

        return $this->update($project, ['status' => $status], $actor);
    }

    /** @param  list<array{user_id: int, role: string}>  $members */
    public function syncMembers(Project $project, array $members, User $actor): Project
    {
        return DB::transaction(function () use ($project, $members, $actor) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $this->assertBranch($actor, $project->branch_id);
            $old = ProjectMember::query()->where('project_id', $project->id)->orderBy('user_id')->get(['user_id', 'role'])->toArray();
            $this->writeMembers($project, $members);
            $this->audit->log('projects.members_synced', $project, ['members' => $old], ['members' => $members], $actor->id);

            return $project->refresh()->load('members');
        }, self::TX_ATTEMPTS);
    }

    /** An account holding `finance.projects` only at own scope works inside its branches. */
    private function assertBranch(User $actor, ?int $branchId): void
    {
        if ($branchId !== null && $this->branchService->find($branchId)?->is_active !== true) {
            throw ValidationException::withMessages(['branch_id' => ['الفرع غير موجود أو غير فعّال.']]);
        }
        if ($this->access->covers($actor, 'finance.projects', Scope::ALL)) {
            return;
        }
        $allowed = $this->branches->branchIdsFor($actor, 'finance.projects');
        abort_if($allowed !== null && ($branchId === null || ! in_array($branchId, $allowed, true)), 403, 'لا تملك صلاحية على مشاريع هذا الفرع.');
    }

    /** @param  list<array{user_id: int, role: string}>  $members */
    private function writeMembers(Project $project, array $members): void
    {
        $rows = collect($members)->keyBy(fn ($m) => (int) $m['user_id']);
        ProjectMember::query()->where('project_id', $project->id)->whereNotIn('user_id', $rows->keys()->all())->delete();
        foreach ($rows as $userId => $member) {
            ProjectMember::query()->updateOrCreate(['project_id' => $project->id, 'user_id' => $userId], ['role' => $member['role']]);
        }
    }

    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip(['name', 'status', 'branch_id', 'manager_user_id', 'start_date', 'end_date', 'notes']));
    }
}
