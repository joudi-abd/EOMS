<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Models\EmploymentContract;
use App\Modules\HR\Services\EmploymentContractService;
use Illuminate\Console\Command;

class ProcessScheduledContractTerminations extends Command
{
    protected $signature = 'contracts:process-scheduled-terminations';

    protected $description = 'Execute due scheduled employment contract terminations';

    public function handle(EmploymentContractService $contracts): int
    {
        $processed = 0;

        EmploymentContract::query()
            ->where('status', 'active')
            ->whereNotNull('scheduled_termination_at')
            ->whereDate('scheduled_termination_at', '<=', today()->toDateString())
            ->select(['id'])
            ->chunkById(100, function ($items) use ($contracts, &$processed) {
                foreach ($items as $contract) {
                    if ($contracts->executeScheduledTermination($contract->id)) {
                        $processed++;
                    }
                }
            });

        $this->info("Processed {$processed} scheduled contract terminations.");

        return self::SUCCESS;
    }
}
