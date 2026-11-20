<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line of a multi-line voucher (expense, custody settlement, manual journal, opening balance, allocation, payroll). */
class VoucherLine extends Model
{
    protected $table = 'fin_voucher_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['voucher_id' => 'integer', 'line_no' => 'integer', 'account_id' => 'integer', 'expense_category_id' => 'integer', 'project_id' => 'integer', 'project_cost_category_id' => 'integer', 'party_id' => 'integer', 'asset_id' => 'integer', 'employee_id' => 'integer', 'contractor_agreement_id' => 'integer', 'custody_id' => 'integer', 'currency_id' => 'integer', 'debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
