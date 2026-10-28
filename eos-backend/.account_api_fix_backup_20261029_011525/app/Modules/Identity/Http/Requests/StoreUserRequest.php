<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:usr_users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:usr_users,phone'],
            'password' => ['required', 'string', 'min:10'],
            'roles' => [
                'sometimes',
                'array',
                Rule::prohibitedIf(fn () => ! $this->user()?->hasRole('system_admin')),
            ],
            'roles.*' => ['string', 'distinct', 'exists:usr_roles,name'],
        ];
    }
}
