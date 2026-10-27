<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Services\JobTitleService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group('04 - HR / Job Titles', weight: 4)]
class JobTitleController extends Controller
{
    public function __construct(private JobTitleService $jobTitles) {}

    /**
     * List job titles.
     *
     * Returns a paginated job title list.
     * Supports searching and filtering.
     */
    public function index()
    {
        return ApiResponse::paginated($this->jobTitles->paginate());
    }

    /**
     * Show job title details.
     *
     * Returns the job title profile.
     */
    public function show(JobTitle $jobTitle)
    {
        return ApiResponse::ok($this->jobTitles->show($jobTitle));
    }

    /**
     * Create a job title.
     *
     * Creates a job title record.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:hr_job_titles,name'],
        ]);

        return ApiResponse::created($this->jobTitles->create($data));
    }

    /**
     * Update job title information.
     *
     * Updates the job title profile.
     */
    public function update(Request $request, JobTitle $jobTitle)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:hr_job_titles,name,'.$jobTitle->id],
        ]);

        return ApiResponse::ok($this->jobTitles->update($jobTitle, $data));
    }

    /**
     * Delete a job title.
     *
     * Deletes the job title record if it has no
     * associated employees.
     */
    public function destroy(JobTitle $jobTitle)
    {
        $this->jobTitles->delete($jobTitle);

        return ApiResponse::message('تم حذف المسمى الوظيفي.');
    }
}
