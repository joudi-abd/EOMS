<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Recurrence;
use App\Modules\Tasks\Models\TaskList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repeating cards: on each scheduled day a new card is created from the template at the start of the working day.
 * A scheduled day that is not a working day (weekly rest day or public holiday) is skipped, not moved.
 */
class RecurrenceService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private BoardAccess $access, private CardService $cards, private WorkCalendar $calendar, private ActivityLog $activity) {}

    public function save(Board $board, ?Recurrence $recurrence, array $data, User $actor): Recurrence
    {
        $this->cards->writable($actor, $board, 'member');
        $list = TaskList::query()->whereKey($data['list_id'])->where('board_id', $board->id)->where('kind', 'normal')->whereNull('archived_at')->first();
        abort_unless($list, 422, 'اختر قائمة عادية من هذه اللوحة.');
        $workers = $this->access->workers($board);
        abort_if(array_diff(array_map('intval', $data['template']['assignee_ids'] ?? []), $workers) !== [], 422, 'يُكلَّف أعضاء اللوحة فقط.');

        return DB::transaction(function () use ($board, $recurrence, $data, $actor) {
            $values = [
                'list_id' => (int) $data['list_id'], 'template' => $data['template'], 'frequency' => $data['frequency'],
                'weekdays' => $data['frequency'] === 'weekly' ? array_values(array_unique(array_map('intval', $data['weekdays']))) : null,
                'month_day' => $data['frequency'] === 'monthly' ? (int) $data['month_day'] : null,
                'due_in_days' => $data['due_in_days'] ?? null, 'active' => $data['active'] ?? true,
            ];
            $start = $data['starts_on'] ?? $this->calendar->today();
            $values['next_run_on'] = $this->firstOnOrAfter($values, $start);
            $recurrence = $recurrence
                ? tap($recurrence)->update($values)
                : Recurrence::create($values + ['board_id' => $board->id, 'created_by' => $actor->id]);
            $this->activity->record($board, null, $actor, 'recurrence.saved', ['recurrence_id' => $recurrence->id]);

            return $recurrence;
        }, self::TX_ATTEMPTS);
    }

    public function stop(Recurrence $recurrence, User $actor): void
    {
        $board = Board::findOrFail($recurrence->board_id);
        $this->cards->writable($actor, $board, 'member');
        $recurrence->update(['active' => false]);
        $this->activity->record($board, null, $actor, 'recurrence.stopped', ['recurrence_id' => $recurrence->id]);
    }

    /** Creates today's cards. Safe to run more than once a day: next_run_on moves past today once a card exists. */
    public function run(?string $today = null): int
    {
        if ($today === null && ! $this->calendar->workdayStarted()) {
            return 0;
        }
        $today ??= $this->calendar->today();
        $created = 0;
        $due = Recurrence::query()->where('active', true)->whereDate('next_run_on', '<=', $today)->orderBy('id')->pluck('id');
        foreach ($due as $id) {
            try {
                $created += DB::transaction(function () use ($id, $today) {
                    $recurrence = Recurrence::query()->lockForUpdate()->find($id);
                    if (! $recurrence || ! $recurrence->active || $recurrence->next_run_on->format('Y-m-d') > $today) {
                        return 0;
                    }
                    $board = Board::find($recurrence->board_id);
                    $made = 0;
                    $runDay = $recurrence->next_run_on->format('Y-m-d');
                    // Missed days (server down) are not back-filled: only today's occurrence is created.
                    if ($board && $board->archived_at === null && $runDay === $today && $this->calendar->isWorkingDay($today)) {
                        $this->createCard($board, $recurrence, $today);
                        $made = 1;
                    }
                    $recurrence->update(['last_run_on' => $made ? $today : $recurrence->last_run_on,
                        'next_run_on' => $this->firstOnOrAfter($recurrence->only(['frequency', 'weekdays', 'month_day']),
                            CarbonImmutable::parse($today)->addDay()->toDateString())]);

                    return $made;
                }, self::TX_ATTEMPTS);
            } catch (\Throwable $e) {
                Log::warning('tasks: recurring card failed', ['recurrence_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $created;
    }

    private function createCard(Board $board, Recurrence $recurrence, string $today): Card
    {
        $template = $recurrence->template;
        $creator = User::findOrFail($recurrence->created_by);
        $list = TaskList::query()->whereKey($recurrence->list_id)->whereNull('archived_at')->first()
            ?? TaskList::where('board_id', $board->id)->where('kind', 'normal')->whereNull('archived_at')->orderBy('position')->firstOrFail();
        // Only people who can still work on the board are assigned.
        $assignees = array_values(array_intersect(array_map('intval', $template['assignee_ids'] ?? []), $this->access->workers($board)));
        $labels = array_values(array_intersect(array_map('intval', $template['label_ids'] ?? []), $board->labels()->pluck('id')->all()));

        return $this->cards->createSystem($board, [
            'list_id' => $list->id, 'title' => $template['title'], 'description' => $template['description'] ?? null,
            'priority' => $template['priority'] ?? 'normal', 'start_date' => $today,
            'due_date' => $recurrence->due_in_days === null ? null : $this->calendar->addWorkingDays($today, $recurrence->due_in_days),
            'assignee_ids' => $assignees, 'label_ids' => $labels, 'checklist' => $template['checklist'] ?? [],
            'recurrence_id' => $recurrence->id,
        ], $creator);
    }

    /** The first scheduled day on or after $date (not checked against holidays; run() skips those). */
    private function firstOnOrAfter(array $rule, string $date): string
    {
        $day = CarbonImmutable::parse($date);
        for ($i = 0; $i < 400; $i++) {
            $hit = match ($rule['frequency']) {
                'daily' => true,
                'weekly' => in_array($day->dayOfWeek, $rule['weekdays'] ?? [], true),
                'monthly' => $day->day === min((int) $rule['month_day'], $day->daysInMonth),
                default => false,
            };
            if ($hit) {
                return $day->toDateString();
            }
            $day = $day->addDay();
        }

        return $day->toDateString();
    }
}
