<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\TaskList;
use Illuminate\Support\Facades\DB;

/**
 * Due-date reminders for assignees: one the working day before the due date ("soon") and one once it has passed
 * ("overdue"). Each is sent once per due date; changing the date re-arms them. Cards already in "done" or
 * "finished", archived cards and archived boards are left alone.
 */
class ReminderService
{
    public function __construct(private WorkCalendar $calendar, private TaskNotifier $notifier) {}

    public function run(): array
    {
        $today = $this->calendar->today();
        $soon = $this->calendar->nextWorkingDay($today);
        $closed = TaskList::whereIn('kind', ['done', 'finished'])->select('id');
        $base = fn () => Card::query()->whereNull('archived_at')->whereNotNull('due_date')->whereNotIn('list_id', $closed)
            ->whereIn('board_id', Board::whereNull('archived_at')->select('id'));
        $sent = ['soon' => 0, 'overdue' => 0];

        foreach ($base()->whereDate('due_date', '>=', $today)->whereDate('due_date', '<=', $soon)
            ->where(fn ($q) => $q->whereNull('reminded_soon_for')->orWhereColumn('reminded_soon_for', '!=', 'due_date'))->get() as $card) {
            $sent['soon'] += $this->remind($card, 'reminded_soon_for', 'tasks.card_due_soon', 'موعد استحقاق البطاقة '.$card->due_date->format('Y-m-d'));
        }
        foreach ($base()->whereDate('due_date', '<', $today)
            ->where(fn ($q) => $q->whereNull('reminded_overdue_for')->orWhereColumn('reminded_overdue_for', '!=', 'due_date'))->get() as $card) {
            $sent['overdue'] += $this->remind($card, 'reminded_overdue_for', 'tasks.card_overdue', 'تجاوزت البطاقة موعد استحقاقها '.$card->due_date->format('Y-m-d'));
        }

        return $sent;
    }

    private function remind(Card $card, string $column, string $kind, string $message): int
    {
        return DB::transaction(function () use ($card, $column, $kind, $message) {
            $card = Card::query()->lockForUpdate()->find($card->id);
            $due = $card?->due_date?->format('Y-m-d');
            if (! $card || $due === null || $card->{$column}?->format('Y-m-d') === $due) {
                return 0;
            }
            $card->update([$column => $due]);
            $assignees = DB::table('tsk_card_assignees')->where('card_id', $card->id)->pluck('user_id')->all();
            $this->notifier->aboutCard($assignees, $kind, $message, $card);

            return 1;
        }, 3);
    }
}
