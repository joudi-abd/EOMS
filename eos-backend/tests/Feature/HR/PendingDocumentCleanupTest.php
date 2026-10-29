<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\PendingDocumentCleanup;
use App\Modules\HR\Services\PendingDocumentCleanupService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PendingDocumentCleanupTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->startOfSecond());
    }

    private function pending(array $attributes = []): PendingDocumentCleanup
    {
        return PendingDocumentCleanup::create(array_merge([
            'disk' => 'local',
            'file_path' => 'employee-documents/1/certificate.pdf',
        ], $attributes));
    }

    private function failingDisk(bool $throws = false): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with('employee-documents/1/certificate.pdf')->andReturnTrue();
        $expectation = $disk->shouldReceive('delete')->with('employee-documents/1/certificate.pdf');
        if ($throws) {
            $expectation->andThrow(new \RuntimeException('Storage unavailable'));
        } else {
            $expectation->andReturnFalse();
        }
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    }

    public function test_real_storage_failure_is_deferred_then_recovered(): void
    {
        $pending = $this->pending();
        $manager = Storage::getFacadeRoot();
        $disk = Storage::disk('local');
        $disk->put($pending->file_path, 'private content');
        $this->failingDisk();

        $service = app(PendingDocumentCleanupService::class);
        $this->assertFalse($service->process($pending->id));
        $pending->refresh();
        $this->assertSame(1, $pending->attempts);
        $this->assertTrue($pending->next_attempt_at->equalTo(now()->addMinutes(2)));
        $this->assertNotNull($pending->last_error);
        $disk->assertExists($pending->file_path);

        Storage::swap($manager);
        $this->artisan('documents:process-cleanups')->assertSuccessful();
        $this->assertDatabaseHas($pending->getTable(), ['id' => $pending->id, 'attempts' => 1]);
        $this->travel(2)->minutes();
        $this->artisan('documents:process-cleanups')->assertSuccessful();
        $this->assertDatabaseMissing($pending->getTable(), ['id' => $pending->id]);
        $disk->assertMissing($pending->file_path);
    }

    public function test_storage_exception_keeps_cleanup_and_schedules_retry(): void
    {
        $pending = $this->pending();
        $this->failingDisk(true);
        $this->assertFalse(app(PendingDocumentCleanupService::class)->process($pending->id));
        $this->assertSame(1, $pending->fresh()->attempts);
        $this->assertTrue($pending->fresh()->next_attempt_at->isFuture());
    }

    public function test_exists_exception_does_not_discard_cleanup(): void
    {
        $pending = $this->pending();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andThrow(new \RuntimeException('Storage unavailable'));
        $disk->shouldNotReceive('delete');
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->assertFalse(app(PendingDocumentCleanupService::class)->process($pending->id));
        $this->assertSame(1, $pending->fresh()->attempts);
    }

    public function test_repeated_failures_back_off_to_sixty_minutes(): void
    {
        $pending = $this->pending();
        $this->failingDisk();
        foreach ([2, 4, 8, 16, 32, 60, 60] as $index => $minutes) {
            $this->assertFalse(app(PendingDocumentCleanupService::class)->process($pending->id));
            $pending->refresh();
            $this->assertSame($index + 1, $pending->attempts);
            $this->assertTrue($pending->next_attempt_at->equalTo(now()->addMinutes($minutes)));
            $this->travel($minutes)->minutes();
        }
    }

    public function test_future_attempt_is_not_processed_even_when_called_directly(): void
    {
        $pending = $this->pending(['next_attempt_at' => now()->addHour()]);
        Storage::shouldReceive('disk')->never();
        $this->assertFalse(app(PendingDocumentCleanupService::class)->process($pending->id));
        $this->assertSame(0, $pending->fresh()->attempts);
    }

    public function test_missing_file_and_repeated_processing_are_idempotent(): void
    {
        $pending = $this->pending();
        $service = app(PendingDocumentCleanupService::class);
        $this->assertTrue($service->process($pending->id));
        $this->assertTrue($service->process($pending->id));
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
    }

    public function test_unsafe_targets_never_reach_storage(): void
    {
        Storage::shouldReceive('disk')->never();
        foreach (['employee-documents/../secret.pdf', 'employee-documents/1/../../secret.pdf',
            'employee-documents/1/./file.pdf', 'employee-documents//file.pdf',
            'employee-documents/1\\file.pdf', 'employee-documents/', '/etc/passwd',
            "employee-documents/1/evil\nfile.pdf"] as $path) {
            $pending = $this->pending(['file_path' => $path]);
            $this->assertFalse(app(PendingDocumentCleanupService::class)->process($pending->id));
            $this->assertNotNull($pending->fresh());
        }
        $pending = $this->pending(['disk' => 'public']);
        $this->assertFalse(app(PendingDocumentCleanupService::class)->process($pending->id));
    }

    public function test_process_inside_outer_transaction_never_deletes_file(): void
    {
        $pending = $this->pending();
        Storage::disk('local')->put($pending->file_path, 'private');
        DB::beginTransaction();
        try {
            $this->assertFalse(app(PendingDocumentCleanupService::class)->process($pending->id));
        } finally {
            DB::rollBack();
        }
        Storage::disk('local')->assertExists($pending->file_path);
        $this->assertNotNull($pending->fresh());
    }

    public function test_failed_upload_compensation_is_queued(): void
    {
        $this->failingDisk();
        app(PendingDocumentCleanupService::class)->removeOrQueue('employee-documents/1/certificate.pdf');
        $this->assertDatabaseHas('hr_pending_document_cleanups', [
            'disk' => 'local', 'file_path' => 'employee-documents/1/certificate.pdf',
        ]);
    }

    public function test_command_processes_more_than_one_chunk_without_skipping_rows(): void
    {
        for ($i = 0; $i < 105; $i++) {
            $this->pending(['file_path' => 'employee-documents/1/'.$i.'.pdf']);
        }
        $this->artisan('documents:process-cleanups')->assertSuccessful();
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
    }
}
