<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Modules\Tasks\Models\Attachment;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Checklist;
use App\Modules\Tasks\Models\ChecklistItem;
use App\Modules\Tasks\Models\Comment;
use App\Modules\Tasks\Services\BoardAccess;
use App\Modules\Tasks\Services\CardService;
use App\Modules\Tasks\Services\TaskQueries;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardController extends Controller
{
    /** Photos, PDF, Office files, CAD drawings and archives (by extension: CAD files rarely have a reliable MIME type). */
    private const EXTENSIONS = 'jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,dwg,dxf,zip,rar,7z';

    public function __construct(private CardService $cards, private BoardAccess $access, private TaskQueries $queries) {}

    public function store(Board $board, Request $request): JsonResponse
    {
        $data = $request->validate([
            'list_id' => ['required', 'integer'],
            ...$this->fieldRules(true),
            'assignee_ids' => ['nullable', 'array', 'max:50'], 'assignee_ids.*' => ['integer'],
            'label_ids' => ['nullable', 'array', 'max:30'], 'label_ids.*' => ['integer'],
        ]);
        $card = $this->cards->create($board, $data, $request->user());

        return ApiResponse::created($this->detail($card, $request));
    }

    public function show(Card $card, Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), Board::findOrFail($card->board_id));

        return ApiResponse::ok($this->detail($card, $request));
    }

    public function update(Card $card, Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer'], ...$this->fieldRules(false)]);

        return ApiResponse::ok($this->detail($this->cards->update($card, $data, $request->user()), $request));
    }

    public function assignees(Card $card, Request $request): JsonResponse
    {
        $ids = $request->validate(['user_ids' => ['present', 'array', 'max:50'], 'user_ids.*' => ['integer']])['user_ids'];
        $result = $this->cards->setAssignees($card, $ids, $request->user());

        return ApiResponse::ok($this->detail($result['card'], $request));
    }

    public function labels(Card $card, Request $request): JsonResponse
    {
        $ids = $request->validate(['label_ids' => ['present', 'array', 'max:30'], 'label_ids.*' => ['integer']])['label_ids'];

        return ApiResponse::ok($this->detail($this->cards->setLabels($card, $ids, $request->user()), $request));
    }

    public function move(Card $card, Request $request): JsonResponse
    {
        $data = $request->validate(['list_id' => ['required', 'integer'], 'after_card_id' => ['nullable', 'integer']]);
        $card = $this->cards->move($card, (int) $data['list_id'], isset($data['after_card_id']) ? (int) $data['after_card_id'] : null, $request->user());

        return ApiResponse::ok($this->queries->summaries(collect([$card->refresh()]))[0]);
    }

    public function approve(Card $card, Request $request): JsonResponse
    {
        return ApiResponse::ok($this->detail($this->cards->approve($card, $request->user()), $request));
    }

    public function reject(Card $card, Request $request): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']])['reason'];

        return ApiResponse::ok($this->detail($this->cards->reject($card, $reason, $request->user()), $request));
    }

    public function reopen(Card $card, Request $request): JsonResponse
    {
        return ApiResponse::ok($this->detail($this->cards->reopen($card, $request->user()), $request));
    }

    public function archive(Card $card, Request $request): JsonResponse
    {
        $archived = $request->validate(['archived' => ['required', 'boolean']])['archived'];

        return ApiResponse::ok($this->detail($this->cards->archive($card, (bool) $archived, $request->user()), $request));
    }

    public function watch(Card $card, Request $request): JsonResponse
    {
        $watching = $request->validate(['watching' => ['required', 'boolean']])['watching'];
        $this->cards->watch($card, (bool) $watching, $request->user());

        return ApiResponse::ok(['watching' => (bool) $watching]);
    }

    public function transfer(Card $card, Request $request): JsonResponse
    {
        $data = $request->validate(['board_id' => ['required', 'integer', 'exists:tsk_boards,id'], 'list_id' => ['required', 'integer']]);
        $card = $this->cards->transfer($card, Board::findOrFail($data['board_id']), (int) $data['list_id'], $request->user());

        return ApiResponse::ok($this->detail($card, $request));
    }

    public function copy(Card $card, Request $request): JsonResponse
    {
        $data = $request->validate(['board_id' => ['required', 'integer', 'exists:tsk_boards,id'], 'list_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:200']]);
        $copy = $this->cards->copy($card, Board::findOrFail($data['board_id']), (int) $data['list_id'], $data['title'] ?? null, $request->user());

        return ApiResponse::created($this->detail($copy, $request));
    }

    // ── Checklists ────────────────────────────────────────────────────────────

    public function storeChecklist(Card $card, Request $request): JsonResponse
    {
        $title = $request->validate(['title' => ['required', 'string', 'max:150']])['title'];

        return ApiResponse::created($this->cards->addChecklist($card, $title, $request->user()));
    }

    public function updateChecklist(Checklist $checklist, Request $request): JsonResponse
    {
        $title = $request->validate(['title' => ['required', 'string', 'max:150']])['title'];
        $this->cards->updateChecklist($checklist, $title, false, $request->user());

        return ApiResponse::ok(null);
    }

    public function destroyChecklist(Checklist $checklist, Request $request): JsonResponse
    {
        $this->cards->updateChecklist($checklist, null, true, $request->user());

        return ApiResponse::ok(null);
    }

    public function storeItem(Checklist $checklist, Request $request): JsonResponse
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:500']])['text'];

        return ApiResponse::created($this->cards->addItem($checklist, $text, $request->user()));
    }

    public function updateItem(ChecklistItem $item, Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['sometimes', 'string', 'max:500'], 'done' => ['sometimes', 'boolean']]);

        return ApiResponse::ok($this->cards->updateItem($item, $data, $request->user()));
    }

    public function destroyItem(ChecklistItem $item, Request $request): JsonResponse
    {
        $this->cards->updateItem($item, ['delete' => true], $request->user());

        return ApiResponse::ok(null);
    }

    // ── Comments and attachments ──────────────────────────────────────────────

    public function storeComment(Card $card, Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'mentions' => ['nullable', 'array', 'max:30'], 'mentions.*' => ['integer']]);

        return ApiResponse::created($this->cards->comment($card, $data['body'], $data['mentions'] ?? [], $request->user()));
    }

    public function updateComment(Comment $comment, Request $request): JsonResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'max:5000']])['body'];

        return ApiResponse::ok($this->cards->editComment($comment, $body, $request->user()));
    }

    public function destroyComment(Comment $comment, Request $request): JsonResponse
    {
        $this->cards->editComment($comment, null, $request->user());

        return ApiResponse::ok(null);
    }

    public function storeAttachment(Card $card, Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:'.self::EXTENSIONS, 'max:25600']]);

        return ApiResponse::created($this->cards->attach($card, $request->file('file'), $request->user()));
    }

    public function downloadAttachment(Attachment $attachment, Request $request): StreamedResponse
    {
        $this->access->authorize($request->user(), Board::findOrFail(Card::findOrFail($attachment->card_id)->board_id));
        abort_unless(Storage::disk('local')->exists($attachment->path), 404, 'الملف غير موجود.');
        $inline = str_starts_with((string) $attachment->mime, 'image/') || $attachment->mime === 'application/pdf';

        return Storage::disk('local')->response($attachment->path, $attachment->original_name,
            ['Content-Type' => $attachment->mime ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'], $inline ? 'inline' : 'attachment');
    }

    public function destroyAttachment(Attachment $attachment, Request $request): JsonResponse
    {
        $this->cards->detach($attachment, $request->user());

        return ApiResponse::ok(null);
    }

    // ── Across boards ─────────────────────────────────────────────────────────

    public function mine(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['open', 'finished', 'all'])],
            'due' => ['nullable', Rule::in(['overdue', 'soon', 'none'])],
            'board_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        return ApiResponse::ok($this->queries->myCards($request->user(), $filters));
    }

    public function report(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'board_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'],
        ]);

        return ApiResponse::ok($this->queries->report($request->user(), $filters));
    }

    private function fieldRules(bool $creating): array
    {
        $sometimes = $creating ? [] : ['sometimes'];

        return [
            'title' => [...$sometimes, $creating ? 'required' : 'filled', 'string', 'max:200'],
            'description' => [...$sometimes, 'nullable', 'string', 'max:10000'],
            'priority' => [...$sometimes, 'nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'start_date' => [...$sometimes, 'nullable', 'date_format:Y-m-d'],
            'due_date' => [...$sometimes, 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /** The card with its leave warnings: assignees on approved leave on the due date (shown, not enforced). */
    private function detail(Card $card, Request $request): array
    {
        return [...$this->queries->card($card->refresh(), $request->user()), 'warnings' => $this->cards->leaveWarnings($card)];
    }
}
