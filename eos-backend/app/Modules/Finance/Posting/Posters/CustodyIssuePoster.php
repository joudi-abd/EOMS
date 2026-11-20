<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Advances\CustodyService;
use App\Modules\Finance\Services\Core\SystemAccounts;
use Illuminate\Support\Facades\Auth;

/**
 * Custody issue (entry #19), as `custody_issue` or `payment.custody`: Dr custody (party = holder, project or general,
 * custody) / Cr box. R15: when the holder already has an open custody for the same project (or both general) and
 * currency, the voucher needs `meta.confirm_existing = true` and tops that custody up; otherwise a new custody is
 * opened when the voucher posts.
 */
class CustodyIssuePoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private CustodyService $custodies)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $this->party($voucher->party_id);
        $this->custodies->assertIssueAllowed($voucher);
    }

    /** Opens or locks the custody inside the posting transaction so the line carries its id. */
    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $custody = $this->custodies->resolveForIssue($voucher, Auth::id() ?? $voucher->updated_by);
        $amount = (string) $voucher->amount;
        $memo = $voucher->description ?: 'صرف عهدة';

        return [
            LineDraft::debit($this->roles->id('custody'), $voucher->currency_id, $amount,
                ['party_id' => $custody->holder_party_id, 'project_id' => $custody->project_id, 'custody_id' => $custody->id], $memo),
            LineDraft::credit($box->account_id, $voucher->currency_id, $amount, [], $memo),
        ];
    }

    public function afterPost(Voucher $voucher): void
    {
        $this->custodies->refresh($voucher->custody_id, $voucher->voucher_date->toDateString(), $voucher->approved_by);
    }

    public function afterReverse(Voucher $voucher): void
    {
        $date = JournalEntry::query()->whereKey($voucher->reversal_entry_id)->value('entry_date');
        $this->custodies->refresh($voucher->custody_id, $date ? substr((string) $date, 0, 10) : now()->toDateString(), $voucher->reversed_by);
    }
}
