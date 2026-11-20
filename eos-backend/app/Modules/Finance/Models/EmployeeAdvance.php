<?php

namespace App\Modules\Finance\Models;

use App\Modules\Settings\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A salary advance repaid in monthly installments (D17). */
class EmployeeAdvance extends Model
{
    protected $table = 'fin_employee_advances';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['employee_id' => 'integer', 'party_id' => 'integer', 'voucher_id' => 'integer', 'amount' => 'decimal:4', 'currency_id' => 'integer', 'installments_count' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function installments(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceInstallment::class, 'advance_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}
