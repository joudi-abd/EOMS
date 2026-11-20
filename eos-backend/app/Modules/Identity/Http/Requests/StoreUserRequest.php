<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /** Checked before validation, so a caller without the permission learns nothing from validation errors. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('users.manage');
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('ليست لديك صلاحية إدارة المستخدمين.');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'required', 'integer', 'exists:hr_employees,id'],
            'name' => ['required_without:employee_id', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:usr_users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:usr_users,phone'],
            'password' => ['required', 'string', 'min:10'],
            'roles' => [
                'sometimes',
                'array',
                // Giving roles needs roles.manage; UserService also keeps each role within the actor's own permissions.
                Rule::prohibitedIf(fn () => ! $this->user()?->can('roles.manage')),
            ],
            'roles.*' => ['string', 'distinct', 'exists:usr_roles,name'],
        ];
    }
}
