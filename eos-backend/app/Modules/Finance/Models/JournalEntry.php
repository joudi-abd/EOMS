<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A posted entry: balanced per currency, immutable; corrected only by a reversing entry. */
class JournalEntry extends Model
{
    /** Non-blocking notes produced while posting (e.g. a completed project). Not stored. */
    public array $warnings = [];

    protected $table = 'fin_journal_entries';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'posted'];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'period_id' => 'integer', 'branch_id' => 'integer', 'source_id' => 'integer', 'reversal_of_id' => 'integer', 'posted_by' => 'integer', 'posted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'reversal_of_id');
    }
}
