<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Contracts\EmployeeDirectory;
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
        $directory = app(EmployeeDirectory::class);
        $employeeId = $directory->employeeIdForUser($this->id);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'roles' => $this->getRoleNames(),
            'employee_id' => $employeeId,
            'employee' => $this->when($self, fn () => $directory->profileForUser($this->id)),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
