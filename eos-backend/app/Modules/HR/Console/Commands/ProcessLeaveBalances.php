<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\LeaveWorkflowService;
use Illuminate\Console\Command;

class ProcessLeaveBalances extends Command
{
    protected $signature = 'leaves:process';
    protected $description = 'Grant due leave balances and expire pending requests';

    public function handle(LeaveWorkflowService $service): int
    {
        $failed = false;
        Employee::query()->chunkById(100, function ($employees) use ($service, &$failed) {
            foreach ($employees as $employee) {
                try {
                    $service->maintain($employee);
                } catch (\Throwable $error) {
                    report($error);
                    $this->error('Leave maintenance failed for employee '.$employee->id);
                    $failed = true;
                }
            }
        });
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
