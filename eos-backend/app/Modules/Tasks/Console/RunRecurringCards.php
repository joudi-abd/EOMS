<?php

namespace App\Modules\Tasks\Console;

use App\Modules\Tasks\Services\RecurrenceService;
use Illuminate\Console\Command;

class RunRecurringCards extends Command
{
    protected $signature = 'tasks:recurring';

    protected $description = 'Create today\'s recurring task cards (working days only)';

    public function handle(RecurrenceService $recurrences): int
    {
        $this->info('Created '.$recurrences->run().' card(s).');

        return self::SUCCESS;
    }
}
