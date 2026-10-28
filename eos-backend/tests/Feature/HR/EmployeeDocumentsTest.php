<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeDocumentsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_employee_without_document_permission_cannot_read_documents(): void
    {
        $employee = $this->employee();
        $this->login('employee');
        $this->getJson("/api/v1/employees/{$employee->id}/documents")->assertForbidden();
    }
}
