<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Services\Advances\CustodyService;
use Illuminate\Console\Command;

/** R17: notifies finance about open custodies older than `finance.custody_overdue_days` (once per custody per day). */
class NotifyOverdueCustodies extends Command
{
    protected $signature = 'finance:custody-overdue';

    protected $description = 'Notify finance about open custodies older than the overdue setting (once per custody per day)';

    public function handle(CustodyService $custodies): int
    {
        $this->info('Overdue custodies notified: '.$custodies->notifyOverdue().'.');

        return self::SUCCESS;
    }
}
