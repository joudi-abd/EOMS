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
    /** Whole-transaction retries on deadlocks and serialization conflicts (MariaDB 1020, PostgreSQL 40001/40P01). */
    private const TX_ATTEMPTS = 3;

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
            abort_unless($lines->sum('amount') > 0, 422, 'إجمالي الفاتورة يجب أن يكون أكبر من صفر.');
            // Drafts count as unsettled for month-close; take the period lock before inserting.
            $this->ledger->ensureOpen($data['date']);
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

            return $invoice->load(['lines' => fn ($q) => $q->orderBy('id')]);
        }, self::TX_ATTEMPTS);
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
                app(FinanceNotifier::class)->toApprovers('finance.invoice_pending', ($invoice->type === 'sale' ? 'فاتورة بيع' : 'فاتورة شراء')
                    .' بانتظار الاعتماد: '.number_format((float) $invoice->total, 2).' '.$invoice->currency_code, ['invoice_id' => $invoice->id], $actor->id);

                return $invoice->refresh();
            }
            $this->post($invoice, $actor);

            return $invoice->refresh();
        }, self::TX_ATTEMPTS);
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
            app(FinanceNotifier::class)->toUser($invoice->created_by, $approve ? 'finance.invoice_approved' : 'finance.invoice_rejected',
                ($approve ? 'اعتُمدت الفاتورة ' : 'أُعيدت الفاتورة للتعديل ').($invoice->number ?? '#'.$invoice->id).($note ? ': '.$note : '.'), ['invoice_id' => $invoice->id]);

            return $invoice->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Reverses the invoice's entries and stock; payments must be reversed first.
     * A purchase is cancelled only while it is still the latest stock move of each of its items.
     */
    public function cancel(Invoice $invoice, string $date, string $reason, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $date, $reason, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->status === 'posted', 409, 'تُلغى الفواتير المرحّلة فقط.');
            // Stock moves at zero cost post no entry; check the period here, not only through the ledger.
            $this->ledger->ensureOpen($date);
            abort_if((float) $invoice->paid > 0, 409, 'اعكس دفعات الفاتورة أولاً.');
            // Lock stock rows before the value check so a concurrent post/adjust cannot slip in.
            $itemIds = $invoice->lines()->whereNotNull('item_id')->pluck('item_id')->unique()->sort()->values();
            $items = [];
            foreach ($itemIds as $itemId) {
                $items[$itemId] = Item::query()->lockForUpdate()->findOrFail($itemId);
            }
            $this->assertStockInvoiceCancellable($invoice, $items);
            foreach (JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->whereNull('reverses_id')->get() as $entry) {
                $this->ledger->reverse($entry, $date, $reason, $actor);
            }
            // A sale comes back at the exact unit cost it left with (its stock move), not the cent-rounded entry,
            // so the average returns to where it was; the entry reversal returns the rounded amount.
            $saleMoves = $invoice->type === 'sale'
                ? StockMove::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->where('quantity', '<', 0)
                    ->orderBy('id')->get()->groupBy('item_id')->map(fn ($moves) => $moves->values())
                : collect();
            $taken = [];
            foreach ($invoice->lines()->whereNotNull('item_id')->orderBy('id')->get() as $line) {
                $sign = $invoice->type === 'sale' ? 1 : -1;
                if ($invoice->type === 'sale') {
                    $index = $taken[$line->item_id] = ($taken[$line->item_id] ?? -1) + 1;
                    $saleMove = $saleMoves->get($line->item_id)?->get($index);
                    $cost = $saleMove ? (float) $saleMove->unit_cost : $this->unitCost((float) $line->cost_amount, (float) $line->quantity);
                } else {
                    $cost = $this->unitCost((float) $line->amount, (float) $line->quantity);
                }
                $this->move($items[$line->item_id], $sign * (float) $line->quantity, $cost, $date,
                    'invoice_cancel', $invoice->id, $reason, $actor);
            }
            $invoice->update(['status' => 'cancelled', 'decision_note' => $reason, 'version' => $invoice->version + 1]);
            $this->audit->log('finance.invoice_cancelled', $invoice, null, ['reason' => $reason], $actor->id);

            return $invoice->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Manual stock count difference. Gains enter at the given cost (default: current average); losses leave at average.
     * The other side goes to the chosen account (e.g. inventory gain/loss).
     */
    public function adjustStock(Item $item, array $data, User $actor): StockMove
    {
        return DB::transaction(function () use ($item, $data, $actor) {
            // A zero-value count posts no entry, so the period check cannot be left to the ledger.
            $this->ledger->ensureOpen($data['date']);
            $item = Item::query()->lockForUpdate()->findOrFail($item->id);
            $quantity = round((float) $data['quantity'], 3);
            abort_if($quantity == 0, 422, 'أدخل فرق الكمية.');
            $counterpart = $this->ledger->stockAdjustmentCounterpart((int) $data['account_id']);
            $cost = $quantity > 0 ? (float) ($data['unit_cost'] ?? $item->average_cost) : (float) $item->average_cost;
            $move = $this->move($item, $quantity, $cost, $data['date'], 'adjustment', 0, $data['reason'], $actor);
            $value = round(abs($quantity) * $cost, 2);
            if ($value > 0) {
                $inventory = $this->ledger->systemAccount('inventory')->id;
                $lines = $quantity > 0
                    ? [['account_id' => $inventory, 'debit' => $value], ['account_id' => $counterpart->id, 'credit' => $value]]
                    : [['account_id' => $counterpart->id, 'debit' => $value], ['account_id' => $inventory, 'credit' => $value]];
                $this->ledger->post($item->currency_code, $data['date'], 'تسوية جرد: '.$item->name.' — '.$data['reason'], $lines, $actor, 'stock_move', $move->id);
            }

            return $move;
        }, self::TX_ATTEMPTS);
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
                    // Valued at the amount posted to the inventory account, so stock and ledger share one cost basis.
                    $this->move(Item::query()->lockForUpdate()->findOrFail($line->item_id), (float) $line->quantity,
                        $this->unitCost($amount, (float) $line->quantity), $date, 'invoice', $invoice->id, null, $actor);
                }
            } else {
                $lines[] = ['account_id' => $line->account_id, 'credit' => $amount, 'memo' => $line->description];
                if ($line->item_id) {
                    $item = Item::query()->lockForUpdate()->findOrFail($line->item_id);
                    $cost = round((float) $line->quantity * (float) $item->average_cost, 2);
                    $line->update(['cost_amount' => $cost]);
                    // Goods leave at the average (a sale never changes it); the entry carries that value rounded to cents.
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

    /**
     * A purchase is undone only while it is the latest stock move of each item: later sales or counts were valued
     * at an average that included it, and taking it out at its own price afterwards can leave a negative cost.
     * Sales are always reversible (the goods come back at the cost they left with). The resulting average is
     * computed exactly as move() will, on the same cost basis, and must not be negative.
     */
    private function assertStockInvoiceCancellable(Invoice $invoice, array $items): void
    {
        if ($invoice->type !== 'purchase') {
            return;
        }
        foreach ($invoice->lines()->whereNotNull('item_id')->orderBy('id')->get()->groupBy('item_id') as $itemId => $lines) {
            $item = $items[$itemId];
            $lastOwnMove = StockMove::query()->where('item_id', $itemId)->where('source_type', 'invoice')
                ->where('source_id', $invoice->id)->max('id');
            if ($lastOwnMove !== null && StockMove::query()->where('item_id', $itemId)->where('id', '>', $lastOwnMove)->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => ['لا تُلغى فاتورة الشراء بعد حركات لاحقة على '.$item->name.'؛ سجّل تسوية جرد بدل الإلغاء.'],
                ]);
            }
            $quantity = (float) $item->quantity;
            $average = (float) $item->average_cost;
            foreach ($lines as $line) {
                $removed = -(float) $line->quantity;
                $after = round($quantity + $removed, 3);
                if ($after < -0.0005) {
                    break; // move() reports the missing quantity
                }
                $average = $this->averageAfter($quantity, $average, $removed, $this->unitCost((float) $line->amount, (float) $line->quantity), 'invoice_cancel');
                if ($average < 0) {
                    throw ValidationException::withMessages([
                        'invoice' => ['إلغاء فاتورة الشراء يجعل متوسط تكلفة '.$item->name.' سالباً؛ سجّل تسوية جرد بدل الإلغاء.'],
                    ]);
                }
                $quantity = $after;
            }
        }
    }

    /** Unit cost of a stock line from the amount posted for it. */
    private function unitCost(float $amount, float $quantity): float
    {
        return $quantity > 0 ? $amount / $quantity : 0.0;
    }

    /** The weighted average after a move, rounded as stored (6 places); shared by move() and the cancel check. */
    private function averageAfter(float $onHand, float $average, float $quantity, float $unitCost, string $sourceType): float
    {
        $after = round($onHand + $quantity, 3);
        if ($after == 0.0) {
            return 0.0;
        }
        if ($quantity > 0 || $sourceType === 'invoice_cancel') {
            $average = ($onHand * $average + $quantity * $unitCost) / $after;
        }
        $average = round($average, 6);
        // Stock with (almost) zero cost can come out a fraction of a cent below zero from rounding alone:
        // that is zero. Anything larger stays negative so the cancel check refuses it.
        if ($average < 0 && abs($average * $after) < 0.005) {
            return 0.0;
        }

        return $average + 0.0; // + 0.0 turns -0.0 into 0.0
    }

    /** Weighted average cost: purchases re-average; sales and losses leave at average; stock never goes negative. */
    private function move(Item $item, float $quantity, float $unitCost, string $date, string $sourceType, int $sourceId, ?string $reason, User $actor): StockMove
    {
        $onHand = (float) $item->quantity;
        $after = round($onHand + $quantity, 3);
        if ($after < -0.0005) {
            throw ValidationException::withMessages(['quantity' => ["الكمية المتوفرة من {$item->name} هي ".rtrim(rtrim(number_format($onHand, 3, '.', ''), '0'), '.').' فقط.']]);
        }
        // A sale cancel adds back at its cost and a purchase cancel removes at its price (checked ≥ 0 beforehand).
        $average = $this->averageAfter($onHand, (float) $item->average_cost, $quantity, $unitCost, $sourceType);
        $item->update(['quantity' => $after, 'average_cost' => $average]);

        return StockMove::create(['item_id' => $item->id, 'date' => $date, 'quantity' => $quantity, 'unit_cost' => round($unitCost, 6),
            'quantity_after' => $after, 'average_cost_after' => round($average, 6), 'source_type' => $sourceType, 'source_id' => $sourceId,
            'reason' => $reason, 'created_by' => $actor->id]);
    }

    private function lines(string $type, string $currency, array $lines): Collection
    {
        // Keep the user's order: validated() can rebuild the array with keys out of order when lines omit optional fields.
        return collect($lines)->sortKeys()->values()->map(function (array $line, int $i) use ($type, $currency) {
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
                $posted = $this->ledger->postable((int) $account, "lines.$i.account_id");
                $allowed = $type === 'sale' ? ['revenue'] : ['expense', 'asset'];
                abort_unless(in_array($posted->type, $allowed, true), 422,
                    $type === 'sale' ? "البند $i: حساب إيراد مطلوب." : "البند $i: حساب مصروف أو أصل مطلوب.");
                // Free-text lines must not touch inventory GL without a catalogue item (stock move).
                if ($posted->system_key === 'inventory') {
                    throw ValidationException::withMessages(["lines.$i.account_id" => ["البند $i: حساب المخزون يتحرك مع المواد فقط؛ اختر مادة من المخزون."]]);
                }
            }

            // A stock item is always counted in its catalogue unit; a free-text line takes the unit the user writes (metre, kg...).
            $unit = $item ? $item->unit : (filled($line['unit'] ?? null) ? trim($line['unit']) : null);
            if ($item && filled($line['unit'] ?? null) && trim($line['unit']) !== $item->unit) {
                abort(422, "البند $i: وحدة {$item->name} هي {$item->unit}.");
            }

            return ['item_id' => $item?->id, 'account_id' => $account, 'description' => $line['description'] ?? $item?->name,
                'unit' => $unit, 'quantity' => $quantity, 'unit_price' => $price, 'amount' => round($quantity * $price, 2)];
        });
    }
}
