<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Services\JobTitleService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JobTitleController extends Controller
{
    public function __construct(private JobTitleService $jobTitles) {}

    public function index()
    {
        return ApiResponse::paginated($this->jobTitles->paginate());
    }

        public function show(JobTitle $jobTitle)
    {
        return ApiResponse::ok($this->jobTitles->show($jobTitle));
    }
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:hr_job_titles,name'],
        ]);

        return ApiResponse::created($this->jobTitles->create($data));
    }

    public function update(Request $request, JobTitle $jobTitle)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:hr_job_titles,name,'.$jobTitle->id],
        ]);

        return ApiResponse::ok($this->jobTitles->update($jobTitle, $data));
    }

    public function destroy(JobTitle $jobTitle)
    {
        $this->jobTitles->delete($jobTitle);

        return ApiResponse::message('تم حذف المسمى الوظيفي.');
    }
}