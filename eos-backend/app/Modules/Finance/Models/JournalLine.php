<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $table = 'fin_journal_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['journal_entry_id' => 'integer', 'account_id' => 'integer', 'party_id' => 'integer', 'debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public $timestamps = false;
}
