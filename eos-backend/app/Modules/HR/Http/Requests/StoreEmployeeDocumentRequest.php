<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'document_type' => ['sometimes', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Allowed file types for security; document_type itself remains extensible.
            'file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'xlsx'])->max('10mb')],
        ];
    }
}
