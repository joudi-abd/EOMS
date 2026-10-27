<?php

namespace App\Modules\HR\Listeners;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Events\UserProfileUpdating;
use Illuminate\Validation\ValidationException;

class ProtectEmployeeAccountProfile
{
    public function handle(UserProfileUpdating $event): void
    {
        $protectedFields = ['name', 'phone'];

        $attempted = array_intersect(
            $protectedFields,
            array_keys($event->changes)
        );

        if (empty($attempted)) {
            return;
        }

        $hasEmployee = Employee::query()
            ->where('user_id', $event->user->id)
            ->exists();

        if (! $hasEmployee) {
            return;
        }

        $errors = [];

        foreach ($attempted as $field) {
            $errors[$field] = [
                'يجب تعديل هذا الحقل من ملف الموظف في HR.',
            ];
        }

        throw ValidationException::withMessages($errors);
    }
}
