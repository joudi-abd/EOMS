<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\HR\Events\EmployeeTerminating;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\BoardMember;
use App\Modules\Tasks\Services\ActivityLog;
use App\Modules\Tasks\Services\BoardAccess;
use App\Modules\Tasks\Services\BoardService;
use App\Modules\Tasks\Services\TaskNotifier;
use Illuminate\Support\Facades\DB;

/**
 * When service ends (inside the termination transaction, so it rolls back with it): the person leaves every board
 * and every open card; each board's owners hear how many cards lost their assignee; boards they owned alone pass
 * to their department manager; their personal boards are archived.
 */
class ReleaseTasksOnTermination
{
    public function __construct(private BoardService $boards, private BoardAccess $access, private ActivityLog $activity, private TaskNotifier $notifier) {}

    public function handle(EmployeeTerminating $event): void
    {
        $userId = $event->employee->user_id;
        if (! $userId) {
            return;
        }
        $managerUser = $this->managerUser($event->employee);

        Board::where('type', 'personal')->where('created_by', $userId)->whereNull('archived_at')
            ->each(fn (Board $board) => $board->update(['archived_at' => now()]));

        $boardIds = BoardMember::where('user_id', $userId)->pluck('board_id')
            ->merge(Board::where('type', 'department')->where('department_id', $event->employee->department_id)->pluck('id'))
            ->merge(DB::table('tsk_card_assignees')->join('tsk_cards', 'tsk_cards.id', '=', 'tsk_card_assignees.card_id')
                ->where('tsk_card_assignees.user_id', $userId)->pluck('tsk_cards.board_id'))
            ->unique();

        foreach (Board::whereIn('id', $boardIds)->where('type', '!=', 'personal')->get() as $board) {
            $member = BoardMember::where('board_id', $board->id)->where('user_id', $userId)->first();
            if ($member?->role === 'owner' && array_diff($this->access->owners($board), [$userId]) === [] && $managerUser && $managerUser !== $userId) {
                BoardMember::updateOrCreate(['board_id' => $board->id, 'user_id' => $managerUser], ['role' => 'owner', 'added_by' => null]);
                $this->notifier->toUsers([$managerUser], 'tasks.board_handed_over', 'انتقلت إليك ملكية لوحة المهام: '.$board->name, ['board_id' => $board->id]);
            }
            $member?->delete();
            $cards = $this->boards->unassignFromOpenCards($board, $userId);
            $this->activity->record($board, null, null, 'member.left_company', ['user_id' => $userId, 'cards' => $cards]);
            if ($cards !== []) {
                $this->notifier->toUsers(array_diff($this->access->owners($board), [$userId]), 'tasks.cards_unassigned',
                    'انتهت خدمة '.$event->employee->full_name.'؛ '.count($cards).' بطاقة في "'.$board->name.'" بلا مكلّف الآن', ['board_id' => $board->id]);
            }
        }
    }

    private function managerUser(Employee $employee): ?int
    {
        $managerEmployee = $employee->department_id ? Department::whereKey($employee->department_id)->value('manager_employee_id') : null;
        if ($managerEmployee && $managerEmployee !== $employee->id) {
            return Employee::whereKey($managerEmployee)->value('user_id');
        }

        return null;
    }
}
