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

        // Full-form clients may resend unchanged values while updating login settings.
        $attempted = array_filter($attempted, fn ($field) =>
            (string) $event->changes[$field] !== (string) $event->user->getAttribute($field));

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
                'الاسم والهاتف يؤخذان من ملف الموظف. لتغييرهما افتح ملف الموظف ضمن إدارة الموظفين.',
            ];
        }

        throw ValidationException::withMessages($errors);
    }
}
