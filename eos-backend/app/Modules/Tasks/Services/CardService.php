<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Attachment;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Checklist;
use App\Modules\Tasks\Models\ChecklistItem;
use App\Modules\Tasks\Models\Comment;
use App\Modules\Tasks\Models\Label;
use App\Modules\Tasks\Models\TaskList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Cards and the completion workflow: any member moves cards between normal lists; an assignee (or an owner) moves
 * a card to "done"; an owner confirms it into "finished" or sends it back to where it was with a reason. A finished
 * card is read-only until an owner reopens it. On a personal board the owner is the reviewer, so "done" finishes.
 */
class CardService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(
        private BoardAccess $access,
        private BoardService $boards,
        private ActivityLog $activity,
        private TaskNotifier $notifier,
        private WorkCalendar $calendar,
    ) {}

    // ── Create and edit ───────────────────────────────────────────────────────

    public function create(Board $board, array $data, User $actor): Card
    {
        $this->writable($actor, $board, 'member');

        return $this->createSystem($board, $data, $actor);
    }

    /** Creates a card without checking the actor's role (recurring cards run as their creator). */
    public function createSystem(Board $board, array $data, User $actor): Card
    {
        $this->checkDates($data['start_date'] ?? null, $data['due_date'] ?? null);

        return DB::transaction(function () use ($board, $data, $actor) {
            $list = $this->list($board, (int) $data['list_id']);
            abort_unless($list->kind === 'normal', 422, 'تُنشأ البطاقات في القوائم العادية؛ "منجز" و"منتهية" لمراحل الإنجاز.');
            $card = Card::create([
                'board_id' => $board->id, 'list_id' => $list->id, 'title' => $data['title'],
                'description' => $data['description'] ?? null, 'priority' => $data['priority'] ?? 'normal',
                'start_date' => $data['start_date'] ?? null, 'due_date' => $data['due_date'] ?? null,
                'position' => $this->endOf($list), 'created_by' => $actor->id,
                'recurrence_id' => $data['recurrence_id'] ?? null,
            ]);
            $card->watchers()->syncWithoutDetaching([$actor->id]);
            $this->activity->record($board, $card, $actor, 'card.created', ['title' => $card->title, 'list_id' => $list->id]);
            if (! empty($data['label_ids'])) {
                $card->labels()->sync($this->labelsOf($board, $data['label_ids']));
            }
            if (! empty($data['assignee_ids'])) {
                $this->applyAssignees($board, $card, $data['assignee_ids'], $actor);
            }
            foreach ($data['checklist'] ?? [] as $i => $checklist) {
                $this->copyChecklist($card, $checklist, $i);
            }

            return $card->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function update(Card $card, array $data, User $actor): Card
    {
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'member');

        return DB::transaction(function () use ($card, $board, $data, $actor) {
            $card = $this->lockOpen($card);
            if ((int) $data['version'] !== $card->version) {
                abort(409, 'عدّل شخص آخر البطاقة للتو؛ حدّث الصفحة ثم أعد التعديل.');
            }
            $fields = array_intersect_key($data, array_flip(['title', 'description', 'priority', 'start_date', 'due_date']));
            $before = $card->only(array_keys($fields));
            $card->fill($fields);
            $this->checkDates($card->start_date?->format('Y-m-d'), $card->due_date?->format('Y-m-d'));
            $dueChanged = $card->isDirty('due_date');
            if ($dueChanged) {
                $card->reminded_soon_for = null;
                $card->reminded_overdue_for = null;
            }
            if (! $card->isDirty()) {
                return $card;
            }
            $changed = array_keys($card->getDirty());
            $card->version++;
            $card->save();
            $this->activity->record($board, $card, $actor, 'card.updated', ['fields' => array_values(array_diff($changed, ['version', 'reminded_soon_for', 'reminded_overdue_for'])),
                'due_date' => $dueChanged ? ['from' => $before['due_date'] ?? null, 'to' => $card->due_date?->format('Y-m-d')] : null]);
            if ($dueChanged) {
                $this->notifier->aboutCard($this->watcherIds($card), 'tasks.card_due_changed',
                    'تغيّر موعد الاستحقاق إلى '.($card->due_date?->format('Y-m-d') ?? 'بلا موعد'), $card, $actor);
            }

            return $card;
        }, self::TX_ATTEMPTS);
    }

    /** @return array{card: Card, warnings: list<array{user_id: int, date: string}>} */
    public function setAssignees(Card $card, array $userIds, User $actor): array
    {
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'member');

        return DB::transaction(function () use ($card, $board, $userIds, $actor) {
            $card = $this->lockOpen($card);
            $this->applyAssignees($board, $card, $userIds, $actor);

            return ['card' => $card->refresh(), 'warnings' => $this->leaveWarnings($card)];
        }, self::TX_ATTEMPTS);
    }

    public function setLabels(Card $card, array $labelIds, User $actor): Card
    {
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'member');

        return DB::transaction(function () use ($card, $board, $labelIds, $actor) {
            $card = $this->lockOpen($card);
            $card->labels()->sync($this->labelsOf($board, $labelIds));
            $this->activity->record($board, $card, $actor, 'card.labels', ['label_ids' => array_values(array_map('intval', $labelIds))]);

            return $card;
        }, self::TX_ATTEMPTS);
    }

    // ── Moving and the completion workflow ────────────────────────────────────

    /**
     * Moves a card to a list, after $afterCardId (null = top). Reaching "done" or "finished" follows the workflow
     * rules; a finished card is moved only by reopen().
     */
    public function move(Card $card, int $listId, ?int $afterCardId, User $actor): Card
    {
        $board = $this->boardOf($card);
        $role = $this->writable($actor, $board, 'member');

        return DB::transaction(function () use ($card, $board, $role, $listId, $afterCardId, $actor) {
            $card = Card::query()->lockForUpdate()->findOrFail($card->id);
            abort_if($card->archived_at !== null, 409, 'البطاقة مؤرشفة.');
            $from = TaskList::findOrFail($card->list_id);
            $to = $this->list($board, $listId);
            abort_if($from->kind === 'finished' && $to->id !== $from->id, 422, 'البطاقة منتهية؛ يعيد المالك فتحها أولاً.');
            $owner = $role === 'owner';
            $assignee = $card->assignees()->whereKey($actor->id)->exists();

            if ($to->kind === 'done' && $from->kind !== 'done') {
                abort_unless($owner || $assignee, 403, 'ينقل البطاقة إلى "منجز" المكلّفون بها أو مالك اللوحة.');
            }
            if ($from->kind === 'done' && $to->kind === 'normal') {
                abort_unless($owner || $assignee, 403, 'إعادة البطاقة من "منجز" للمكلّفين أو المالك؛ المالك يرفضها بسبب.');
            }
            if ($to->kind === 'finished') {
                abort_unless($owner, 403, 'يؤكد مالك اللوحة الإنجاز وينقل البطاقة إلى "منتهية".');
            }

            $card->position = $this->positionIn($to, $afterCardId, $card->id);
            if ($to->id === $from->id) {
                $card->save();
                $this->activity->record($board, $card, $actor, 'card.reordered', ['list_id' => $to->id]);

                return $card;
            }
            if ($from->kind === 'normal' && $to->kind !== 'normal') {
                $card->previous_list_id = $from->id;
            }
            $card->list_id = $to->id;
            if ($to->kind === 'normal') {
                $card->done_at = $card->done_by = null;
            }
            if ($to->kind === 'done') {
                $card->done_at = now();
                $card->done_by = $actor->id;
            }
            if ($to->kind === 'finished') {
                $card->done_at ??= now();
                $card->done_by ??= $actor->id;
                $card->completed_at = now();
                $card->completed_by = $actor->id;
            }
            $card->version++;
            $card->save();
            $this->activity->record($board, $card, $actor, 'card.moved', ['from' => $from->id, 'to' => $to->id, 'kind' => $to->kind]);

            // A personal board's owner is also the reviewer: "done" finishes the card at once.
            if ($to->kind === 'done' && $board->type === 'personal') {
                return $this->finish($board, $card, $actor);
            }
            match ($to->kind) {
                'done' => $this->notifier->aboutCard($this->access->owners($board), 'tasks.card_review', 'بطاقة بانتظار تأكيد إنجازها', $card, $actor),
                'finished' => $this->notifier->aboutCard([...$this->assigneeIds($card), ...$this->watcherIds($card)], 'tasks.card_finished', 'أُكّد إنجاز البطاقة', $card, $actor),
                default => $this->notifier->aboutCard($this->watcherIds($card), 'tasks.card_moved', 'نُقلت البطاقة إلى '.$to->name, $card, $actor),
            };

            return $card;
        }, self::TX_ATTEMPTS);
    }

    /** The owner confirms a card in "done": it goes to the end of "finished". */
    public function approve(Card $card, User $actor): Card
    {
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'owner');
        abort_unless(TaskList::whereKey($card->list_id)->value('kind') === 'done', 422, 'البطاقة ليست في "منجز".');

        return $this->move($card, (int) $this->kindList($board, 'finished')->id, $this->lastCardId($this->kindList($board, 'finished')), $actor);
    }

    /** The owner sends a card in "done" back to where it was, with a reason that becomes a comment. */
    public function reject(Card $card, string $reason, User $actor): Card
    {
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'owner');

        return DB::transaction(function () use ($card, $board, $reason, $actor) {
            $card = Card::query()->lockForUpdate()->findOrFail($card->id);
            abort_unless(TaskList::whereKey($card->list_id)->value('kind') === 'done', 422, 'البطاقة ليست في "منجز".');
            $back = $this->returnList($board, $card);
            $card->update(['list_id' => $back->id, 'position' => $this->endOf($back), 'done_at' => null, 'done_by' => null,
                'version' => $card->version + 1]);
            Comment::create(['card_id' => $card->id, 'user_id' => $actor->id, 'body' => 'لم يُعتمد الإنجاز: '.$reason]);
            $this->activity->record($board, $card, $actor, 'card.rejected', ['to' => $back->id, 'reason' => $reason]);
            $this->notifier->aboutCard($this->assigneeIds($card), 'tasks.card_rejected', 'لم يُعتمد إنجاز البطاقة ('.$reason.')', $card, $actor);

            return $card;
        }, self::TX_ATTEMPTS);
    }

    /** The owner reopens a finished card: it returns to the list it was in before "done". */
    public function reopen(Card $card, User $actor): Card
    {
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'owner');

        return DB::transaction(function () use ($card, $board, $actor) {
            $card = Card::query()->lockForUpdate()->findOrFail($card->id);
            abort_unless(TaskList::whereKey($card->list_id)->value('kind') === 'finished', 422, 'البطاقة ليست منتهية.');
            $back = $this->returnList($board, $card);
            $card->update(['list_id' => $back->id, 'position' => $this->endOf($back), 'done_at' => null, 'done_by' => null,
                'completed_at' => null, 'completed_by' => null, 'version' => $card->version + 1]);
            $this->activity->record($board, $card, $actor, 'card.reopened', ['to' => $back->id]);
            $this->notifier->aboutCard($this->assigneeIds($card), 'tasks.card_reopened', 'أُعيد فتح البطاقة', $card, $actor);

            return $card;
        }, self::TX_ATTEMPTS);
    }

    public function archive(Card $card, bool $archived, User $actor): Card
    {
        $board = $this->boardOf($card);
        $finished = TaskList::whereKey($card->list_id)->value('kind') === 'finished';
        $this->writable($actor, $board, $finished ? 'owner' : 'member');

        return DB::transaction(function () use ($card, $board, $archived, $actor) {
            $card = Card::query()->lockForUpdate()->findOrFail($card->id);
            $list = TaskList::findOrFail($card->list_id);
            $update = ['archived_at' => $archived ? now() : null, 'version' => $card->version + 1];
            // Restoring into an archived list would hide the card: it goes to the first open normal list instead.
            if (! $archived && $list->archived_at !== null) {
                $first = $this->firstNormal($board);
                $update += ['list_id' => $first->id, 'position' => $this->endOf($first)];
            }
            $card->update($update);
            $this->activity->record($board, $card, $actor, $archived ? 'card.archived' : 'card.restored');

            return $card;
        }, self::TX_ATTEMPTS);
    }

    public function watch(Card $card, bool $watching, User $actor): void
    {
        $this->access->authorize($actor, $this->boardOf($card));
        $watching ? $card->watchers()->syncWithoutDetaching([$actor->id]) : $card->watchers()->detach($actor->id);
    }

    // ── Checklists ────────────────────────────────────────────────────────────

    public function addChecklist(Card $card, string $title, User $actor): Checklist
    {
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'member');

        return DB::transaction(function () use ($card, $board, $title, $actor) {
            $card = $this->lockOpen($card);
            $position = (float) Checklist::where('card_id', $card->id)->max('position') + BoardService::STEP;
            $checklist = Checklist::create(['card_id' => $card->id, 'title' => $title, 'position' => $position]);
            $this->activity->record($board, $card, $actor, 'checklist.added', ['title' => $title]);

            return $checklist;
        }, self::TX_ATTEMPTS);
    }

    public function updateChecklist(Checklist $checklist, ?string $title, bool $delete, User $actor): void
    {
        $card = Card::findOrFail($checklist->card_id);
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'member');
        DB::transaction(function () use ($checklist, $card, $board, $title, $delete, $actor) {
            $this->lockOpen($card);
            $delete ? $checklist->delete() : $checklist->update(['title' => $title]);
            $this->activity->record($board, $card, $actor, $delete ? 'checklist.deleted' : 'checklist.renamed', ['title' => $title ?? $checklist->title]);
        }, self::TX_ATTEMPTS);
    }

    public function addItem(Checklist $checklist, string $text, User $actor): ChecklistItem
    {
        $card = Card::findOrFail($checklist->card_id);
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'member');

        return DB::transaction(function () use ($checklist, $card, $board, $text, $actor) {
            $this->lockOpen($card);
            $position = (float) ChecklistItem::where('checklist_id', $checklist->id)->max('position') + BoardService::STEP;
            $item = ChecklistItem::create(['checklist_id' => $checklist->id, 'text' => $text, 'position' => $position]);
            $this->activity->record($board, $card, $actor, 'checklist.item_added', ['text' => $text]);

            return $item;
        }, self::TX_ATTEMPTS);
    }

    public function updateItem(ChecklistItem $item, array $data, User $actor): ?ChecklistItem
    {
        $checklist = Checklist::findOrFail($item->checklist_id);
        $card = Card::findOrFail($checklist->card_id);
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'member');

        return DB::transaction(function () use ($item, $card, $board, $data, $actor) {
            $this->lockOpen($card);
            if (! empty($data['delete'])) {
                $item->delete();
                $this->activity->record($board, $card, $actor, 'checklist.item_deleted', ['text' => $item->text]);

                return null;
            }
            if (array_key_exists('done', $data)) {
                $item->fill(['done' => (bool) $data['done'], 'done_by' => $data['done'] ? $actor->id : null, 'done_at' => $data['done'] ? now() : null]);
            }
            if (isset($data['text'])) {
                $item->text = $data['text'];
            }
            $item->save();
            $this->activity->record($board, $card, $actor, array_key_exists('done', $data) ? ($data['done'] ? 'checklist.item_checked' : 'checklist.item_unchecked') : 'checklist.item_edited', ['text' => $item->text]);

            return $item;
        }, self::TX_ATTEMPTS);
    }

    // ── Comments ──────────────────────────────────────────────────────────────

    /** Anyone who can read the board comments (viewers included). Mentions must be people who can read it too. */
    public function comment(Card $card, string $body, array $mentions, User $actor): Comment
    {
        $board = $this->boardOf($card);
        $this->access->authorize($actor, $board);
        abort_if($board->archived_at !== null, 409, 'اللوحة مؤرشفة.');
        $readers = $this->access->readers($board);
        $mentions = array_values(array_unique(array_map('intval', $mentions)));
        $outside = array_diff($mentions, $readers);
        if ($outside !== []) {
            throw ValidationException::withMessages(['mentions' => ['لا يمكن الإشارة إلى من لا يرى اللوحة.']]);
        }

        return DB::transaction(function () use ($card, $board, $body, $mentions, $actor) {
            $comment = Comment::create(['card_id' => $card->id, 'user_id' => $actor->id, 'body' => $body, 'mentions' => $mentions ?: null]);
            $this->activity->record($board, $card, $actor, 'comment.added', ['comment_id' => $comment->id]);
            $this->notifier->aboutCard($mentions, 'tasks.mentioned', $actor->name.' أشار إليك في تعليق', $card, $actor);
            $this->notifier->aboutCard(array_diff($this->watcherIds($card), $mentions), 'tasks.card_comment', $actor->name.' علّق على البطاقة', $card, $actor);

            return $comment;
        }, self::TX_ATTEMPTS);
    }

    public function editComment(Comment $comment, ?string $body, User $actor): ?Comment
    {
        $card = Card::findOrFail($comment->card_id);
        $board = $this->boardOf($card);
        $role = $this->access->authorize($actor, $board);
        // Authors edit and delete their own comments; an owner may remove any comment.
        abort_unless($comment->user_id === $actor->id || ($body === null && $role === 'owner'), 403, 'تعدّل تعليقاتك أنت فقط.');

        return DB::transaction(function () use ($comment, $card, $board, $body, $actor) {
            if ($body === null) {
                $comment->delete();
                $this->activity->record($board, $card, $actor, 'comment.deleted', ['comment_id' => $comment->id]);

                return null;
            }
            $comment->update(['body' => $body, 'edited_at' => now()]);
            $this->activity->record($board, $card, $actor, 'comment.edited', ['comment_id' => $comment->id]);

            return $comment;
        }, self::TX_ATTEMPTS);
    }

    // ── Attachments ───────────────────────────────────────────────────────────

    public function attach(Card $card, UploadedFile $file, User $actor): Attachment
    {
        $board = $this->boardOf($card);
        $this->writable($actor, $board, 'member');
        abort_if(Attachment::where('card_id', $card->id)->count() >= 30, 422, 'الحد الأقصى 30 مرفقاً للبطاقة.');
        $path = $file->store('tasks/cards/'.$card->id, 'local');

        try {
            return DB::transaction(function () use ($card, $board, $file, $path, $actor) {
                $this->lockOpen($card);
                $attachment = Attachment::create(['card_id' => $card->id, 'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType(),
                    'size' => $file->getSize(), 'uploaded_by' => $actor->id]);
                $this->activity->record($board, $card, $actor, 'attachment.added', ['name' => $attachment->original_name]);

                return $attachment;
            }, self::TX_ATTEMPTS);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function detach(Attachment $attachment, User $actor): void
    {
        $card = Card::findOrFail($attachment->card_id);
        $board = $this->boardOf($card);
        $role = $this->writable($actor, $board, 'member');
        abort_unless($attachment->uploaded_by === $actor->id || $role === 'owner', 403, 'يحذف المرفق من رفعه أو مالك اللوحة.');
        DB::transaction(function () use ($attachment, $card, $board, $actor) {
            $this->lockOpen($card);
            $attachment->delete();
            $this->activity->record($board, $card, $actor, 'attachment.removed', ['name' => $attachment->original_name]);
        }, self::TX_ATTEMPTS);
        DB::afterCommit(fn () => Storage::disk('local')->delete($attachment->path));
    }

    // ── Between boards ────────────────────────────────────────────────────────

    /**
     * Moves a card to another board the actor works on. Labels follow by name; assignees and watchers who cannot
     * work on the new board are dropped. History stays with the card.
     */
    public function transfer(Card $card, Board $target, int $listId, User $actor): Card
    {
        $source = $this->boardOf($card);
        $this->writable($actor, $source, 'member');
        $this->writable($actor, $target, 'member');
        abort_if($source->id === $target->id, 422, 'اختر لوحة أخرى.');

        return DB::transaction(function () use ($card, $source, $target, $listId, $actor) {
            $card = $this->lockOpen($card);
            $list = $this->list($target, $listId);
            abort_unless($list->kind === 'normal', 422, 'تُنقل البطاقة إلى قائمة عادية.');
            $labels = $this->matchLabels($card, $target);
            $workers = $this->access->workers($target);
            $card->update(['board_id' => $target->id, 'list_id' => $list->id, 'position' => $this->endOf($list), 'previous_list_id' => null,
                'done_at' => null, 'done_by' => null, 'recurrence_id' => null, 'version' => $card->version + 1]);
            $card->labels()->sync($labels);
            $card->assignees()->sync(array_values(array_intersect($this->assigneeIds($card), $workers)));
            $card->watchers()->sync(array_values(array_intersect($this->watcherIds($card), $this->access->readers($target))));
            $this->activity->record($source, $card, $actor, 'card.transferred_out', ['to_board' => $target->id]);
            $this->activity->record($target, $card, $actor, 'card.transferred_in', ['from_board' => $source->id]);

            return $card;
        }, self::TX_ATTEMPTS);
    }

    /** Copies a card (fields, labels by name, checklists unticked, assignees who can work there) to a board. */
    public function copy(Card $card, Board $target, int $listId, ?string $title, User $actor): Card
    {
        $source = $this->boardOf($card);
        $this->access->authorize($actor, $source);
        $this->writable($actor, $target, 'member');

        return DB::transaction(function () use ($card, $target, $listId, $title, $actor) {
            $list = $this->list($target, $listId);
            abort_unless($list->kind === 'normal', 422, 'تُنسخ البطاقة إلى قائمة عادية.');
            $copy = Card::create(['board_id' => $target->id, 'list_id' => $list->id, 'title' => $title ?: $card->title,
                'description' => $card->description, 'priority' => $card->priority, 'start_date' => $card->start_date,
                'due_date' => $card->due_date, 'position' => $this->endOf($list), 'created_by' => $actor->id]);
            $copy->labels()->sync($this->matchLabels($card, $target));
            $copy->watchers()->sync([$actor->id]);
            $this->activity->record($target, $copy, $actor, 'card.created', ['title' => $copy->title, 'copied_from' => $card->id]);
            $assignees = array_values(array_intersect($this->assigneeIds($card), $this->access->workers($target)));
            if ($assignees !== []) {
                $this->applyAssignees($target, $copy, $assignees, $actor);
            }
            foreach (Checklist::where('card_id', $card->id)->orderBy('position')->get() as $i => $checklist) {
                $this->copyChecklist($copy, ['title' => $checklist->title, 'items' => ChecklistItem::where('checklist_id', $checklist->id)
                    ->orderBy('position')->pluck('text')->all()], $i);
            }

            return $copy;
        }, self::TX_ATTEMPTS);
    }

    // ── Helpers shared with the controller and other services ─────────────────

    /** @return list<array{user_id: int, date: string}> assignees with approved leave on the due date */
    public function leaveWarnings(Card $card): array
    {
        if ($card->due_date === null) {
            return [];
        }
        $date = $card->due_date->format('Y-m-d');

        return array_map(fn ($id) => ['user_id' => $id, 'date' => $date], $this->calendar->onLeave($this->assigneeIds($card), $date));
    }

    public function assigneeIds(Card $card): array
    {
        return DB::table('tsk_card_assignees')->where('card_id', $card->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    public function watcherIds(Card $card): array
    {
        return DB::table('tsk_card_watchers')->where('card_id', $card->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Owner or member role on a board that is not archived; returns the role. */
    public function writable(User $actor, Board $board, string $atLeast): string
    {
        $role = $this->access->authorize($actor, $board, $atLeast);
        abort_if($board->archived_at !== null, 409, 'اللوحة مؤرشفة؛ يستعيدها المالك أولاً.');

        return $role;
    }

    private function applyAssignees(Board $board, Card $card, array $userIds, User $actor): void
    {
        $wanted = array_values(array_unique(array_map('intval', $userIds)));
        $outside = array_diff($wanted, $this->access->workers($board));
        if ($outside !== []) {
            throw ValidationException::withMessages(['assignee_ids' => ['يُكلَّف أعضاء اللوحة فقط (ليس المشاهدين).']]);
        }
        $current = $this->assigneeIds($card);
        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));
        if ($added === [] && $removed === []) {
            return;
        }
        $card->assignees()->sync($wanted);
        $card->watchers()->syncWithoutDetaching($added);
        $this->activity->record($board, $card, $actor, 'card.assignees', ['added' => $added, 'removed' => $removed]);
        $this->notifier->aboutCard($added, 'tasks.card_assigned', 'كُلّفت ببطاقة', $card, $actor);
        $this->notifier->aboutCard($removed, 'tasks.card_unassigned', 'أُزلت من بطاقة', $card, $actor);
    }

    private function checkDates(?string $start, ?string $due): void
    {
        if ($start !== null && $due !== null && $due < $start) {
            throw ValidationException::withMessages(['due_date' => ['موعد الاستحقاق قبل تاريخ البدء.']]);
        }
    }

    private function finish(Board $board, Card $card, User $actor): Card
    {
        $finished = $this->kindList($board, 'finished');
        $card->update(['list_id' => $finished->id, 'position' => $this->endOf($finished), 'completed_at' => now(),
            'completed_by' => $actor->id, 'version' => $card->version + 1]);
        $this->activity->record($board, $card, $actor, 'card.moved', ['to' => $finished->id, 'kind' => 'finished']);

        return $card;
    }

    private function copyChecklist(Card $card, array $checklist, int $index): void
    {
        $model = Checklist::create(['card_id' => $card->id, 'title' => $checklist['title'], 'position' => ($index + 1) * BoardService::STEP]);
        foreach (array_values($checklist['items'] ?? []) as $i => $text) {
            ChecklistItem::create(['checklist_id' => $model->id, 'text' => $text, 'position' => ($i + 1) * BoardService::STEP]);
        }
    }

    private function matchLabels(Card $card, Board $target): array
    {
        $names = $card->labels()->pluck('name')->all();

        return Label::where('board_id', $target->id)->whereIn('name', $names)->pluck('id')->all();
    }

    private function labelsOf(Board $board, array $labelIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $labelIds)));
        $found = Label::where('board_id', $board->id)->whereIn('id', $ids)->pluck('id')->all();
        abort_unless(count($found) === count($ids), 422, 'تسمية لا تخص هذه اللوحة.');

        return $found;
    }

    private function lockOpen(Card $card): Card
    {
        $card = Card::query()->lockForUpdate()->findOrFail($card->id);
        abort_if($card->archived_at !== null, 409, 'البطاقة مؤرشفة.');
        abort_if(TaskList::whereKey($card->list_id)->value('kind') === 'finished', 422, 'البطاقة منتهية؛ يعيد المالك فتحها للتعديل.');

        return $card;
    }

    private function boardOf(Card $card): Board
    {
        return Board::findOrFail($card->board_id);
    }

    private function list(Board $board, int $listId): TaskList
    {
        $list = TaskList::query()->whereKey($listId)->where('board_id', $board->id)->whereNull('archived_at')->first();
        abort_unless($list, 422, 'القائمة غير موجودة في هذه اللوحة.');

        return $list;
    }

    private function kindList(Board $board, string $kind): TaskList
    {
        return TaskList::where('board_id', $board->id)->where('kind', $kind)->firstOrFail();
    }

    private function firstNormal(Board $board): TaskList
    {
        return TaskList::where('board_id', $board->id)->where('kind', 'normal')->whereNull('archived_at')->orderBy('position')->firstOrFail();
    }

    private function returnList(Board $board, Card $card): TaskList
    {
        $previous = $card->previous_list_id ? TaskList::query()->whereKey($card->previous_list_id)->where('board_id', $board->id)
            ->where('kind', 'normal')->whereNull('archived_at')->first() : null;

        return $previous ?? $this->firstNormal($board);
    }

    private function lastCardId(TaskList $list): ?int
    {
        return Card::where('list_id', $list->id)->whereNull('archived_at')->orderByDesc('position')->value('id');
    }

    private function endOf(TaskList $list): float
    {
        return (float) Card::where('list_id', $list->id)->max('position') + BoardService::STEP;
    }

    /** Position after $afterCardId (null = top) in $list, ignoring the card being moved. */
    private function positionIn(TaskList $list, ?int $afterCardId, int $movingId): float
    {
        return $this->boards->slot(function () use ($list, $afterCardId, $movingId) {
            $cards = Card::where('list_id', $list->id)->whereNull('archived_at')->whereKeyNot($movingId);
            if ($afterCardId === null) {
                $first = (clone $cards)->min('position');

                return [null, $first === null ? null : (float) $first];
            }
            $before = (clone $cards)->whereKey($afterCardId)->value('position');
            abort_if($before === null, 422, 'البطاقة المرجعية ليست في هذه القائمة.');
            $after = (clone $cards)->where('position', '>', $before)->min('position');

            return [(float) $before, $after === null ? null : (float) $after];
        }, 'tsk_cards', ['list_id' => $list->id]);
    }
}
