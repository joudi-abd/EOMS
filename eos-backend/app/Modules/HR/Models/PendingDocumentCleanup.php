<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class PendingDocumentCleanup extends Model
{
    protected $table = 'hr_pending_document_cleanups';

    protected $fillable = ['disk', 'file_path', 'attempts', 'next_attempt_at', 'last_error'];

    protected $hidden = ['file_path', 'last_error'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'attempts' => 'integer'];
    }
}
