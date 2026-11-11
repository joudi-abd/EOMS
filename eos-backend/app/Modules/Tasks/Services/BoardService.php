<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\BoardMember;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Label;
use App\Modules\Tasks\Models\TaskList;
use Illuminate\Support\Facades\DB;

/** Boards, their members, lists and labels. Cards live in CardService. */
class BoardService
{
    private const TX_ATTEMPTS = 3;

    public const STEP = 1024.0;

    public function __construct(
        private BoardAccess $access,
        private ActivityLog $activity,
        private TaskNotifier $notifier,
        private AuditLogger $audit,
    ) {}

    public function create(array $data, User $actor): Board
    {
        $type = $data['type'];
        $departmentId = $type === 'department' ? (int) $data['department_id'] : null;
        abort_unless($this->access->mayCreate($actor, $type, $departmentId), 403, match ($type) {
            'department' => 'لوحة القسم ينشئها مدير القسم.',
            'team' => 'إنشاء لوحات الفرق للمدراء.',
            default => 'لا يمكنك إنشاء هذه اللوحة.',
        });

        return DB::transaction(function () use ($data, $type, $departmentId, $actor) {
            $board = Board::create(['type' => $type, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'color' => $data['color'] ?? null, 'department_id' => $departmentId, 'created_by' => $actor->id]);
            // Default lists; "done" and "finished" carry the completion workflow and cannot be archived.
            foreach ([['للقيام', 'normal'], ['قيد التنفيذ', 'normal'], ['منجز', 'done'], ['منتهية', 'finished']] as $i => [$name, $kind]) {
                TaskList::create(['board_id' => $board->id, 'name' => $name, 'kind' => $kind, 'position' => ($i + 1) * self::STEP]);
            }
            if ($type !== 'personal') {
                BoardMember::create(['board_id' => $board->id, 'user_id' => $actor->id, 'role' => 'owner', 'added_by' => $actor->id]);
                foreach ($data['members'] ?? [] as $member) {
                    if ((int) $member['user_id'] !== $actor->id) {
                        $this->addMember($board, (int) $member['user_id'], $member['role'] ?? 'member', $actor, notify: true, log: false);
                    }
                }
            }
            $this->activity->record($board, null, $actor, 'board.created', ['name' => $board->name, 'type' => $type]);
            $this->audit->log('tasks.board_created', $board, null, ['type' => $type, 'name' => $board->name], $actor->id);

            return $board;
        }, self::TX_ATTEMPTS);
    }

    public function update(Board $board, array $data, User $actor): Board
    {
        $this->access->authorize($actor, $board, 'owner');

        return DB::transaction(function () use ($board, $data, $actor) {
            $board = Board::query()->lockForUpdate()->findOrFail($board->id);
            $board->fill(array_intersect_key($data, array_flip(['name', 'description', 'color'])));
            $board->version++;
            $board->save();
            $this->activity->record($board, null, $actor, 'board.updated', array_intersect_key($data, array_flip(['name', 'color'])));

            return $board;
        }, self::TX_ATTEMPTS);
    }

    public function archive(Board $board, bool $archived, User $actor): Board
    {
        $this->access->authorize($actor, $board, 'owner');

        return DB::transaction(function () use ($board, $archived, $actor) {
            $board = Board::query()->lockForUpdate()->findOrFail($board->id);
            $board->update(['archived_at' => $archived ? now() : null, 'version' => $board->version + 1]);
            $this->activity->record($board, null, $actor, $archived ? 'board.archived' : 'board.restored');
            $this->audit->log($archived ? 'tasks.board_archived' : 'tasks.board_restored', $board, null, ['name' => $board->name], $actor->id);

            return $board;
        }, self::TX_ATTEMPTS);
    }

    // ── Members ───────────────────────────────────────────────────────────────

    public function addMember(Board $board, int $userId, string $role, User $actor, bool $notify = true, bool $log = true): BoardMember
    {
        $this->access->authorize($actor, $board, 'owner');
        abort_if($board->type === 'personal', 422, 'اللوحة الشخصية لصاحبها فقط.');
        $user = User::query()->whereKey($userId)->where('status', 'active')->first();
        abort_unless($user, 422, 'المستخدم غير موجود أو معطّل.');

        return DB::transaction(function () use ($board, $user, $role, $actor, $notify, $log) {
            $member = BoardMember::query()->updateOrCreate(['board_id' => $board->id, 'user_id' => $user->id], ['role' => $role, 'added_by' => $actor->id]);
            if ($log) {
                $this->activity->record($board, null, $actor, 'member.added', ['user_id' => $user->id, 'role' => $role]);
            }
            if ($notify) {
                $this->notifier->toUsers([$user->id], 'tasks.board_member_added', 'أُضفت إلى لوحة المهام: '.$board->name,
                    ['board_id' => $board->id], $actor);
            }

            return $member;
        }, self::TX_ATTEMPTS);
    }

    public function changeRole(Board $board, int $userId, string $role, User $actor): BoardMember
    {
        $this->access->authorize($actor, $board, 'owner');

        return DB::transaction(function () use ($board, $userId, $role, $actor) {
            $member = BoardMember::query()->where('board_id', $board->id)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if ($member->role === 'owner' && $role !== 'owner') {
                $this->keepAnOwner($board, $userId);
            }
            $member->update(['role' => $role]);
            $this->activity->record($board, null, $actor, 'member.role_changed', ['user_id' => $userId, 'role' => $role]);

            return $member;
        }, self::TX_ATTEMPTS);
    }

    /** Removes an explicit member and takes them off the board's open cards. */
    public function removeMember(Board $board, int $userId, User $actor): void
    {
        $this->access->authorize($actor, $board, 'owner');
        DB::transaction(function () use ($board, $userId, $actor) {
            $member = BoardMember::query()->where('board_id', $board->id)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if ($member->role === 'owner') {
                $this->keepAnOwner($board, $userId);
            }
            $member->delete();
            // A department board still lets its employees in; only take them off cards if they lost access.
            if (! in_array($userId, $this->access->readers($board), true)) {
                $this->unassignFromOpenCards($board, $userId);
            }
            $this->activity->record($board, null, $actor, 'member.removed', ['user_id' => $userId]);
        }, self::TX_ATTEMPTS);
    }

    /** @return list<int> ids of the open cards the user was taken off */
    public function unassignFromOpenCards(Board $board, int $userId): array
    {
        $cards = Card::query()->where('board_id', $board->id)->whereNull('archived_at')->whereNull('completed_at')
            ->whereIn('id', DB::table('tsk_card_assignees')->where('user_id', $userId)->select('card_id'))->pluck('id')->all();
        DB::table('tsk_card_assignees')->whereIn('card_id', $cards)->where('user_id', $userId)->delete();
        DB::table('tsk_card_watchers')->where('user_id', $userId)
            ->whereIn('card_id', Card::query()->where('board_id', $board->id)->select('id'))->delete();

        return $cards;
    }

    private function keepAnOwner(Board $board, int $leaving): void
    {
        $others = array_diff($this->access->owners($board), [$leaving]);
        abort_if($others === [], 422, 'يجب أن يبقى للوحة مالك واحد على الأقل.');
    }

    // ── Lists ─────────────────────────────────────────────────────────────────

    public function createList(Board $board, string $name, User $actor): TaskList
    {
        $this->access->authorize($actor, $board, 'owner');

        return DB::transaction(function () use ($board, $name, $actor) {
            Board::query()->lockForUpdate()->findOrFail($board->id);
            // New lists go before "done", so the completion columns stay at the end.
            $position = $this->slot(function () use ($board) {
                $done = (float) TaskList::query()->where('board_id', $board->id)->where('kind', 'done')->value('position');
                $before = TaskList::query()->where('board_id', $board->id)->where('position', '<', $done)->max('position');

                return [$before === null ? null : (float) $before, $done];
            }, 'tsk_lists', ['board_id' => $board->id]);
            $list = TaskList::create(['board_id' => $board->id, 'name' => $name, 'kind' => 'normal', 'position' => $position]);
            $this->activity->record($board, null, $actor, 'list.created', ['list_id' => $list->id, 'name' => $name]);

            return $list;
        }, self::TX_ATTEMPTS);
    }

    public function updateList(TaskList $list, array $data, User $actor): TaskList
    {
        $board = $list->board;
        $this->access->authorize($actor, $board, 'owner');

        return DB::transaction(function () use ($list, $data, $actor, $board) {
            $list = TaskList::query()->lockForUpdate()->findOrFail($list->id);
            if (isset($data['name'])) {
                $list->name = $data['name'];
            }
            if (isset($data['position'])) {
                $list->position = (float) $data['position'];
            }
            $list->save();
            $this->activity->record($board, null, $actor, 'list.updated', ['list_id' => $list->id] + array_intersect_key($data, array_flip(['name'])));

            return $list;
        }, self::TX_ATTEMPTS);
    }

    public function archiveList(TaskList $list, bool $archived, User $actor): TaskList
    {
        $board = $list->board;
        $this->access->authorize($actor, $board, 'owner');
        abort_if($list->kind !== 'normal', 422, 'قائمتا "منجز" و"منتهية" ثابتتان في كل لوحة.');

        return DB::transaction(function () use ($list, $archived, $actor, $board) {
            $list = TaskList::query()->lockForUpdate()->findOrFail($list->id);
            abort_if($archived && Card::query()->where('list_id', $list->id)->whereNull('archived_at')->exists(), 422,
                'انقل بطاقات القائمة أو أرشفها أولاً.');
            $list->update(['archived_at' => $archived ? now() : null]);
            $this->activity->record($board, null, $actor, $archived ? 'list.archived' : 'list.restored', ['list_id' => $list->id]);

            return $list;
        }, self::TX_ATTEMPTS);
    }

    // ── Labels ────────────────────────────────────────────────────────────────

    public function saveLabel(Board $board, ?Label $label, array $data, User $actor): Label
    {
        $this->access->authorize($actor, $board, 'member');

        return DB::transaction(function () use ($board, $label, $data, $actor) {
            $label = $label ? tap($label)->update($data) : Label::create(['board_id' => $board->id] + $data);
            $this->activity->record($board, null, $actor, 'label.saved', ['label_id' => $label->id]);

            return $label;
        }, self::TX_ATTEMPTS);
    }

    public function deleteLabel(Label $label, User $actor): void
    {
        $board = Board::findOrFail($label->board_id);
        $this->access->authorize($actor, $board, 'member');
        DB::transaction(function () use ($label, $board, $actor) {
            $label->delete();
            $this->activity->record($board, null, $actor, 'label.deleted', ['label_id' => $label->id]);
        }, self::TX_ATTEMPTS);
    }

    /**
     * A position between two neighbours, as returned by $neighbours ([before, after]; null = open end). When the gap
     * has worn down (many drops in the same spot) the rows are renumbered in steps and the neighbours read again.
     */
    public function slot(callable $neighbours, string $table, array $scope): float
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$before, $after] = $neighbours();
            if ($after === null) {
                return ($before ?? 0) + self::STEP;
            }
            $before ??= $after - 2 * self::STEP;
            if ($after - $before > 1e-6) {
                return ($before + $after) / 2;
            }
            $rows = DB::table($table)->where($scope)->orderBy('position')->orderBy('id')->pluck('id');
            foreach ($rows as $i => $id) {
                DB::table($table)->where('id', $id)->update(['position' => ($i + 1) * self::STEP]);
            }
        }

        return ($before + $after) / 2;
    }
}
