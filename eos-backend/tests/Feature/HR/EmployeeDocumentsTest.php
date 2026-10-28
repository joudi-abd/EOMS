<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\User;
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

    public function test_employee_without_document_permission_cannot_read_documents(): void
    {
        $employee = $this->employee();
        $this->login('employee');
        $this->getJson("/api/v1/employees/{$employee->id}/documents")->assertForbidden();
    }
}
