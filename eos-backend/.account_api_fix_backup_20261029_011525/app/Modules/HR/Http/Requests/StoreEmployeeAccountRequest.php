<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:usr_users,email'],
            'password' => ['required', 'string', 'min:10'],
            'roles' => ['prohibited'],
            'status' => ['prohibited'],
            'user_id' => ['prohibited'],
            // Names and phone belong to the employee profile; reused UI should display them read-only.
            'name' => ['prohibited'],
            'phone' => ['prohibited'],
        ];
    }
}
