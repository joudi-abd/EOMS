<?php

namespace App\Modules\Finance\Services\Core;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\EntryDraft;
use App\Modules\Finance\Posting\PosterRegistry;
use App\Modules\Finance\Services\Projects\BudgetMonitor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Approving a voucher posts it: the poster validates and builds the lines, the ledger posts them, the voucher gets
 * its number (gapless per type and year) — all in one transaction. Reversal posts the mirror entry (R3: an approved
 * voucher is never edited or deleted).
 */
class PostingService
{
    private const TX_ATTEMPTS = 3;

    public const LABELS = [
        'receipt' => 'سند قبض', 'payment' => 'سند صرف', 'transfer' => 'تحويل بين صناديق', 'exchange' => 'تصريف عملة', 'expense' => 'سند مصروف',
        'project_billing' => 'مطالبة مشروع', 'custody_issue' => 'صرف عهدة', 'custody_settlement' => 'تسوية عهدة', 'custody_return' => 'رد عهدة',
        'advance_issue' => 'سلفة موظف', 'asset_purchase' => 'شراء أصل', 'asset_maintenance' => 'صيانة أصل', 'asset_disposal' => 'استبعاد أصل',
        'count_adjustment' => 'فرق جرد', 'opening_balance' => 'رصيد افتتاحي', 'allocation' => 'توزيع على المشاريع', 'manual_journal' => 'قيد يدوي',
        'payroll_accrual' => 'استحقاق رواتب', 'payroll_payment' => 'صرف راتب',
    ];

    public function __construct(private PosterRegistry $registry, private LedgerService $ledger, private Sequences $sequences, private AuditLogger $audit,
        private BudgetMonitor $budget) {}

    public function approve(Voucher $voucher, User $actor): Voucher
    {
        return DB::transaction(function () use ($voucher, $actor) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);
            abort_unless(in_array($voucher->status, ['draft', 'submitted'], true), 409, 'السند ليس مسودة أو بانتظار الاعتماد.');
            $poster = $this->registry->for($voucher);
            $poster->validate($voucher);
            $lines = $poster->lines($voucher);
            $date = $voucher->voucher_date->toDateString();
            $number = $this->sequences->next($voucher->type, $date);
            $description = Str::limit(trim(($voucher->description ?: self::LABELS[$voucher->type] ?? $voucher->type).' — '.$number), 495);
            $entry = $this->ledger->postEntry(new EntryDraft($date, $voucher->branch_id, $description, $lines, 'voucher', $voucher->id), $actor);
            $voucher->update(['number' => $number, 'status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(),
                'journal_entry_id' => $entry->id]);
            $poster->afterPost($voucher);
            // R13: budget lines of the touched (project, cost category) pairs; warns and notifies, never blocks.
            $budgetWarnings = $this->budget->afterPosting($entry);
            $this->audit->log('finance.voucher_approved', $voucher, null, ['number' => $number, 'type' => $voucher->type, 'entry' => $entry->number], $actor->id);
            $warnings = array_values(array_unique([...$poster->warnings($voucher), ...$entry->warnings, ...$budgetWarnings]));
            $voucher = $voucher->refresh();
            $voucher->warnings = $warnings;

            return $voucher;
        }, self::TX_ATTEMPTS);
    }

    /** Mirror entry dated `$date` in an open month (R1); the voucher becomes `reversed`. */
    public function reverse(Voucher $voucher, string $date, string $reason, User $actor): Voucher
    {
        return DB::transaction(function () use ($voucher, $date, $reason, $actor) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);
            abort_unless($voucher->status === 'approved', 409, 'يُعكس السند المعتمد فقط.');
            $entry = JournalEntry::query()->findOrFail($voucher->journal_entry_id);
            $reversal = $this->ledger->reverseEntry($entry, $date, $reason, $actor);
            $voucher->update(['status' => 'reversed', 'reversal_entry_id' => $reversal->id, 'reversed_by' => $actor->id, 'reversed_at' => now(),
                'reversal_reason' => $reason]);
            $this->registry->for($voucher)->afterReverse($voucher);
            $this->budget->afterPosting($reversal); // R13: a reversal may lower a budget alert level (silently).
            $this->audit->log('finance.voucher_reversed', $voucher, ['status' => 'approved'], ['status' => 'reversed', 'reason' => $reason,
                'entry' => $reversal->number], $actor->id);

            return $voucher->refresh();
        }, self::TX_ATTEMPTS);
    }
}
