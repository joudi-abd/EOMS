<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\ApprovalLimit;
use App\Modules\Finance\Models\Box;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Boxes (cash or Sham Cash, one currency each) and the operations that move money: receipt, payment,
 * transfer between same-currency boxes, currency exchange between boxes, and payroll payments.
 * Payments above the currency's approval limit wait for the company manager; a box never goes negative.
 */
class CashService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit) {}

    public function createBox(array $data, User $actor): Box
    {
        return DB::transaction(function () use ($data, $actor) {
            $parentId = $data['parent_account_id'] ?? null;
            // Same parent rules as chart accounts: lock, matching type, no postings, not a box/system leaf.
            if ($parentId) {
                $parent = Account::query()->lockForUpdate()->findOrFail($parentId);
                $this->ledger->checkParent($parent, 'asset');
            }
            $account = Account::create(['code' => 'BOX-'.uniqid(), 'name' => 'صندوق: '.$data['name'], 'type' => 'asset',
                'parent_id' => $parentId]);
            $box = Box::create(['name' => $data['name'], 'kind' => $data['kind'], 'currency_code' => strtoupper($data['currency_code']),
                'account_id' => $account->id]);
            $account->update(['code' => 'BOX-'.$box->id]);
            $this->audit->log('finance.box_created', $box, null, $box->getAttributes(), $actor->id);
            // Money already in the box when it is created: a receipt from the opening balances account
            // (or another equity account finance picks, e.g. capital).
            if ((float) ($data['opening_amount'] ?? 0) > 0) {
                $counter = (int) ($data['opening_account_id'] ?? $this->ledger->systemAccount('opening_balances')->id);
                abort_unless($this->ledger->postable($counter, 'opening_account_id')->type === 'equity', 422, 'الرصيد الافتتاحي يقابله حساب حقوق ملكية.');
                $tx = $this->make('receipt', ['date' => $data['opening_date'] ?? now()->toDateString(), 'amount' => $data['opening_amount'],
                    'description' => 'رصيد افتتاحي: '.$box->name, 'account_id' => $counter], $box, $actor, 'posted');
                $this->postTransaction($tx, $actor);
            }

            return $box;
        });
    }

    public function balance(Box $box): float
    {
        return round((float) JournalLine::query()->where('account_id', $box->account_id)->sum(DB::raw('debit - credit')), 2);
    }

    public function setLimit(string $currency, float $amount, User $actor): ApprovalLimit
    {
        $limit = ApprovalLimit::query()->updateOrCreate(['currency_code' => strtoupper($currency)], ['amount' => $amount, 'updated_by' => $actor->id]);
        $this->audit->log('finance.approval_limit_set', $limit, null, $limit->getAttributes(), $actor->id);

        return $limit;
    }

    /** No limit configured for the currency means every amount needs the company manager. */
    public function needsApproval(string $currency, float $amount): bool
    {
        $limit = ApprovalLimit::query()->where('currency_code', strtoupper($currency))->value('amount');

        return $limit === null || $amount > (float) $limit;
    }

    /** Receipt into a box, from a customer (optionally against a sale invoice) or credited to an account. */
    public function receipt(array $data, User $actor): Transaction
    {
        return DB::transaction(function () use ($data, $actor) {
            $box = $this->box($data['box_id']);
            $this->counterparty($data, 'customer', $box);
            $tx = $this->make('receipt', $data, $box, $actor, 'posted');
            $this->postTransaction($tx, $actor);

            return $tx->refresh();
        });
    }

    /** Payment out of a box, to a supplier (optionally against a purchase invoice) or debited to an account. */
    public function payment(array $data, User $actor): Transaction
    {
        return DB::transaction(function () use ($data, $actor) {
            $box = $this->box($data['box_id']);
            $this->counterparty($data, 'supplier', $box);
            $pending = $this->needsApproval($box->currency_code, (float) $data['amount']);
            $tx = $this->make('payment', $data, $box, $actor, $pending ? 'pending' : 'posted');
            if (! $pending) {
                $this->postTransaction($tx, $actor);
            } else {
                app(FinanceNotifier::class)->toApprovers('finance.payment_pending', 'صرف بانتظار الاعتماد: '.number_format((float) $tx->amount, 2).' '.$box->currency_code.' — '.$tx->description,
                    ['transaction_id' => $tx->id], $actor->id);
            }

            return $tx->refresh();
        });
    }

    public function transfer(array $data, User $actor): Transaction
    {
        return DB::transaction(function () use ($data, $actor) {
            $from = $this->box($data['box_id']);
            $to = $this->box($data['counter_box_id']);
            abort_if($from->id === $to->id, 422, 'اختر صندوقين مختلفين.');
            abort_unless($from->currency_code === $to->currency_code, 422, 'التحويل بين صندوقين بنفس العملة؛ استخدم صرف العملة لغير ذلك.');
            $tx = $this->make('transfer', [...$data, 'counter_amount' => $data['amount']], $from, $actor, 'posted');
            $this->postTransaction($tx, $actor);

            return $tx->refresh();
        });
    }

    /** Sell `amount` from one box for `counter_amount` into a box of another currency; one entry per currency. */
    public function exchange(array $data, User $actor): Transaction
    {
        return DB::transaction(function () use ($data, $actor) {
            $from = $this->box($data['box_id']);
            $to = $this->box($data['counter_box_id']);
            abort_if($from->currency_code === $to->currency_code, 422, 'صرف العملة بين عملتين مختلفتين؛ استخدم التحويل لنفس العملة.');
            $data['rate'] = round((float) $data['counter_amount'] / (float) $data['amount'], 6);
            $tx = $this->make('exchange', $data, $from, $actor, 'posted');
            $this->postTransaction($tx, $actor);

            return $tx->refresh();
        });
    }

    /** Called by the payroll module inside its payment transaction: salary paid out of the chosen box. */
    public function payroll(int $boxId, string $currency, float $amount, string $date, string $description, int $sourceId, User $actor): Transaction
    {
        $box = $this->box($boxId);
        if ($box->currency_code !== strtoupper($currency)) {
            throw ValidationException::withMessages(['box_id' => ['عملة الصندوق يجب أن تكون عملة الدفع.']]);
        }
        $tx = $this->make('payroll', ['date' => $date, 'amount' => $amount, 'description' => $description,
            'account_id' => $this->ledger->systemAccount('salaries')->id, 'source_type' => 'payroll_payment', 'source_id' => $sourceId], $box, $actor, 'posted');
        $this->postTransaction($tx, $actor);

        return $tx->refresh();
    }

    public function decide(Transaction $tx, bool $approve, ?string $note, User $actor): Transaction
    {
        return DB::transaction(function () use ($tx, $approve, $note, $actor) {
            $tx = Transaction::query()->lockForUpdate()->findOrFail($tx->id);
            abort_unless($tx->status === 'pending', 409, 'العملية ليست بانتظار الاعتماد.');
            abort_if($tx->created_by === $actor->id, 403, 'لا يعتمد أحد عملية سجّلها بنفسه.');
            if ($approve) {
                // Re-check live box/party/account state; create-time validation may be stale.
                $this->assertPostablePending($tx);
                $tx->update(['status' => 'posted', 'approved_by' => $actor->id, 'decision_note' => $note]);
                $this->postTransaction($tx, $actor);
            } else {
                $tx->update(['status' => 'rejected', 'approved_by' => $actor->id, 'decision_note' => $note]);
            }
            $this->audit->log($approve ? 'finance.payment_approved' : 'finance.payment_rejected', $tx, null, null, $actor->id);
            app(FinanceNotifier::class)->toUser($tx->created_by, $approve ? 'finance.payment_approved' : 'finance.payment_rejected',
                ($approve ? 'اعتُمد الصرف: ' : 'رُفض الصرف: ').$tx->description.($note ? ' — '.$note : ''), ['transaction_id' => $tx->id]);

            return $tx->refresh();
        });
    }

    /** Reverses every entry of a posted operation and restores the paid amount of a linked invoice. */
    public function reverse(Transaction $tx, string $date, string $reason, User $actor, bool $fromPayroll = false): Transaction
    {
        return DB::transaction(function () use ($tx, $date, $reason, $actor, $fromPayroll) {
            $tx = Transaction::query()->lockForUpdate()->findOrFail($tx->id);
            abort_unless($tx->status === 'posted', 409, 'تُعكس العمليات المرحّلة فقط.');
            abort_if($tx->kind === 'payroll' && ! $fromPayroll, 409, 'دفعات الرواتب تُلغى من وحدة الرواتب.');
            foreach (JournalEntry::query()->where('source_type', 'transaction')->where('source_id', $tx->id)->whereNull('reverses_id')->get() as $entry) {
                $this->ledger->reverse($entry, $date, $reason, $actor);
            }
            if ($tx->invoice_id) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($tx->invoice_id);
                $invoice->update(['paid' => round((float) $invoice->paid - (float) $tx->amount, 2)]);
            }
            $tx->update(['status' => 'reversed', 'decision_note' => $reason]);
            $this->audit->log('finance.transaction_reversed', $tx, null, ['reason' => $reason], $actor->id);

            return $tx->refresh();
        });
    }

    private function postTransaction(Transaction $tx, User $actor): void
    {
        $box = Box::query()->lockForUpdate()->findOrFail($tx->box_id);
        $amount = (float) $tx->amount;
        if (in_array($tx->kind, ['payment', 'transfer', 'exchange', 'payroll'], true) && $this->balance($box) + 0.001 < $amount) {
            throw ValidationException::withMessages(['amount' => ['رصيد الصندوق غير كافٍ (المتاح '.number_format($this->balance($box), 2).').']]);
        }
        $post = fn (string $currency, array $lines) => $this->ledger->post($currency, $tx->date->format('Y-m-d'), $tx->description, $lines, $actor, 'transaction', $tx->id);
        $counter = function () use ($tx): array {
            if ($tx->party_id) {
                $key = $tx->kind === 'receipt' ? 'receivables' : 'payables';

                return ['account_id' => $this->ledger->systemAccount($key)->id, 'party_id' => $tx->party_id];
            }

            return ['account_id' => $tx->account_id];
        };
        match ($tx->kind) {
            'receipt' => $post($box->currency_code, [['account_id' => $box->account_id, 'debit' => $amount], [...$counter(), 'credit' => $amount]]),
            'payment', 'payroll' => $post($box->currency_code, [[...$counter(), 'debit' => $amount], ['account_id' => $box->account_id, 'credit' => $amount]]),
            'transfer' => $post($box->currency_code, [['account_id' => Box::findOrFail($tx->counter_box_id)->account_id, 'debit' => $amount],
                ['account_id' => $box->account_id, 'credit' => $amount]]),
            'exchange' => (function () use ($tx, $box, $amount, $post) {
                $fx = $this->ledger->systemAccount('fx_clearing')->id;
                $to = Box::findOrFail($tx->counter_box_id);
                $post($box->currency_code, [['account_id' => $fx, 'debit' => $amount], ['account_id' => $box->account_id, 'credit' => $amount]]);
                $post($to->currency_code, [['account_id' => $to->account_id, 'debit' => (float) $tx->counter_amount], ['account_id' => $fx, 'credit' => (float) $tx->counter_amount]]);
            })(),
        };
        if ($tx->invoice_id) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($tx->invoice_id);
            abort_unless($invoice->status === 'posted', 409, 'الفاتورة غير مرحّلة.');
            $remaining = round((float) $invoice->total - (float) $invoice->paid, 2);
            if ($amount - $remaining > 0.001) {
                throw ValidationException::withMessages(['amount' => ['المبلغ أكبر من المتبقي على الفاتورة ('.number_format($remaining, 2).').']]);
            }
            $invoice->update(['paid' => round((float) $invoice->paid + $amount, 2)]);
        }
    }

    /** Exactly one of party_id / account_id; parties must be of the right kind; invoices must match party and currency. */
    private function counterparty(array $data, string $kind, Box $box): void
    {
        $party = $data['party_id'] ?? null;
        $account = $data['account_id'] ?? null;
        if (($party === null) === ($account === null)) {
            throw ValidationException::withMessages(['party_id' => ['حدّد طرفاً (عميل/مورد) أو حساباً، وليس كليهما.']]);
        }
        if ($party !== null) {
            // Same row lock as party deactivate so a concurrent soft-delete cannot race a new payment/receipt.
            $row = Party::query()->whereKey($party)->lockForUpdate()->first();
            abort_unless($row && $row->kind === $kind && $row->active, 422,
                $kind === 'customer' ? 'القبض من عميل فعّال.' : 'الصرف لمورد فعّال.');
        } else {
            $this->assertCashCounterpart((int) $account);
        }
        if (! empty($data['invoice_id'])) {
            $invoice = Invoice::query()->findOrFail($data['invoice_id']);
            abort_unless($invoice->status === 'posted', 422, 'الفاتورة غير مرحّلة.');
            abort_unless($invoice->party_id === (int) $party && $invoice->currency_code === $box->currency_code
                && $invoice->type === ($kind === 'customer' ? 'sale' : 'purchase'), 422, 'الفاتورة لا تخص هذا الطرف أو العملة.');
        }
    }

    /**
     * Manual cash counterpart: postable, not a box, and not a protected system account.
     * opening_balances remains allowed so a new box can be funded from equity at creation.
     */
    private function assertCashCounterpart(int $accountId, string $field = 'account_id'): Account
    {
        $counter = $this->ledger->postable($accountId, $field);
        abort_if(Box::query()->where('account_id', $accountId)->exists(), 422, 'استخدم التحويل بين الصناديق.');
        if ($counter->system_key !== null && $counter->system_key !== 'opening_balances') {
            throw ValidationException::withMessages([$field => ['لا تستخدم حساب نظام كمقابل لصرف أو قبض يدوي؛ الرواتب والمخزون والتكلفة عبر مساراتها.']]);
        }

        return $counter;
    }

    /** Live checks before posting a pending payment/receipt that was validated at create time. */
    private function assertPostablePending(Transaction $tx): void
    {
        $box = $this->box($tx->box_id);
        if ($tx->party_id) {
            $kind = $tx->kind === 'receipt' ? 'customer' : 'supplier';
            $row = Party::query()->whereKey($tx->party_id)->lockForUpdate()->first();
            abort_unless($row && $row->kind === $kind && $row->active, 422,
                $kind === 'customer' ? 'القبض من عميل فعّال.' : 'الصرف لمورد فعّال.');
        } elseif ($tx->account_id) {
            $this->assertCashCounterpart((int) $tx->account_id);
        }
        if ($tx->invoice_id) {
            $invoice = Invoice::query()->findOrFail($tx->invoice_id);
            abort_unless($invoice->status === 'posted', 422, 'الفاتورة غير مرحّلة.');
            abort_unless($invoice->party_id === (int) $tx->party_id && $invoice->currency_code === $box->currency_code, 422, 'الفاتورة لا تخص هذا الطرف أو العملة.');
        }
    }

    private function make(string $kind, array $data, Box $box, User $actor, string $status): Transaction
    {
        // Lock the period before inserting (including pending payments that do not post yet).
        $this->ledger->ensureOpen($data['date']);

        return Transaction::create([
            'kind' => $kind, 'date' => $data['date'], 'box_id' => $box->id, 'amount' => round((float) $data['amount'], 2),
            'counter_box_id' => $data['counter_box_id'] ?? null,
            'counter_amount' => isset($data['counter_amount']) ? round((float) $data['counter_amount'], 2) : null,
            'rate' => $data['rate'] ?? null, 'party_id' => $data['party_id'] ?? null, 'invoice_id' => $data['invoice_id'] ?? null,
            'account_id' => $data['account_id'] ?? null, 'description' => $data['description'], 'status' => $status,
            'source_type' => $data['source_type'] ?? null, 'source_id' => $data['source_id'] ?? null, 'created_by' => $actor->id,
        ]);
    }

    private function box(int|string $id): Box
    {
        $box = Box::query()->find($id);
        abort_unless($box && $box->active, 422, 'الصندوق غير موجود أو غير فعّال.');

        return $box;
    }
}
