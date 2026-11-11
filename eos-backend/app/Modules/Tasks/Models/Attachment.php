<?php

namespace App\Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $table = 'tsk_attachments';

    protected $fillable = ['card_id', 'path', 'original_name', 'mime', 'size', 'uploaded_by'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['card_id' => 'integer', 'size' => 'integer', 'uploaded_by' => 'integer'];
    }
}
