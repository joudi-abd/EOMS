<?php

namespace App\Modules\Tasks\Console;

use App\Modules\Tasks\Services\ReminderService;
use Illuminate\Console\Command;

class SendDueReminders extends Command
{
    protected $signature = 'tasks:reminders';

    protected $description = 'Notify assignees of cards due the next working day or overdue (once per due date)';

    public function handle(ReminderService $reminders): int
    {
        $sent = $reminders->run();
        $this->info("Due soon: {$sent['soon']}, overdue: {$sent['overdue']}.");

        return self::SUCCESS;
    }
}
