<?php

namespace App\Modules\Tasks\Services;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\BoardMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and do what on a board. Roles rank owner > member > viewer.
 * - personal: its creator only (owner); nobody else, not even the company manager.
 * - explicit membership rows give their role.
 * - department boards: the department's current manager is an owner and its active employees are members,
 *   without rows, so joining or leaving the department needs no bookkeeping.
 * - read-only: the company manager / system admin on every non-personal board, and a department manager on
 *   non-personal boards that include an active employee of a department they manage.
 */
class BoardAccess
{
    public const RANK = ['viewer' => 1, 'member' => 2, 'owner' => 3];

    /** @var array<string, array{employee: ?int, department: ?int, manages: list<int>, top: bool}> */
    private array $context = [];

    /** @return array{employee: ?int, department: ?int, manages: list<int>, top: bool} */
    public function context(User $user): array
    {
        // Memoised per HTTP request (controllers can be reused between requests, e.g. in tests or Octane).
        $key = $user->id.'@'.(app()->bound('request') ? spl_object_id(app('request')) : 0);

        return $this->context[$key] ??= (function () use ($user) {
            $employee = Employee::query()->where('user_id', $user->id)->where('status', '!=', 'terminated')->first(['id', 'department_id']);

            return [
                'employee' => $employee?->id,
                'department' => $employee?->department_id,
                'manages' => $employee ? Department::query()->where('manager_employee_id', $employee->id)->pluck('id')->all() : [],
                'top' => $user->hasRole(['company_manager', 'system_admin']),
            ];
        })();
    }

    public function forget(): void
    {
        $this->context = [];
    }

    /** The user's effective role on the board, or null when they may not see it. */
    public function role(User $user, Board $board): ?string
    {
        if ($board->type === 'personal') {
            return $board->created_by === $user->id ? 'owner' : null;
        }
        $ctx = $this->context($user);
        $roles = [];
        $explicit = BoardMember::query()->where('board_id', $board->id)->where('user_id', $user->id)->value('role');
        if ($explicit) {
            $roles[] = $explicit;
        }
        if ($board->type === 'department' && $board->department_id !== null) {
            if (in_array($board->department_id, $ctx['manages'], true)) {
                $roles[] = 'owner';
            } elseif ($ctx['department'] === $board->department_id) {
                $roles[] = 'member';
            }
        }
        if ($roles === [] && ($ctx['top'] || $this->managesAMember($ctx['manages'], $board))) {
            $roles[] = 'viewer';
        }

        return collect($roles)->sortByDesc(fn ($role) => self::RANK[$role])->first();
    }

    public function can(User $user, Board $board, string $atLeast): bool
    {
        $role = $this->role($user, $board);

        return $role !== null && self::RANK[$role] >= self::RANK[$atLeast];
    }

    /** Aborts 404 when the board is hidden from the user (no hint that it exists), 403 when the role is too low. */
    public function authorize(User $user, Board $board, string $atLeast = 'viewer'): string
    {
        $role = $this->role($user, $board);
        abort_if($role === null, 404);
        abort_unless(self::RANK[$role] >= self::RANK[$atLeast], 403, match ($atLeast) {
            'owner' => 'هذا الإجراء لمالك اللوحة.',
            default => 'صلاحيتك على هذه اللوحة للقراءة فقط.',
        });

        return $role;
    }

    /** Boards the user can see, for lists, "my cards" and reports. */
    public function visible(User $user): Builder
    {
        $ctx = $this->context($user);

        return Board::query()->where(function (Builder $q) use ($user, $ctx) {
            $q->where(fn ($p) => $p->where('type', 'personal')->where('created_by', $user->id))
                ->orWhere(function (Builder $shared) use ($user, $ctx) {
                    $shared->where('type', '!=', 'personal')->where(function (Builder $any) use ($user, $ctx) {
                        if ($ctx['top']) {
                            $any->whereRaw('1 = 1');

                            return;
                        }
                        $any->whereIn('id', BoardMember::query()->where('user_id', $user->id)->select('board_id'));
                        $departments = array_values(array_filter([...$ctx['manages'], $ctx['department']]));
                        if ($departments !== []) {
                            $any->orWhere(fn ($d) => $d->where('type', 'department')->whereIn('department_id', $departments));
                        }
                        if ($ctx['manages'] !== []) {
                            $any->orWhereIn('id', BoardMember::query()->whereIn('user_id', $this->activeUsersOf($ctx['manages']))->select('board_id'));
                        }
                    });
                });
        });
    }

    /** User ids of active employees in these departments. */
    public function activeUsersOf(array $departmentIds): Builder
    {
        return Employee::query()->whereIn('department_id', $departmentIds)->where('status', '!=', 'terminated')
            ->whereNotNull('user_id')->select('user_id');
    }

    /** Everyone who can read the board: explicit members plus a department board's employees and manager. */
    public function readers(Board $board): array
    {
        if ($board->type === 'personal') {
            return [$board->created_by];
        }
        $ids = BoardMember::query()->where('board_id', $board->id)->pluck('user_id')->all();
        if ($board->type === 'department' && $board->department_id !== null) {
            $ids = [...$ids, ...$this->activeUsersOf([$board->department_id])->pluck('user_id')->all()];
            $manager = Department::query()->whereKey($board->department_id)->value('manager_employee_id');
            if ($manager) {
                $ids[] = Employee::query()->whereKey($manager)->value('user_id');
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /** Users who can work on the board (owner or member), e.g. who may be assigned a card. */
    public function workers(Board $board): array
    {
        if ($board->type === 'personal') {
            return [$board->created_by];
        }
        $viewers = BoardMember::query()->where('board_id', $board->id)->where('role', 'viewer')->pluck('user_id')->all();

        return array_values(array_diff($this->readers($board), array_map('intval', $viewers)));
    }

    /** Owners: explicit owners plus a department board's current manager. */
    public function owners(Board $board): array
    {
        if ($board->type === 'personal') {
            return [$board->created_by];
        }
        $ids = BoardMember::query()->where('board_id', $board->id)->where('role', 'owner')->pluck('user_id')->all();
        if ($board->type === 'department' && $board->department_id !== null) {
            $manager = Department::query()->whereKey($board->department_id)->value('manager_employee_id');
            if ($manager) {
                $ids[] = Employee::query()->whereKey($manager)->value('user_id');
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /** Who may create a board of this type (and, for department boards, for which department). */
    public function mayCreate(User $user, string $type, ?int $departmentId): bool
    {
        $ctx = $this->context($user);

        return match ($type) {
            'personal' => $user->can('tasks.view'),
            'department' => $departmentId !== null && ($ctx['top'] || in_array($departmentId, $ctx['manages'], true)),
            'team' => $user->can('tasks.manage'),
            default => false,
        };
    }

    private function managesAMember(array $managed, Board $board): bool
    {
        if ($managed === []) {
            return false;
        }
        if ($board->type === 'department' && in_array($board->department_id, $managed, true)) {
            return true;
        }

        return BoardMember::query()->where('board_id', $board->id)->whereIn('user_id', $this->activeUsersOf($managed))->exists();
    }
}
