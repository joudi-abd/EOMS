<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Every business document that posts (receipt, payment, transfer, exchange, ...). Draft → approved (= posted) → reversed. */
class Voucher extends Model
{
    public const TYPES = ['receipt', 'payment', 'transfer', 'exchange', 'expense', 'project_billing', 'custody_issue', 'custody_settlement', 'custody_return',
        'advance_issue', 'asset_purchase', 'asset_maintenance', 'asset_disposal', 'count_adjustment', 'opening_balance', 'allocation', 'manual_journal',
        'payroll_accrual', 'payroll_payment'];

    public const PURPOSES = ['client_payment', 'contractor_payment', 'supplier_payment', 'internal_expense', 'partner_drawing', 'partner_deposit',
        'employee_advance', 'contractor_advance', 'custody', 'debt', 'other'];

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'reversed'];

    /** Non-blocking notes from validation and posting (R10, R13, R5). Not stored. */
    public array $warnings = [];

    protected $table = 'fin_vouchers';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'voucher_date' => 'date:Y-m-d', 'cash_box_id' => 'integer', 'currency_id' => 'integer', 'amount' => 'decimal:4', 'counter_cash_box_id' => 'integer', 'counter_currency_id' => 'integer', 'counter_amount' => 'decimal:4', 'rate' => 'decimal:8', 'fee_amount' => 'decimal:4', 'fee_currency_id' => 'integer', 'party_id' => 'integer', 'project_id' => 'integer', 'project_cost_category_id' => 'integer', 'expense_category_id' => 'integer', 'asset_id' => 'integer', 'employee_id' => 'integer', 'contractor_agreement_id' => 'integer', 'custody_id' => 'integer', 'account_id' => 'integer', 'advance_deduction' => 'decimal:4', 'meta' => 'array', 'journal_entry_id' => 'integer', 'reversal_entry_id' => 'integer', 'submitted_by' => 'integer', 'submitted_at' => 'datetime', 'approved_by' => 'integer', 'approved_at' => 'datetime', 'rejected_by' => 'integer', 'rejected_at' => 'datetime', 'reversed_by' => 'integer', 'reversed_at' => 'datetime', 'version' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class, 'voucher_id');
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }

    public function counterCashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'counter_cash_box_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
