<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    private bool $includeOwnEmployee = false;

    public function withOwnEmployee(): static
    {
        $this->includeOwnEmployee = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $self = $this->includeOwnEmployee;
        $employee = $self ? Employee::query()->where('user_id', $this->id)
            ->with(['department:id,name', 'jobTitle:id,name'])->first() : null;
        $employeeId = $self ? $employee?->id : Employee::where('user_id', $this->id)->value('id');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'roles' => $this->getRoleNames(),
            'employee_id' => $employeeId === null ? null : (int) $employeeId,
            'employee' => $this->when($self, fn () => $employee ? new EmployeeResource($employee) : null),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
