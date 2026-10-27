<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\JobTitle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class JobTitleService
{
    public function paginate(): LengthAwarePaginator
    {
        return JobTitle::orderBy('name')->paginate(20);
    }


    public function show(JobTitle $jobTitle): JobTitle
    {
        return $jobTitle;
    }
    public function create(array $data): JobTitle
    {
        return JobTitle::create($data);
    }

    public function update(JobTitle $jobTitle, array $data): JobTitle
    {
        $jobTitle->update($data);

        return $jobTitle->refresh();
    }

    public function delete(JobTitle $jobTitle): void
    {
        if ($jobTitle->employees()->exists()) {
            throw ValidationException::withMessages([
                'job_title' => ['لا يمكن حذف مسمى وظيفي مرتبط بموظفين.'],
            ]);
        }

        $jobTitle->delete();
    }
}