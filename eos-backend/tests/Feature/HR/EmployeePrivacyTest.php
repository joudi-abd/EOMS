<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Database\Seeders\RolePermissionSeeder;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function login(string $role): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_no' => 'U-12345678',
            'first_name' => 'Ahmad',
            'father_name' => 'Test Father',
            'last_name' => 'Ali',
            'national_id' => '1234567890',
            'national_id_hash' => hash('sha256', '1234567890'),
            'address' => 'Private address',
            'phone' => '0999999999',
            'sham_cash_wallet' => 'Private wallet',
            'hire_date' => '2026-10-01',
        ]);
    }

    public function test_project_manager_cannot_see_private_employee_information(): void
    {
        $employee = $this->employee();
        $this->login('project_manager');

        $this->getJson("/api/v1/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.sham_cash_wallet')
            ->assertJsonMissingPath('data.national_id');
    }

    public function test_hr_manager_sees_sensitive_but_not_financial_data(): void
    {
        $employee = $this->employee();
        $this->login('hr_manager');

        $this->getJson("/api/v1/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.address', 'Private address')
            ->assertJsonMissingPath('data.sham_cash_wallet');
    }

    public function test_creating_employee_does_not_audit_private_values(): void
    {
        $this->login('hr_manager');

        $this->postJson('/api/v1/employees', [
            'first_name' => 'Sara',
            'father_name' => 'Test Father',
            'last_name' => 'Omar',
            'national_id' => '123000456000',
            'address' => 'Confidential home',
            'sham_cash_wallet' => '0999876543',
            'hire_date' => '2026-10-01',
        ])->assertCreated();

        $log = AuditLog::where('action', 'employee.created')->firstOrFail();
        $this->assertArrayNotHasKey('address', $log->new_values ?? []);
        $this->assertArrayNotHasKey('sham_cash_wallet', $log->new_values ?? []);
        $this->assertArrayNotHasKey('national_id', $log->new_values ?? []);
    }
}
