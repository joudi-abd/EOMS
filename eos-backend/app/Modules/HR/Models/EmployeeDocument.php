<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    protected $table = 'hr_employee_documents';

    protected $fillable = [
        'employee_id', 'title', 'document_type', 'file_path',
        'original_name', 'mime_type', 'file_size', 'description', 'uploaded_by_user_id',
    ];

    protected $hidden = ['file_path'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
