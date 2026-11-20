<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:191', Rule::unique('usr_users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('usr_users', 'phone')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:10'],
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
