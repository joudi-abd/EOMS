<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceAttachment extends Model
{
    protected $table = 'fin_invoice_attachments';

    protected $guarded = ['id'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['invoice_id' => 'integer', 'size' => 'integer', 'uploaded_by' => 'integer'];
    }
}
