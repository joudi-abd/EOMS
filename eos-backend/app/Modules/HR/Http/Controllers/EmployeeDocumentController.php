<?php

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Http\Requests\StoreEmployeeDocumentRequest;
use App\Modules\HR\Http\Resources\EmployeeDocumentResource;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Services\EmployeeDocumentService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Routing\Controller;

#[Group('05 - HR / Employees', weight: 5)]
class EmployeeDocumentController extends Controller
{
    public function __construct(private EmployeeDocumentService $documents) {}

    /** List employee documents (requires employees.documents.view). */
    public function index(Employee $employee)
    {
        return ApiResponse::paginated(
            $this->documents->paginate($employee)
                ->through(fn (EmployeeDocument $document) => new EmployeeDocumentResource($document))
        );
    }

    /** Upload private document (multipart/form-data). */
    public function store(StoreEmployeeDocumentRequest $request, Employee $employee)
    {
        return ApiResponse::created(new EmployeeDocumentResource(
            $this->documents->upload($employee, $request->safe()->except(['file']), $request->file('file'))
        ));
    }

    /** Download a private document after authorization. */
    public function download(Employee $employee, EmployeeDocument $document)
    {
        return $this->documents->download($employee, $document);
    }

    /** Remove employee document and its private file. */
    public function destroy(Employee $employee, EmployeeDocument $document)
    {
        $this->documents->delete($employee, $document);

        return ApiResponse::message('تم حذف الوثيقة.');
    }
}
