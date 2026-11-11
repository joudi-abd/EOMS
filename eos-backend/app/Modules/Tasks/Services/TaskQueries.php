<?php

namespace App\Modules\Tasks\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Activity;
use App\Modules\Tasks\Models\Attachment;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\BoardMember;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Checklist;
use App\Modules\Tasks\Models\ChecklistItem;
use App\Modules\Tasks\Models\Comment;
use App\Modules\Tasks\Models\Label;
use App\Modules\Tasks\Models\Recurrence;
use App\Modules\Tasks\Models\TaskList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read models for the tasks screens: board snapshots, card details, "my cards", the change feed and reports. */
class TaskQueries
{
    public function __construct(private BoardAccess $access, private ActivityLog $activity, private WorkCalendar $calendar) {}

    /** Boards the user can see, with their role and open-card counts. */
    public function boards(User $user, bool $archived): array
    {
        $boards = $this->access->visible($user)->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('type')->orderBy('name')->get();
        $finished = TaskList::whereIn('board_id', $boards->pluck('id'))->where('kind', 'finished')->pluck('id');
        $open = Card::whereIn('board_id', $boards->pluck('id'))->whereNull('archived_at')->whereNotIn('list_id', $finished)
            ->groupBy('board_id')->select('board_id', DB::raw('count(*) as n'))->pluck('n', 'board_id');
        $today = $this->calendar->today();
        $overdue = Card::whereIn('board_id', $boards->pluck('id'))->whereNull('archived_at')->whereNotIn('list_id', $finished)
            ->whereNull('done_at')->whereDate('due_date', '<', $today)->groupBy('board_id')->select('board_id', DB::raw('count(*) as n'))->pluck('n', 'board_id');

        return $boards->map(fn (Board $board) => [
            ...$board->only(['id', 'type', 'name', 'description', 'color', 'department_id', 'archived_at', 'version', 'created_at']),
            'role' => $this->access->role($user, $board),
            'open_cards' => (int) ($open[$board->id] ?? 0),
            'overdue_cards' => (int) ($overdue[$board->id] ?? 0),
        ])->values()->all();
    }

    /** Everything the board screen needs in one response, plus the cursor to poll or listen from. */
    public function board(Board $board, User $user): array
    {
        $role = $this->access->role($user, $board);
        $lists = TaskList::where('board_id', $board->id)->whereNull('archived_at')->orderBy('position')->get();
        $cards = Card::where('board_id', $board->id)->whereNull('archived_at')->orderBy('position')->get();

        return [
            'board' => $board->only(['id', 'type', 'name', 'description', 'color', 'department_id', 'archived_at', 'version', 'created_by']),
            'role' => $role,
            'members' => $this->members($board),
            'labels' => Label::where('board_id', $board->id)->orderBy('id')->get(['id', 'name', 'color']),
            'lists' => $lists->map(fn (TaskList $list) => $list->only(['id', 'name', 'kind', 'position']))->values(),
            'cards' => $this->summaries($cards),
            'cursor' => $this->activity->cursor($board->id),
            'today' => $this->calendar->today(),
        ];
    }

    /** People on the board: explicit rows and, for a department board, its employees and manager (implicit). */
    public function members(Board $board): array
    {
        $explicit = BoardMember::where('board_id', $board->id)->get()->keyBy('user_id');
        $ids = array_unique([...$this->access->readers($board), ...$explicit->keys()->all()]);
        $owners = $this->access->owners($board);
        $users = User::whereIn('id', $ids)->get(['id', 'name', 'status'])->keyBy('id');
        $employees = Employee::whereIn('user_id', $ids)->get(['id', 'user_id', 'department_id'])->keyBy('user_id');

        return collect($ids)->filter(fn ($id) => $users->has($id))->map(function ($id) use ($explicit, $owners, $users, $employees) {
            $row = $explicit->get($id);
            $role = $row?->role ?? (in_array($id, $owners, true) ? 'owner' : 'member');
            if (in_array($id, $owners, true)) {
                $role = 'owner';
            }

            return ['user_id' => (int) $id, 'name' => $users[$id]->name, 'role' => $role, 'implicit' => $row === null,
                'employee_id' => $employees->get($id)?->id, 'department_id' => $employees->get($id)?->department_id];
        })->sortBy([['role', 'desc'], ['name', 'asc']])->values()->all();
    }

    /** Compact cards for columns and lists. */
    public function summaries(Collection $cards): array
    {
        $ids = $cards->pluck('id');
        $assignees = DB::table('tsk_card_assignees')->whereIn('card_id', $ids)->get()->groupBy('card_id');
        $labels = DB::table('tsk_card_labels')->whereIn('card_id', $ids)->get()->groupBy('card_id');
        $checklists = Checklist::whereIn('card_id', $ids)->pluck('card_id', 'id');
        $items = ChecklistItem::whereIn('checklist_id', $checklists->keys())->get(['checklist_id', 'done']);
        $progress = [];
        foreach ($items as $item) {
            $cardId = $checklists[$item->checklist_id];
            $progress[$cardId] ??= ['done' => 0, 'total' => 0];
            $progress[$cardId]['total']++;
            $progress[$cardId]['done'] += $item->done ? 1 : 0;
        }
        $comments = Comment::whereIn('card_id', $ids)->groupBy('card_id')->select('card_id', DB::raw('count(*) as n'))->pluck('n', 'card_id');
        $attachments = Attachment::whereIn('card_id', $ids)->groupBy('card_id')->select('card_id', DB::raw('count(*) as n'))->pluck('n', 'card_id');

        return $cards->map(fn (Card $card) => [
            ...$card->only(['id', 'board_id', 'list_id', 'title', 'priority', 'start_date', 'due_date', 'position', 'done_at', 'completed_at', 'recurrence_id', 'version']),
            'start_date' => $card->start_date?->format('Y-m-d'),
            'due_date' => $card->due_date?->format('Y-m-d'),
            'assignee_ids' => ($assignees[$card->id] ?? collect())->pluck('user_id')->map(fn ($v) => (int) $v)->values(),
            'label_ids' => ($labels[$card->id] ?? collect())->pluck('label_id')->map(fn ($v) => (int) $v)->values(),
            'checklist' => $progress[$card->id] ?? ['done' => 0, 'total' => 0],
            'comments' => (int) ($comments[$card->id] ?? 0),
            'attachments' => (int) ($attachments[$card->id] ?? 0),
            'has_description' => filled($card->description),
        ])->values()->all();
    }

    public function card(Card $card, User $user): array
    {
        $users = fn (array $ids) => User::whereIn('id', $ids)->pluck('name', 'id');
        $comments = Comment::where('card_id', $card->id)->orderBy('id')->get();
        $activities = Activity::where('card_id', $card->id)->orderByDesc('id')->limit(100)->get();
        $names = $users(array_unique([...$comments->pluck('user_id'), ...$activities->pluck('user_id')->filter()]));
        $watching = DB::table('tsk_card_watchers')->where('card_id', $card->id)->where('user_id', $user->id)->exists();

        return [
            ...$this->summaries(collect([$card]))[0],
            'description' => $card->description,
            'previous_list_id' => $card->previous_list_id,
            'done_by' => $card->done_by,
            'completed_by' => $card->completed_by,
            'created_by' => $card->created_by,
            'created_at' => $card->created_at,
            'archived_at' => $card->archived_at,
            'watcher_ids' => DB::table('tsk_card_watchers')->where('card_id', $card->id)->pluck('user_id')->map(fn ($v) => (int) $v),
            'watching' => $watching,
            'checklists' => Checklist::where('card_id', $card->id)->orderBy('position')->get()->map(fn (Checklist $c) => [
                'id' => $c->id, 'title' => $c->title,
                'items' => ChecklistItem::where('checklist_id', $c->id)->orderBy('position')->get(['id', 'text', 'done', 'done_by', 'done_at']),
            ]),
            'comments' => $comments->map(fn (Comment $c) => [...$c->only(['id', 'user_id', 'body', 'mentions', 'edited_at', 'created_at']),
                'user_name' => $names[$c->user_id] ?? null]),
            'attachments' => Attachment::where('card_id', $card->id)->orderBy('id')->get(['id', 'original_name', 'mime', 'size', 'uploaded_by', 'created_at']),
            'activity' => $activities->map(fn (Activity $a) => [...$a->only(['id', 'action', 'data', 'user_id', 'created_at']),
                'user_name' => $a->user_id ? ($names[$a->user_id] ?? null) : null]),
            'recurrence' => $card->recurrence_id ? Recurrence::find($card->recurrence_id)?->only(['id', 'frequency', 'weekdays', 'month_day', 'due_in_days', 'next_run_on', 'active']) : null,
        ];
    }

    /** What changed since a cursor; clients reload what the actions touched (or the whole board when unsure). */
    public function changes(Board $board, int $since): array
    {
        $rows = Activity::where('board_id', $board->id)->where('id', '>', $since)->orderBy('id')->limit(200)->get(['id', 'card_id', 'action', 'user_id', 'created_at']);

        return ['cursor' => (int) ($rows->last()?->id ?? max($since, $this->activity->cursor($board->id))), 'changes' => $rows,
            'complete' => $rows->count() < 200];
    }

    /** Cards assigned to the user on every board they can still see. */
    public function myCards(User $user, array $filters): array
    {
        $boards = $this->access->visible($user)->whereNull('archived_at')->get(['id', 'name', 'type', 'color'])->keyBy('id');
        $lists = TaskList::whereIn('board_id', $boards->keys())->get(['id', 'name', 'kind'])->keyBy('id');
        $finishedIds = $lists->where('kind', 'finished')->keys();
        $today = $this->calendar->today();
        $soon = $this->calendar->nextWorkingDay($today);
        $cards = Card::whereIn('board_id', $boards->keys())->whereNull('archived_at')
            ->whereIn('id', DB::table('tsk_card_assignees')->where('user_id', $user->id)->select('card_id'))
            ->when(($filters['state'] ?? 'open') === 'open', fn ($q) => $q->whereNotIn('list_id', $finishedIds))
            ->when(($filters['state'] ?? null) === 'finished', fn ($q) => $q->whereIn('list_id', $finishedIds))
            ->when(($filters['due'] ?? null) === 'overdue', fn ($q) => $q->whereNull('done_at')->whereDate('due_date', '<', $today))
            ->when(($filters['due'] ?? null) === 'soon', fn ($q) => $q->whereNull('done_at')->whereDate('due_date', '>=', $today)->whereDate('due_date', '<=', $soon))
            ->when(($filters['due'] ?? null) === 'none', fn ($q) => $q->whereNull('due_date'))
            ->when(! empty($filters['board_id']), fn ($q) => $q->where('board_id', (int) $filters['board_id']))
            ->when(! empty($filters['priority']), fn ($q) => $q->where('priority', $filters['priority']))
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id')->limit(500)->get();

        return ['today' => $today, 'cards' => collect($this->summaries($cards))->map(fn ($c) => [...$c,
            'board' => $boards[$c['board_id']]->only(['id', 'name', 'type', 'color']),
            'list' => $lists[$c['list_id']]->only(['id', 'name', 'kind'])])->all()];
    }

    /**
     * Per-assignee figures over the boards the user can see (personal boards excluded): open, overdue, finished in
     * the period, finished on time, and the average days from creation to confirmation.
     */
    public function report(User $user, array $filters): array
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $boards = $this->access->visible($user)->where('type', '!=', 'personal')
            ->when(! empty($filters['board_id']), fn ($q) => $q->whereKey((int) $filters['board_id']))->pluck('id');
        $finishedLists = TaskList::whereIn('board_id', $boards)->where('kind', 'finished')->pluck('id');
        $today = $this->calendar->today();
        $rows = DB::table('tsk_card_assignees as a')->join('tsk_cards as c', 'c.id', '=', 'a.card_id')
            ->whereIn('c.board_id', $boards)->whereNull('c.archived_at')
            ->get(['a.user_id', 'c.id', 'c.list_id', 'c.due_date', 'c.done_at', 'c.completed_at', 'c.created_at']);
        if (! empty($filters['department_id'])) {
            $inDepartment = Employee::where('department_id', (int) $filters['department_id'])->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $rows = $rows->filter(fn ($r) => in_array((int) $r->user_id, $inDepartment, true));
        }
        $names = User::whereIn('id', $rows->pluck('user_id')->unique())->pluck('name', 'id');
        $finishedSet = $finishedLists->flip();

        $people = $rows->groupBy('user_id')->map(function (Collection $cards, $userId) use ($names, $finishedSet, $today, $from, $to) {
            $open = $cards->filter(fn ($c) => ! $finishedSet->has($c->list_id));
            $finished = $cards->filter(function ($c) use ($finishedSet, $from, $to) {
                if (! $finishedSet->has($c->list_id) || $c->completed_at === null) {
                    return false;
                }
                $day = substr((string) $c->completed_at, 0, 10);

                return $day >= $from && $day <= $to;
            });
            $onTime = $finished->filter(fn ($c) => $c->due_date === null || substr((string) ($c->done_at ?? $c->completed_at), 0, 10) <= substr((string) $c->due_date, 0, 10));
            $days = $finished->map(fn ($c) => CarbonImmutable::parse($c->created_at)->diffInHours(CarbonImmutable::parse($c->completed_at)) / 24);

            return [
                'user_id' => (int) $userId, 'name' => $names[$userId] ?? null,
                'open' => $open->count(),
                'overdue' => $open->filter(fn ($c) => $c->done_at === null && $c->due_date !== null && substr((string) $c->due_date, 0, 10) < $today)->count(),
                'awaiting_review' => $open->filter(fn ($c) => $c->done_at !== null)->count(),
                'finished' => $finished->count(),
                'on_time' => $onTime->count(),
                'on_time_rate' => $finished->count() ? round($onTime->count() / $finished->count(), 4) : null,
                'avg_days' => $days->count() ? round($days->avg(), 1) : null,
            ];
        })->sortByDesc('overdue')->values();

        return ['from' => $from, 'to' => $to, 'today' => $today, 'people' => $people,
            'totals' => ['open' => $people->sum('open'), 'overdue' => $people->sum('overdue'), 'finished' => $people->sum('finished'),
                'on_time' => $people->sum('on_time')]];
    }
}
