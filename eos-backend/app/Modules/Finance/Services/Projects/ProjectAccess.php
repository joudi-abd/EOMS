<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\Scope;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reaches which project's finance (§8, R6, OP-12). Reading: `finance.projects.view` at all (or `finance.view` /
 * `finance.projects` at all) sees every project; otherwise the projects the account manages (`finance.projects.view`
 * own) plus the projects of its assigned branches (`finance.view` / `finance.projects` own). Writing (contract,
 * budget, agreements, billing): `finance.projects`, own = the account's branches.
 */
class ProjectAccess
{
    public function __construct(private Access $access, private FinanceScope $scope, private ProjectService $projects) {}

    /** Projects (prj_projects) the account may read. */
    public function visibleProjects(User $user): Builder
    {
        foreach (['finance.projects.view', 'finance.view', 'finance.projects'] as $permission) {
            if ($this->access->covers($user, $permission, Scope::ALL)) {
                return $this->projects->query();
            }
        }
        $branches = [];
        foreach (['finance.view', 'finance.projects'] as $permission) {
            if ($this->access->scope($user, $permission) !== null) {
                $branches = [...$branches, ...($this->scope->branchIds($user, $permission) ?? [])];
            }
        }
        $branches = array_values(array_unique($branches));

        return $this->projects->query()->where(fn (Builder $q) => $q->where('manager_user_id', $user->id)
            ->when($branches !== [], fn (Builder $q) => $q->orWhereIn('branch_id', $branches)));
    }

    /** The project, when the account may read it (404 otherwise, so ids outside the reach are not disclosed). */
    public function readable(User $user, int $projectId): Project
    {
        $project = $this->visibleProjects($user)->find($projectId);
        abort_if($project === null, 404, 'المشروع غير موجود.');

        return $project;
    }

    /** The project's finance profile, when the account may read it. */
    public function readableProfile(User $user, int $projectId): ProjectProfile
    {
        $this->readable($user, $projectId);
        $profile = ProjectProfile::query()->where('project_id', $projectId)->first();
        abort_if($profile === null, 404, 'لا يوجد ملف مالي لهذا المشروع.');

        return $profile;
    }

    /** Writing on the project's finance needs `finance.projects` over its branch (403). */
    public function assertManage(User $user, Project $project): void
    {
        $this->scope->assertBranch($user, 'finance.projects', $project->branch_id);
    }
}
