<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentService
{
    public function __construct(private AuditLogger $audit) {}

    public function paginate(Employee $employee): LengthAwarePaginator
    {
        return $employee->documents()->latest('id')->paginate(20);
    }

    public function find(Employee $employee, EmployeeDocument $document): EmployeeDocument
    {
        abort_unless((int) $document->employee_id === (int) $employee->id, 404);

        return $document;
    }

    public function upload(Employee $employee, array $data, UploadedFile $file): EmployeeDocument
    {
        // Laravel's local disk is private; no storage:link or public URL is used.
        $path = $file->store('employee-documents/'.$employee->id, 'local');

        try {
            $document = $employee->documents()->create([
                'title' => $data['title'],
                'document_type' => $data['document_type'] ?? 'other',
                'description' => $data['description'] ?? null,
                'file_path' => $path,
                'original_name' => basename($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'file_size' => $file->getSize(),
                'uploaded_by_user_id' => auth()->id(),
            ]);
            $this->audit->log('employee.document_uploaded', $document, null, [
                'document_id' => $document->id,
                'employee_id' => $employee->id,
            ]);

            return $document;
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function download(Employee $employee, EmployeeDocument $document): BinaryFileResponse|StreamedResponse
    {
        $document = $this->find($employee, $document);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    public function delete(Employee $employee, EmployeeDocument $document): void
    {
        $document = $this->find($employee, $document);
        $path = $document->file_path;
        $this->audit->log('employee.document_deleted', $document, [
            'document_id' => $document->id,
            'employee_id' => $employee->id,
        ]);
        $document->delete();
        Storage::disk('local')->delete($path);
    }
}
