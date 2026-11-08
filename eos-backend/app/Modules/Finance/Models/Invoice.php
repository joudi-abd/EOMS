<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $table = 'fin_invoices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['party_id' => 'integer', 'date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'total' => 'decimal:2', 'paid' => 'decimal:2', 'created_by' => 'integer', 'approved_by' => 'integer', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
