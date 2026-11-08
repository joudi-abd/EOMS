<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Item;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\StockMove;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sale and purchase invoices. A line is a stock item (quantity × price, moving inventory at weighted
 * average cost) or free text posted to a chosen account. No discounts or taxes. Above the approval
 * limit an invoice waits for the company manager before it posts.
 */
class InvoiceService
{
    public function __construct(private LedgerService $ledger, private CashService $cash, private AuditLogger $audit) {}

    public function save(array $data, User $actor, ?Invoice $invoice = null): Invoice
    {
        return DB::transaction(function () use ($data, $actor, $invoice) {
            if ($invoice) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                abort_unless($invoice->status === 'draft', 409, 'تُعدَّل المسودة فقط؛ الفاتورة المرحّلة تُلغى وتُصدر بدلها.');
                abort_unless($invoice->version === (int) ($data['version'] ?? 0), 409, 'تغيّرت الفاتورة؛ حدّث الصفحة.');
                $type = $invoice->type;
            } else {
                $type = $data['type'];
            }
            $party = Party::query()->whereKey($data['party_id'])->where('active', true)->first();
            abort_unless($party && $party->kind === ($type === 'sale' ? 'customer' : 'supplier'), 422,
                $type === 'sale' ? 'فاتورة البيع لعميل فعّال.' : 'فاتورة الشراء لمورد فعّال.');
            $currency = strtoupper($data['currency_code']);
            $lines = $this->lines($type, $currency, $data['lines']);
            $values = ['party_id' => $party->id, 'date' => $data['date'], 'due_date' => $data['due_date'] ?? $data['date'],
                'currency_code' => $currency, 'notes' => $data['notes'] ?? null,
                'total' => round($lines->sum('amount'), 2)];
            if ($invoice) {
                $invoice->update([...$values, 'version' => $invoice->version + 1]);
                $invoice->lines()->delete();
            } else {
                $invoice = Invoice::create([...$values, 'type' => $type, 'created_by' => $actor->id]);
            }
            $invoice->lines()->createMany($lines->all());
            $this->audit->log('finance.invoice_saved', $invoice, null, ['total' => $invoice->total], $actor->id);

            return $invoice->load('lines');
        });
    }

    /** Posts directly within the approval limit, otherwise waits for the company manager. */
    public function submit(Invoice $invoice, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->status === 'draft', 409, 'الفاتورة ليست مسودة.');
            // Refuse impossible sales before they reach the approval queue; posting checks again.
            if ($invoice->type === 'sale') {
                foreach ($invoice->lines()->whereNotNull('item_id')->get()->groupBy('item_id') as $itemId => $lines) {
                    $item = Item::query()->findOrFail($itemId);
                    if ($lines->sum(fn ($l) => (float) $l->quantity) - (float) $item->quantity > 0.0005) {
                        throw ValidationException::withMessages(['quantity' => ["الكمية المتوفرة من {$item->name} هي ".rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.').' فقط.']]);
                    }
                }
            }
            if ($this->cash->needsApproval($invoice->currency_code, (float) $invoice->total)) {
                $invoice->update(['status' => 'pending', 'version' => $invoice->version + 1]);

                return $invoice->refresh();
            }
            $this->post($invoice, $actor);

            return $invoice->refresh();
        });
    }

    public function decide(Invoice $invoice, bool $approve, ?string $note, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $approve, $note, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->status === 'pending', 409, 'الفاتورة ليست بانتظار الاعتماد.');
            abort_if($invoice->created_by === $actor->id, 403, 'لا يعتمد أحد فاتورة أنشأها بنفسه.');
            $invoice->update(['approved_by' => $actor->id, 'decision_note' => $note]);
            if ($approve) {
                $this->post($invoice, $actor);
            } else {
                // Rejected invoices return to draft so finance can fix and resubmit.
                $invoice->update(['status' => 'draft', 'version' => $invoice->version + 1]);
            }
            $this->audit->log($approve ? 'finance.invoice_approved' : 'finance.invoice_rejected', $invoice, null, ['note' => $note], $actor->id);

            return $invoice->refresh();
        });
    }

    /** Reverses the invoice's entries and stock; payments must be reversed first. */
    public function cancel(Invoice $invoice, string $date, string $reason, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $date, $reason, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->status === 'posted', 409, 'تُلغى الفواتير المرحّلة فقط.');
            abort_if((float) $invoice->paid > 0, 409, 'اعكس دفعات الفاتورة أولاً.');
            foreach (JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->whereNull('reverses_id')->get() as $entry) {
                $this->ledger->reverse($entry, $date, $reason, $actor);
            }
            foreach ($invoice->lines()->whereNotNull('item_id')->get() as $line) {
                $sign = $invoice->type === 'sale' ? 1 : -1;
                $cost = $invoice->type === 'sale' ? (float) $line->cost_amount / (float) $line->quantity : (float) $line->unit_price;
                $this->move(Item::query()->lockForUpdate()->findOrFail($line->item_id), $sign * (float) $line->quantity, $cost, $date,
                    'invoice_cancel', $invoice->id, $reason, $actor);
            }
            $invoice->update(['status' => 'cancelled', 'decision_note' => $reason, 'version' => $invoice->version + 1]);
            $this->audit->log('finance.invoice_cancelled', $invoice, null, ['reason' => $reason], $actor->id);

            return $invoice->refresh();
        });
    }

    /**
     * Manual stock count difference. Gains enter at the given cost (default: current average); losses leave at average.
     * The other side goes to the chosen account (e.g. inventory gain/loss).
     */
    public function adjustStock(Item $item, array $data, User $actor): StockMove
    {
        return DB::transaction(function () use ($item, $data, $actor) {
            $item = Item::query()->lockForUpdate()->findOrFail($item->id);
            $quantity = round((float) $data['quantity'], 3);
            abort_if($quantity == 0, 422, 'أدخل فرق الكمية.');
            $this->ledger->postable((int) $data['account_id']);
            $cost = $quantity > 0 ? (float) ($data['unit_cost'] ?? $item->average_cost) : (float) $item->average_cost;
            $move = $this->move($item, $quantity, $cost, $data['date'], 'adjustment', 0, $data['reason'], $actor);
            $value = round(abs($quantity) * $cost, 2);
            if ($value > 0) {
                $inventory = $this->ledger->systemAccount('inventory')->id;
                $lines = $quantity > 0
                    ? [['account_id' => $inventory, 'debit' => $value], ['account_id' => (int) $data['account_id'], 'credit' => $value]]
                    : [['account_id' => (int) $data['account_id'], 'debit' => $value], ['account_id' => $inventory, 'credit' => $value]];
                $this->ledger->post($item->currency_code, $data['date'], 'تسوية جرد: '.$item->name.' — '.$data['reason'], $lines, $actor, 'stock_move', $move->id);
            }

            return $move;
        });
    }

    private function post(Invoice $invoice, User $actor): void
    {
        $date = $invoice->date->format('Y-m-d');
        $this->ledger->ensureOpen($date);
        $invoice->update(['number' => $invoice->number ?? $this->ledger->number($invoice->type === 'sale' ? 'S' : 'P', $date, 4)]);
        $party = $this->ledger->systemAccount($invoice->type === 'sale' ? 'receivables' : 'payables')->id;
        $inventory = $this->ledger->systemAccount('inventory')->id;
        $lines = [];
        $costs = [];
        foreach ($invoice->lines()->orderBy('id')->get() as $line) {
            $amount = (float) $line->amount;
            if ($invoice->type === 'purchase') {
                $account = $line->item_id ? $inventory : $line->account_id;
                $lines[] = ['account_id' => $account, 'debit' => $amount, 'memo' => $line->description];
                if ($line->item_id) {
                    $this->move(Item::query()->lockForUpdate()->findOrFail($line->item_id), (float) $line->quantity, (float) $line->unit_price,
                        $date, 'invoice', $invoice->id, null, $actor);
                }
            } else {
                $lines[] = ['account_id' => $line->account_id, 'credit' => $amount, 'memo' => $line->description];
                if ($line->item_id) {
                    $item = Item::query()->lockForUpdate()->findOrFail($line->item_id);
                    $cost = round((float) $line->quantity * (float) $item->average_cost, 2);
                    $line->update(['cost_amount' => $cost]);
                    $this->move($item, -(float) $line->quantity, (float) $item->average_cost, $date, 'invoice', $invoice->id, null, $actor);
                    $costs[$item->currency_code] = ($costs[$item->currency_code] ?? 0) + $cost;
                }
            }
        }
        $total = (float) $invoice->total;
        $partyLine = ['account_id' => $party, 'party_id' => $invoice->party_id, 'memo' => $invoice->number];
        $lines[] = $invoice->type === 'sale' ? [...$partyLine, 'debit' => $total] : [...$partyLine, 'credit' => $total];
        $label = ($invoice->type === 'sale' ? 'فاتورة بيع ' : 'فاتورة شراء ').$invoice->number;
        $this->ledger->post($invoice->currency_code, $date, $label, $lines, $actor, 'invoice', $invoice->id);
        // Cost of goods sold is recorded in each item's valuation currency, which may differ from the sale currency.
        foreach ($costs as $currency => $cost) {
            if ($cost > 0) {
                $this->ledger->post($currency, $date, 'تكلفة '.$label, [['account_id' => $this->ledger->systemAccount('cogs')->id, 'debit' => $cost],
                    ['account_id' => $inventory, 'credit' => $cost]], $actor, 'invoice', $invoice->id);
            }
        }
        $invoice->update(['status' => 'posted', 'version' => $invoice->version + 1]);
        $this->audit->log('finance.invoice_posted', $invoice, null, ['number' => $invoice->number], $actor->id);
    }

    /** Weighted average cost: purchases re-average; sales and losses leave at average; stock never goes negative. */
    private function move(Item $item, float $quantity, float $unitCost, string $date, string $sourceType, int $sourceId, ?string $reason, User $actor): StockMove
    {
        $onHand = (float) $item->quantity;
        $after = round($onHand + $quantity, 3);
        if ($after < -0.0005) {
            throw ValidationException::withMessages(['quantity' => ["الكمية المتوفرة من {$item->name} هي ".rtrim(rtrim(number_format($onHand, 3, '.', ''), '0'), '.').' فقط.']]);
        }
        $average = (float) $item->average_cost;
        if ($quantity > 0 && $after > 0) {
            $average = ($onHand * $average + $quantity * $unitCost) / $after;
        } elseif ($quantity < 0 && $sourceType === 'invoice_cancel' && $after > 0) {
            // Cancelling a purchase removes it at its own price.
            $average = max(0, ($onHand * $average + $quantity * $unitCost) / $after);
        } elseif ($after == 0.0) {
            $average = 0;
        }
        $item->update(['quantity' => $after, 'average_cost' => round($average, 6)]);

        return StockMove::create(['item_id' => $item->id, 'date' => $date, 'quantity' => $quantity, 'unit_cost' => round($unitCost, 6),
            'quantity_after' => $after, 'average_cost_after' => round($average, 6), 'source_type' => $sourceType, 'source_id' => $sourceId,
            'reason' => $reason, 'created_by' => $actor->id]);
    }

    private function lines(string $type, string $currency, array $lines): Collection
    {
        return collect($lines)->values()->map(function (array $line, int $i) use ($type, $currency) {
            $quantity = round((float) $line['quantity'], 3);
            $price = round((float) $line['unit_price'], 2);
            $account = $line['account_id'] ?? null;
            $item = null;
            if (! empty($line['item_id'])) {
                $item = Item::query()->whereKey($line['item_id'])->where('active', true)->first();
                abort_unless($item, 422, "البند $i: المادة غير موجودة أو غير فعّالة.");
                if ($type === 'purchase') {
                    abort_unless($item->currency_code === $currency, 422, "البند $i: تُشترى {$item->name} بعملة تقييمها {$item->currency_code} فقط.");
                    $account = null;
                } else {
                    $account ??= $item->revenue_account_id;
                    abort_unless($account, 422, "البند $i: حدّد حساب الإيراد للمادة.");
                }
            } else {
                abort_unless($account, 422, "البند $i: حدّد الحساب لبند الوصف الحر.");
            }
            if ($account) {
                $allowed = $type === 'sale' ? ['revenue'] : ['expense', 'asset'];
                abort_unless(in_array($this->ledger->postable((int) $account, "lines.$i.account_id")->type, $allowed, true), 422,
                    $type === 'sale' ? "البند $i: حساب إيراد مطلوب." : "البند $i: حساب مصروف أو أصل مطلوب.");
            }

            return ['item_id' => $item?->id, 'account_id' => $account, 'description' => $line['description'] ?? $item?->name,
                'quantity' => $quantity, 'unit_price' => $price, 'amount' => round($quantity * $price, 2)];
        });
    }
}
