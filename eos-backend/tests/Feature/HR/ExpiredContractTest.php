<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Http\Resources\EmploymentContractResource;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class ExpiredContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-12-31 10:00:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function contract(): EmploymentContract
    {
        $employee = Employee::create([
            'employee_no' => 'U-11223344',
            'first_name' => 'Ahmad',
            'last_name' => 'Ali',
            'national_id' => '123456789012',
            'national_id_hash' => hash('sha256', '123456789012'),
            'hire_date' => '2026-01-01',
        ]);

        return EmploymentContract::create([
            'employee_id' => $employee->id,
            'contract_number' => 'CT-2026-001',
            'type' => 'fixed_term',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
    }

    public function test_contract_api_exposes_persisted_and_effective_status_separately(): void
    {
        $contract = $this->contract();
        Date::setTestNow('2027-01-01');

        $resource = new EmploymentContractResource($contract->fresh());
        $data = $resource->toArray(Request::create('/api/v1/test', 'GET'));
        $this->assertSame('active', $data['status']);
        $this->assertSame('expired', $data['effective_status']);

        Artisan::call('contracts:process-expired');
        $resource = new EmploymentContractResource($contract->fresh());
        $data = $resource->toArray(Request::create('/api/v1/test', 'GET'));
        $this->assertSame('expired', $data['status']);
        $this->assertSame('expired', $data['effective_status']);
    }

    public function test_contract_remains_active_on_its_end_date(): void
    {
        $contract = $this->contract();

        Artisan::call('contracts:process-expired');

        $this->assertSame('active', $contract->fresh()->status);
    }

    public function test_contract_expires_after_its_end_date(): void
    {
        $contract = $this->contract();

        Date::setTestNow('2027-01-01 00:10:00');

        Artisan::call('contracts:process-expired');

        $this->assertSame('expired', $contract->fresh()->status);
        $this->assertNull($contract->fresh()->terminated_at);
    }

    public function test_expiration_is_idempotent(): void
    {
        $contract = $this->contract();

        Date::setTestNow('2027-01-01');

        Artisan::call('contracts:process-expired');
        Artisan::call('contracts:process-expired');

        $this->assertSame('expired', $contract->fresh()->status);
    }

    public function test_indefinite_contract_does_not_expire(): void
    {
        $contract = $this->contract();

        $contract->update([
            'type' => 'indefinite',
            'end_date' => null,
        ]);

        Date::setTestNow('2027-01-01');

        Artisan::call('contracts:process-expired');

        $this->assertSame('active', $contract->fresh()->status);
    }

    public function test_due_scheduled_termination_is_not_expired_naturally(): void
    {
        $contract = $this->contract();

        $contract->update([
            'scheduled_termination_at' => '2026-11-30',
            'scheduled_termination_reason' => 'Administrative decision',
        ]);

        Date::setTestNow('2027-01-01');

        Artisan::call('contracts:process-expired');

        $this->assertSame('active', $contract->fresh()->status);

        Artisan::call('contracts:process-scheduled-terminations');

        $this->assertSame('terminated', $contract->fresh()->status);
        $this->assertSame(
            '2026-11-30',
            $contract->fresh()->terminated_at->format('Y-m-d')
        );
    }
}
