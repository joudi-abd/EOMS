<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Modules\HR\Models\Department;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Label;
use App\Modules\Tasks\Models\Recurrence;
use App\Modules\Tasks\Models\TaskList;
use App\Modules\Tasks\Services\BoardAccess;
use App\Modules\Tasks\Services\BoardService;
use App\Modules\Tasks\Services\RecurrenceService;
use App\Modules\Tasks\Services\TaskQueries;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class BoardController extends Controller
{
    private const COLOR = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];

    public function __construct(private BoardService $boards, private BoardAccess $access, private TaskQueries $queries, private RecurrenceService $recurrences) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->queries->boards($request->user(), $request->boolean('archived')));
    }

    /**
     * What the user may create: board types, and the departments a department board may be opened for
     * (any department with company-wide tasks.manage, else the ones they manage), with names for the picker.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $ctx = $this->access->context($user);

        return ApiResponse::ok([
            'types' => array_values(array_filter(['personal', 'team', 'department'], fn ($type) => match ($type) {
                'department' => $ctx['createAny'] || ($ctx['createDepartment'] && $ctx['manages'] !== []),
                default => $this->access->mayCreate($user, $type, null),
            })),
            'departments' => Department::query()->when(! $ctx['createAny'], fn ($q) => $q->whereIn('id', $ctx['createDepartment'] ? $ctx['manages'] : []))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Active colleagues to invite to a board: name and department only. For people who can run boards
     * (tasks.manage at any scope — department managers get it with their department); board members are already listed in the board snapshot.
     * Department names come along so the picker reads without the HR permission.
     */
    public function people(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('tasks.manage'), 403);
        $q = trim((string) $request->query('q', ''));
        $rows = User::query()->where('usr_users.status', 'active')
            ->leftJoin('hr_employees as e', fn ($j) => $j->on('e.user_id', '=', 'usr_users.id')->where('e.status', '!=', 'terminated'))
            ->leftJoin('hr_departments as d', 'd.id', '=', 'e.department_id')
            ->when($q !== '', fn ($query) => $query->where('usr_users.name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%'))
            ->orderBy('usr_users.name')->limit(300)
            ->get(['usr_users.id as user_id', 'usr_users.name', 'e.department_id', 'd.name as department_name']);

        return ApiResponse::ok($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['personal', 'team', 'department'])],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => self::COLOR,
            'department_id' => ['required_if:type,department', 'nullable', 'integer', 'exists:hr_departments,id'],
            'members' => ['nullable', 'array', 'max:200'],
            'members.*.user_id' => ['required', 'integer', 'exists:usr_users,id'],
            'members.*.role' => ['nullable', Rule::in(['owner', 'member', 'viewer'])],
        ]);
        $board = $this->boards->create($data, $request->user());

        return ApiResponse::created($this->queries->board($board, $request->user()));
    }

    public function show(Board $board, Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), $board);

        return ApiResponse::ok($this->queries->board($board, $request->user()));
    }

    /** The change feed since a cursor (polling fallback for the broadcast channel). */
    public function changes(Board $board, Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), $board);
        $since = (int) $request->validate(['since' => ['required', 'integer', 'min:0']])['since'];

        return ApiResponse::ok($this->queries->changes($board, $since));
    }

    public function update(Board $board, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'color' => ['sometimes', ...self::COLOR]]);

        return ApiResponse::ok($this->boards->update($board, $data, $request->user()));
    }

    public function archive(Board $board, Request $request): JsonResponse
    {
        $archived = $request->validate(['archived' => ['required', 'boolean']])['archived'];

        return ApiResponse::ok($this->boards->archive($board, (bool) $archived, $request->user()));
    }

    /** Archived lists and cards, to restore them. */
    public function archived(Board $board, Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), $board);

        return ApiResponse::ok([
            'lists' => TaskList::where('board_id', $board->id)->whereNotNull('archived_at')->orderBy('position')->get(['id', 'name', 'kind', 'archived_at']),
            'cards' => $this->queries->summaries(Card::where('board_id', $board->id)->whereNotNull('archived_at')
                ->orderByDesc('archived_at')->limit(300)->get()),
        ]);
    }

    // ── Members ───────────────────────────────────────────────────────────────

    public function addMember(Board $board, Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:usr_users,id'], 'role' => ['required', Rule::in(['owner', 'member', 'viewer'])]]);

        return ApiResponse::created($this->boards->addMember($board, (int) $data['user_id'], $data['role'], $request->user()));
    }

    public function changeRole(Board $board, int $user, Request $request): JsonResponse
    {
        $role = $request->validate(['role' => ['required', Rule::in(['owner', 'member', 'viewer'])]])['role'];

        return ApiResponse::ok($this->boards->changeRole($board, $user, $role, $request->user()));
    }

    public function removeMember(Board $board, int $user, Request $request): JsonResponse
    {
        $this->boards->removeMember($board, $user, $request->user());

        return ApiResponse::ok(null);
    }

    // ── Lists and labels ──────────────────────────────────────────────────────

    public function storeList(Board $board, Request $request): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:100']])['name'];

        return ApiResponse::created($this->boards->createList($board, $name, $request->user()));
    }

    public function updateList(TaskList $list, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'position' => ['sometimes', 'numeric']]);

        return ApiResponse::ok($this->boards->updateList($list, $data, $request->user()));
    }

    public function archiveList(TaskList $list, Request $request): JsonResponse
    {
        $archived = $request->validate(['archived' => ['required', 'boolean']])['archived'];

        return ApiResponse::ok($this->boards->archiveList($list, (bool) $archived, $request->user()));
    }

    public function storeLabel(Board $board, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:50'], 'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']]);

        return ApiResponse::created($this->boards->saveLabel($board, null, $data, $request->user()));
    }

    public function updateLabel(Label $label, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:50'], 'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']]);

        return ApiResponse::ok($this->boards->saveLabel(Board::findOrFail($label->board_id), $label, $data, $request->user()));
    }

    public function destroyLabel(Label $label, Request $request): JsonResponse
    {
        $this->boards->deleteLabel($label, $request->user());

        return ApiResponse::ok(null);
    }

    // ── Recurring cards ───────────────────────────────────────────────────────

    public function recurrences(Board $board, Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), $board);

        return ApiResponse::ok(Recurrence::where('board_id', $board->id)->orderByDesc('active')->orderBy('id')->get());
    }

    public function storeRecurrence(Board $board, Request $request): JsonResponse
    {
        return ApiResponse::created($this->recurrences->save($board, null, $this->recurrenceData($request), $request->user()));
    }

    public function updateRecurrence(Recurrence $recurrence, Request $request): JsonResponse
    {
        return ApiResponse::ok($this->recurrences->save(Board::findOrFail($recurrence->board_id), $recurrence, $this->recurrenceData($request), $request->user()));
    }

    public function stopRecurrence(Recurrence $recurrence, Request $request): JsonResponse
    {
        $this->recurrences->stop($recurrence, $request->user());

        return ApiResponse::ok(null);
    }

    private function recurrenceData(Request $request): array
    {
        return $request->validate([
            'list_id' => ['required', 'integer'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'weekdays' => ['required_if:frequency,weekly', 'nullable', 'array', 'min:1'], 'weekdays.*' => ['integer', 'between:0,6'],
            'month_day' => ['required_if:frequency,monthly', 'nullable', 'integer', 'between:1,31'],
            'due_in_days' => ['nullable', 'integer', 'between:0,60'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'active' => ['nullable', 'boolean'],
            'template' => ['required', 'array'],
            'template.title' => ['required', 'string', 'max:200'],
            'template.description' => ['nullable', 'string', 'max:10000'],
            'template.priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'template.assignee_ids' => ['nullable', 'array'], 'template.assignee_ids.*' => ['integer'],
            'template.label_ids' => ['nullable', 'array'], 'template.label_ids.*' => ['integer'],
            'template.checklist' => ['nullable', 'array', 'max:10'],
            'template.checklist.*.title' => ['required', 'string', 'max:150'],
            'template.checklist.*.items' => ['nullable', 'array', 'max:50'], 'template.checklist.*.items.*' => ['string', 'max:500'],
        ]);
    }
}
