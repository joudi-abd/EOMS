<?php

namespace Tests\Feature\Tasks\Concerns;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\TaskList;
use Laravel\Sanctum\Sanctum;

/**
 * Engineering department: Mona manages it, Ali and Sara work in it. Omar is in Finance. Hala is HR (tasks.manage),
 * Karim is the company manager.
 */
trait TasksFixture
{
    protected User $mona;

    protected User $ali;

    protected User $sara;

    protected User $omar;

    protected User $hala;

    protected User $karim;

    protected Department $engineering;

    protected Department $finance;

    protected array $employees = [];

    protected function setUpTasks(): void
    {
        $this->seed(RolePermissionSeeder::class);
        // Sunday 8 Nov 2026, 08:00 office time (Asia/Damascus default policy, Friday off).
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(8, 0, 0));
        $this->engineering = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $this->finance = Department::create(['name' => 'Finance', 'code' => 'FIN']);
        $this->mona = $this->person('Mona', $this->engineering, ['employee', 'department_manager']);
        $this->engineering->update(['manager_employee_id' => $this->employees[$this->mona->id]->id]);
        $this->ali = $this->person('Ali', $this->engineering);
        $this->sara = $this->person('Sara', $this->engineering);
        $this->omar = $this->person('Omar', $this->finance);
        $this->hala = $this->person('Hala', null, ['employee', 'hr_manager']);
        $this->karim = $this->person('Karim', null, ['employee', 'company_manager']);
    }

    protected function person(string $name, ?Department $department, array $roles = ['employee']): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($roles);
        $employee = Employee::create(['employee_no' => 'T-'.$name, 'user_id' => $user->id, 'first_name' => $name, 'last_name' => 'Test',
            'hire_date' => '2025-01-01', 'national_id' => 'T-'.$name, 'national_id_hash' => hash('sha256', 'T-'.$name),
            'department_id' => $department?->id]);
        $employee->servicePeriods()->create(['start_date' => '2025-01-01']);
        $this->employees[$user->id] = $employee;

        return $user;
    }

    protected function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** Creates a board as $user and returns the snapshot. */
    protected function board(User $user, string $type, array $extra = []): array
    {
        return $this->as($user)->postJson('/api/v1/tasks/boards', ['type' => $type, 'name' => ucfirst($type).' board', ...$extra])
            ->assertCreated()->json('data');
    }

    protected function listOf(array $snapshot, string $kind, int $nth = 0): int
    {
        return collect($snapshot['lists'])->where('kind', $kind)->values()[$nth]['id'];
    }

    protected function card(User $user, array $snapshot, array $extra = []): array
    {
        return $this->as($user)->postJson('/api/v1/tasks/boards/'.$snapshot['board']['id'].'/cards',
            ['list_id' => $this->listOf($snapshot, 'normal'), 'title' => 'Draw the facade', ...$extra])->assertCreated()->json('data');
    }

    protected function move(User $user, array $card, int $listId, ?int $after = null)
    {
        return $this->as($user)->postJson('/api/v1/tasks/cards/'.$card['id'].'/move', ['list_id' => $listId, 'after_card_id' => $after]);
    }

    protected function kindOf(int $cardId): string
    {
        return TaskList::findOrFail(Card::findOrFail($cardId)->list_id)->kind;
    }

    protected function notified(User $user, string $kind): int
    {
        return $user->notifications()->get()->where('data.kind', $kind)->count();
    }
}
