<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Models\EmploymentContract;
use App\Modules\HR\Services\EmploymentContractService;
use Illuminate\Console\Command;

class ProcessExpiredContracts extends Command
{
    protected $signature = 'contracts:process-expired';

    protected $description = 'Expire employment contracts after their end date';

    public function handle(EmploymentContractService $contracts): int
    {
        $processed = 0;

        EmploymentContract::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', today()->toDateString())
            ->select('id')
            ->chunkById(100, function ($items) use ($contracts, &$processed) {
                foreach ($items as $contract) {
                    if ($contracts->expireContract($contract->id)) {
                        $processed++;
                    }
                }
            });

        $this->info(
            "Processed {$processed} expired employment contracts."
        );

        return self::SUCCESS;
    }
}
