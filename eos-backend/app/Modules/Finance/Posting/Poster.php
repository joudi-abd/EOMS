<?php

namespace App\Modules\Finance\Posting;

use App\Modules\Finance\Models\Voucher;

/**
 * Turns one voucher type (or receipt/payment purpose) into journal lines. PostingService calls, inside one
 * transaction: validate() → lines() → LedgerService::postEntry() → afterPost(); and on reversal afterReverse().
 * VoucherService also calls validate() when a draft is saved. Throw ValidationException (422) for bad input.
 */
interface Poster
{
    /** Business rules on the voucher's own fields (R7, R9, R11, ...). Balances are checked by the ledger. */
    public function validate(Voucher $voucher): void;

    /** @return list<LineDraft> resolve system roles with SystemAccounts before returning */
    public function lines(Voucher $voucher): array;

    /** Side effects after the entry is posted (e.g. create the asset, the advance installments, refresh custody). */
    public function afterPost(Voucher $voucher): void;

    /** Undo afterPost() side effects after the reversing entry is posted. */
    public function afterReverse(Voucher $voucher): void;

    /** @return list<string> non-blocking notes shown to the user (R10 rate deviation, R13 budget, R5 completed project) */
    public function warnings(Voucher $voucher): array;
}
