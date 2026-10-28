<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Models\PendingDocumentCleanup;
use App\Modules\HR\Services\PendingDocumentCleanupService;
use Illuminate\Console\Command;

class ProcessPendingDocumentCleanups extends Command
{
    protected $signature = 'documents:process-cleanups';

    protected $description = 'Retry deletion of orphaned private employee document files';

    public function handle(PendingDocumentCleanupService $cleanups): int
    {
        $done = 0;
        $failed = 0;

        PendingDocumentCleanup::query()
            ->where(fn ($query) => $query->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->select('id')
            ->chunkById(100, function ($items) use ($cleanups, &$done, &$failed) {
                foreach ($items as $item) {
                    if ($cleanups->process($item->id)) {
                        $done++;
                    } else {
                        $failed++;
                    }
                }
            });

        $this->info("Cleaned {$done} files; {$failed} scheduled for retry.");

        return self::SUCCESS;
    }
}
