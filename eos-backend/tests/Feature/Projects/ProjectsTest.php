<?php

namespace Tests\Feature\Projects;

use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Settings\Database\Seeders\BranchSeeder;
use App\Modules\Settings\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The general project registry and who sees which projects (§14.11: a project manager sees only their projects). */
class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $hq;

    private Branch $idlib;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, BranchSeeder::class]);
        $this->hq = Branch::where('code', 'HQ')->firstOrFail();
        $this->idlib = Branch::create(['name' => 'فرع إدلب', 'code' => 'IDL']);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_finance_creates_projects_and_each_role_sees_its_own_reach(): void
    {
        $finance = $this->actor('finance_manager');
        $manager = $this->actor('project_manager');
        $supervisor = $this->actor('site_supervisor');
        Sanctum::actingAs($finance);
        $mine = $this->postJson('/api/v1/projects', ['name' => 'شقق القاسم', 'status' => 'active', 'branch_id' => $this->hq->id,
            'manager_user_id' => $manager->id, 'members' => [['user_id' => $supervisor->id, 'role' => 'supervisor']]])
            ->assertCreated()->assertJsonPath('data.members.0.role', 'supervisor')->json('data');
        $other = $this->postJson('/api/v1/projects', ['name' => 'مكاتب الشركة', 'branch_id' => $this->idlib->id])->assertCreated()->json('data');
        $this->assertSame(2, $this->getJson('/api/v1/projects')->assertOk()->json('meta.total'));
        $this->postJson('/api/v1/projects/'.$other['id'].'/status', ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');

        Sanctum::actingAs($manager);
        $this->assertSame([$mine['id']], array_column($this->getJson('/api/v1/projects')->assertOk()->json('data'), 'id'));
        $this->getJson('/api/v1/projects/'.$other['id'])->assertNotFound();
        $this->patchJson('/api/v1/projects/'.$mine['id'], ['name' => 'x'])->assertForbidden();

        Sanctum::actingAs($supervisor);
        $this->getJson('/api/v1/projects')->assertForbidden();

        // A branch accountant creates and sees the projects of the branches assigned to them only.
        $accountant = $this->actor('branch_accountant');
        Sanctum::actingAs($finance);
        $this->putJson('/api/v1/finance/users/'.$accountant->id.'/branches', ['branch_ids' => [$this->idlib->id]])->assertOk();
        Sanctum::actingAs($accountant);
        $this->assertSame([$other['id']], array_column($this->getJson('/api/v1/projects')->json('data'), 'id'));
        $this->postJson('/api/v1/projects', ['name' => 'خارج الفرع', 'branch_id' => $this->hq->id])->assertForbidden();
        $this->postJson('/api/v1/projects', ['name' => 'بلا فرع'])->assertForbidden();
        $this->postJson('/api/v1/projects', ['name' => 'مشروع إدلب', 'branch_id' => $this->idlib->id])->assertCreated();
        $this->putJson('/api/v1/projects/'.$mine['id'].'/members', ['members' => []])->assertForbidden();
        $this->assertSame(3, Project::count());
    }
}
