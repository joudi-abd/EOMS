<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PendingDocumentCleanup;
use App\Modules\HR\Services\EmployeeDocumentService;
use App\Modules\HR\Services\PendingDocumentCleanupService;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeDocumentsTest extends TestCase
{
    // Exercise real commits so afterCommit callbacks run as in production.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_no' => 'U-44556677', 'first_name' => 'Ali', 'father_name' => 'Omar',
            'last_name' => 'Hassan', 'national_id' => '3344556677',
            'national_id_hash' => hash('sha256', '3344556677'), 'hire_date' => '2026-01-01',
        ]);
    }

    private function login(string $role): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);
    }

    public function test_hr_manager_can_upload_and_download_private_document(): void
    {
        $employee = $this->employee();
        $this->login('hr_manager');
        $this->post("/api/v1/employees/{$employee->id}/documents", [
            'title' => 'Certificate',
            'document_type' => 'certificate',
            'file' => UploadedFile::fake()->create('certificate.pdf', 5, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('data.file_path');

        $this->assertDatabaseCount('hr_employee_documents', 1);
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
        $document = $employee->documents()->firstOrFail();
        Storage::disk('local')->assertExists($document->file_path);
        $this->get("/api/v1/employees/{$employee->id}/documents/{$document->id}/download")->assertOk();
    }

    public function test_document_upload_rolls_back_database_when_audit_fails(): void
    {
        $employee = $this->employee();
        $this->login('hr_manager');

        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->post("/api/v1/employees/{$employee->id}/documents", [
            'title' => 'Contract',
            'file' => UploadedFile::fake()->create('contract.pdf', 5, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertDatabaseCount('hr_employee_documents', 0);
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('employee-documents/'.$employee->id));
    }

    public function test_document_of_another_employee_cannot_be_downloaded(): void
    {
        $owner = $this->employee();
        $other = Employee::create([
            'employee_no' => 'U-99887766', 'first_name' => 'Omar',
            'father_name' => 'Ali', 'last_name' => 'Salem',
            'national_id' => '9988776655',
            'national_id_hash' => hash('sha256', '9988776655'),
            'hire_date' => '2026-01-01',
        ]);
        $this->login('hr_manager');
        $this->post("/api/v1/employees/{$owner->id}/documents", [
            'title' => 'Private certificate',
            'file' => UploadedFile::fake()->create('certificate.pdf', 5, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = $owner->documents()->firstOrFail();
        $this->getJson("/api/v1/employees/{$other->id}/documents/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_successful_delete_removes_document_and_cleanup_record(): void
    {
        $employee = $this->employee();
        $this->login('hr_manager');
        $this->post('/api/v1/employees/'.$employee->id.'/documents', [
            'title' => 'Certificate',
            'file' => UploadedFile::fake()->create('certificate.pdf', 5, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = $employee->documents()->firstOrFail();
        $path = $document->file_path;
        $this->deleteJson('/api/v1/employees/'.$employee->id.'/documents/'.$document->id)->assertOk();

        $this->assertDatabaseMissing('hr_employee_documents', ['id' => $document->id]);
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_failed_disk_cleanup_is_retained_and_can_be_retried(): void
    {
        $employee = $this->employee();
        $this->login('hr_manager');
        $this->post('/api/v1/employees/'.$employee->id.'/documents', [
            'title' => 'Certificate',
            'file' => UploadedFile::fake()->create('certificate.pdf', 5, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = $employee->documents()->firstOrFail();
        $path = $document->file_path;
        $cleanup = \Mockery::mock(PendingDocumentCleanupService::class)->makePartial();
        $cleanup->shouldReceive('process')->once()->andReturn(false);
        $this->app->instance(PendingDocumentCleanupService::class, $cleanup);

        $this->deleteJson('/api/v1/employees/'.$employee->id.'/documents/'.$document->id)->assertOk();
        $this->assertDatabaseMissing('hr_employee_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('hr_pending_document_cleanups', ['file_path' => $path]);
        Storage::disk('local')->assertExists($path);

        // Process with the real cleanup service after storage recovers.
        $this->app->forgetInstance(PendingDocumentCleanupService::class);
        $this->artisan('documents:process-cleanups')->assertExitCode(0);
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_employee_without_document_permission_cannot_read_documents(): void
    {
        $employee = $this->employee();
        $this->login('employee');
        $this->getJson("/api/v1/employees/{$employee->id}/documents")->assertForbidden();
    }

    private function uploadedDocument(): array
    {
        $employee = $this->employee();
        $this->login('hr_manager');
        $this->post('/api/v1/employees/'.$employee->id.'/documents', [
            'title' => 'Certificate',
            'file' => UploadedFile::fake()->create('certificate.pdf', 5, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        return [$employee, $employee->documents()->firstOrFail()];
    }

    public function test_delete_waits_for_outer_commit(): void
    {
        [$employee, $document] = $this->uploadedDocument();
        DB::beginTransaction();
        try {
            app(EmployeeDocumentService::class)->delete($employee, $document);
            Storage::disk('local')->assertExists($document->file_path);
            $this->assertDatabaseCount('hr_pending_document_cleanups', 1);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        Storage::disk('local')->assertMissing($document->file_path);
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
        $this->assertDatabaseMissing('hr_employee_documents', ['id' => $document->id]);
    }

    public function test_outer_rollback_restores_document_and_preserves_file(): void
    {
        [$employee, $document] = $this->uploadedDocument();
        DB::beginTransaction();
        try {
            app(EmployeeDocumentService::class)->delete($employee, $document);
        } finally {
            DB::rollBack();
        }
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertDatabaseHas('hr_employee_documents', ['id' => $document->id]);
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
    }

    public function test_failed_delete_audit_preserves_document_file_and_has_no_cleanup(): void
    {
        [$employee, $document] = $this->uploadedDocument();
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->deleteJson('/api/v1/employees/'.$employee->id.'/documents/'.$document->id)
            ->assertStatus(500);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertDatabaseHas('hr_employee_documents', ['id' => $document->id]);
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
    }

    public function test_failed_immediate_cleanup_retains_intent_and_original_upload_error(): void
    {
        $employee = $this->employee();
        $this->login('hr_manager');
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Original audit failure'));
        $cleanup = \Mockery::mock(PendingDocumentCleanupService::class);
        $cleanup->shouldReceive('process')->once()->andThrow(new \RuntimeException('Cleanup database down'));
        $this->app->instance(AuditLogger::class, $audit);
        $this->app->instance(PendingDocumentCleanupService::class, $cleanup);
        Log::shouldReceive('critical')->once()->withArgs(function ($message, $context) {
            return $message === 'Employee document immediate cleanup unavailable; durable intent retained'
                && isset($context['cleanup_id']);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Original audit failure');
        try {
            app(EmployeeDocumentService::class)->upload($employee, ['title' => 'Certificate'],
                UploadedFile::fake()->create('certificate.pdf', 5, 'application/pdf'));
        } finally {
            $this->assertDatabaseCount('hr_employee_documents', 0);
            $this->assertDatabaseCount('hr_pending_document_cleanups', 1);
        }
    }

    public function test_upload_in_outer_transaction_is_rejected_before_storing_file(): void
    {
        $employee = $this->employee();
        DB::beginTransaction();
        try {
            try {
                app(EmployeeDocumentService::class)->upload($employee, ['title' => 'Certificate'],
                    UploadedFile::fake()->create('certificate.pdf', 5, 'application/pdf'));
                $this->fail('Upload must refuse an outer transaction.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('existing transaction', $e->getMessage());
            }
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('hr_employee_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('employee-documents'));
    }

    public function test_upload_does_not_write_file_when_intent_cannot_be_saved(): void
    {
        $employee = $this->employee();
        PendingDocumentCleanup::creating(function () {
            throw new \RuntimeException('Intent database unavailable');
        });
        try {
            try {
                app(EmployeeDocumentService::class)->upload($employee, ['title' => 'Certificate'],
                    UploadedFile::fake()->create('certificate.pdf', 5, 'application/pdf'));
                $this->fail('Upload must stop before storing bytes.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Intent database unavailable', $e->getMessage());
            }
        } finally {
            PendingDocumentCleanup::getEventDispatcher()->forget('eloquent.creating: '.PendingDocumentCleanup::class);
        }
        $this->assertDatabaseCount('hr_employee_documents', 0);
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('employee-documents'));
    }

    public function test_upload_has_durable_intent_before_document_is_committed(): void
    {
        $employee = $this->employee();
        $this->login('hr_manager');
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andReturnUsing(function () {
            $pending = PendingDocumentCleanup::query()->sole();
            Storage::disk('local')->assertExists($pending->file_path);
            $this->assertTrue($pending->next_attempt_at->isFuture());

            return new \App\Modules\Identity\Models\AuditLog;
        });
        $this->app->instance(AuditLogger::class, $audit);
        app(EmployeeDocumentService::class)->upload($employee, ['title' => 'Certificate'],
            UploadedFile::fake()->create('certificate.pdf', 5, 'application/pdf'));
        $this->assertDatabaseCount('hr_employee_documents', 1);
        $this->assertDatabaseCount('hr_pending_document_cleanups', 0);
    }
}
