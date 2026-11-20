<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A scan or photo attached to any finance record (voucher, cash count, site request, asset). */
class Attachment extends Model
{
    protected $hidden = ['path'];

    protected $table = 'fin_attachments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attachable_id' => 'integer', 'size' => 'integer', 'uploaded_by' => 'integer'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
