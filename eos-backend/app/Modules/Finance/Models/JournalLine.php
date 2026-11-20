<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One debit or credit in its own currency with the base (USD) equivalent at the bulletin rate. */
class JournalLine extends Model
{
    public $timestamps = false;

    protected $table = 'fin_journal_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['journal_entry_id' => 'integer', 'line_no' => 'integer', 'account_id' => 'integer', 'currency_id' => 'integer', 'debit' => 'decimal:4', 'credit' => 'decimal:4', 'rate_to_base' => 'decimal:8', 'base_debit' => 'decimal:4', 'base_credit' => 'decimal:4', 'deal_rate' => 'decimal:8', 'entry_date' => 'date:Y-m-d', 'branch_id' => 'integer', 'party_id' => 'integer', 'project_id' => 'integer', 'project_cost_category_id' => 'integer', 'expense_category_id' => 'integer', 'asset_id' => 'integer', 'employee_id' => 'integer', 'contractor_agreement_id' => 'integer', 'custody_id' => 'integer'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
