<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\PendingDocumentCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PendingDocumentCleanupService
{
    /**
     * Delete the file if possible. Otherwise, record a durable retry.
     * Used for orphan files after an upload transaction fails.
     */
    public function removeOrQueue(string $path): void
    {
        if (! $this->deleteFile('local', $path)) {
            PendingDocumentCleanup::query()->create([
                'disk' => 'local',
                'file_path' => $path,
            ]);
        }
    }

    public function process(int $id): bool
    {
        // Never perform irreversible I/O under a caller-owned transaction.
        if (DB::transactionLevel() !== 0) {
            return false;
        }

        try {
            // Serialize concurrent workers and keep the cleanup row until deletion succeeds.
            return DB::transaction(function () use ($id): bool {
                $pending = PendingDocumentCleanup::query()->lockForUpdate()->find($id);
                if ($pending === null) {
                    return true;
                }

                // Another worker may have deferred this row while we waited.
                if ($pending->next_attempt_at?->isFuture()) {
                    return false;
                }

                if (! $this->deleteFile($pending->disk, $pending->file_path)) {
                    $pending->increment('attempts');
                    $pending->update([
                        'next_attempt_at' => now()->addMinutes(min(60, 2 ** min(6, $pending->attempts))),
                        'last_error' => 'Disk file deletion failed; retry scheduled.',
                    ]);
                    Log::warning('Document file cleanup deferred', ['cleanup_id' => $id]);

                    return false;
                }

                $pending->delete();

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('Document cleanup attempt crashed', [
                'cleanup_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function deleteFile(string $disk, string $path): bool
    {
        if ($disk !== 'local' || ! $this->isSafePath($path)) {
            Log::error('Unsafe document cleanup target refused', ['disk' => $disk]);

            return false;
        }

        try {
            $storage = Storage::disk($disk);

            // Nonexistent file is already clean (idempotent operation).
            return ! $storage->exists($path) || $storage->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Employee document file cleanup unavailable', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function isSafePath(string $path): bool
    {
        if (! str_starts_with($path, 'employee-documents/')
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $path)) {
            return false;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }
}
