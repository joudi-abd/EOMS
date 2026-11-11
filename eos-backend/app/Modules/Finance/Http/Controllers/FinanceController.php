<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\ApprovalLimit;
use App\Modules\Finance\Models\Box;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceAttachment;
use App\Modules\Finance\Models\Item;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\Period;
use App\Modules\Finance\Models\StockMove;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Services\CashService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Finance\Services\ReportService;
use App\Modules\Identity\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    /** Whole-transaction retries on deadlocks and serialization conflicts (MariaDB 1020, PostgreSQL 40001/40P01). */
    private const TX_ATTEMPTS = 3;

    public function __construct(private LedgerService $ledger, private CashService $cash, private InvoiceService $invoices, private ReportService $reports,
        private AuditLogger $audit) {}

    // ── Chart of accounts and journal ─────────────────────────────────────────

    public function accounts(): JsonResponse
    {
        $parents = Account::query()->whereNotNull('parent_id')->pluck('parent_id')->unique();

        return ApiResponse::ok(Account::query()->orderBy('code')->get()->map(fn (Account $a) => [
            ...$a->toArray(), 'is_group' => $parents->contains($a->id),
            'box_id' => Box::query()->where('account_id', $a->id)->value('id'),
        ]));
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:fin_accounts,code'], 'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(LedgerService::TYPES)], 'parent_id' => ['nullable', 'integer', 'exists:fin_accounts,id'],
        ]);

        return ApiResponse::created($this->ledger->createAccount($data, $request->user()));
    }

    public function updateAccount(Request $request, Account $account): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('fin_accounts', 'code')->ignore($account->id)],
            'name' => ['sometimes', 'string', 'max:150'], 'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:fin_accounts,id'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::ok($this->ledger->updateAccount($account, $data, $request->user()));
    }

    public function entries(Request $request): JsonResponse
    {
        $data = $request->validate(['currency' => ['sometimes', 'string', 'size:3'], 'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'], 'source_type' => ['sometimes', 'string', 'max:30']]);
        $page = JournalEntry::query()->with('lines')
            ->when($data['currency'] ?? null, fn ($q, $v) => $q->where('currency_code', strtoupper($v)))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->when($data['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', $v))
            ->orderByDesc('date')->orderByDesc('id')->paginate(50);

        return ApiResponse::paginated($page);
    }

    public function storeEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'currency_code' => ['required', 'string', 'exists:sys_currencies,code'],
            'description' => ['required', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.account_id' => ['required', 'integer'], 'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'], 'lines.*.party_id' => ['nullable', 'integer', 'exists:fin_parties,id'],
            'lines.*.memo' => ['nullable', 'string', 'max:500'],
        ]);

        // Lines in the order entered (validated() may rebuild the array out of order when optional fields are missing).
        $lines = collect($data['lines'])->sortKeys()->values()->all();

        return ApiResponse::created($this->ledger->post($data['currency_code'], $data['date'], $data['description'], $lines, $request->user(), 'manual'));
    }

    /** Only manual entries are reversed here; generated entries are reversed through their operation or invoice. */
    public function reverseEntry(Request $request, JournalEntry $entry): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        abort_unless($entry->source_type === 'manual', 409, 'اعكس العملية أو ألغِ الفاتورة التي أنشأت هذا القيد.');

        return ApiResponse::created($this->ledger->reverse($entry, $data['date'], $data['reason'], $request->user()));
    }

    public function periods(): JsonResponse
    {
        return ApiResponse::ok(Period::query()->orderByDesc('month')->get());
    }

    public function requestClose(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return ApiResponse::ok($this->ledger->requestClose($data['month'], $request->user()));
    }

    public function decideClose(Request $request, string $month): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::ok($this->ledger->decideClose($month, $data['decision'] === 'approve', $data['note'] ?? null, $request->user()));
    }

    public function reopen(Request $request, string $month): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return ApiResponse::ok($this->ledger->reopen($month, $data['reason'], $request->user()));
    }

    public function limits(): JsonResponse
    {
        return ApiResponse::ok(ApprovalLimit::query()->orderBy('currency_code')->get());
    }

    public function setLimit(Request $request): JsonResponse
    {
        $data = $request->validate(['currency_code' => ['required', 'string', 'exists:sys_currencies,code'], 'amount' => ['required', 'numeric', 'min:0']]);

        return ApiResponse::ok($this->cash->setLimit($data['currency_code'], (float) $data['amount'], $request->user()));
    }

    // ── Boxes and cash operations ─────────────────────────────────────────────

    public function boxes(): JsonResponse
    {
        return ApiResponse::ok(Box::query()->orderBy('currency_code')->orderBy('name')->get()
            ->map(fn (Box $b) => [...$b->toArray(), 'balance' => $this->cash->balance($b)]));
    }

    public function storeBox(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'kind' => ['required', Rule::in(['cash', 'sham_cash'])],
            'currency_code' => ['required', 'string', 'exists:sys_currencies,code'],
            'parent_account_id' => ['nullable', 'integer', 'exists:fin_accounts,id'],
            'opening_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'opening_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'opening_account_id' => ['nullable', 'integer', 'exists:fin_accounts,id'],
        ]);
        $box = $this->cash->createBox($data, $request->user());

        return ApiResponse::created([...$box->toArray(), 'balance' => $this->cash->balance($box)]);
    }

    public function updateBox(Request $request, Box $box): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'active' => ['sometimes', 'boolean']]);
        abort_if(($data['active'] ?? true) === false && $this->cash->balance($box) != 0, 422, 'لا يُوقف صندوق رصيده غير صفر.');
        abort_if(($data['active'] ?? true) === false && Transaction::query()->where('status', 'pending')
            ->where(fn ($q) => $q->where('box_id', $box->id)->orWhere('counter_box_id', $box->id))->exists(), 422, 'للصندوق عمليات بانتظار الاعتماد.');
        $box->update($data);

        return ApiResponse::ok($box->refresh());
    }

    public function transactions(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['pending', 'posted', 'rejected', 'reversed'])],
            'kind' => ['sometimes', Rule::in(['receipt', 'payment', 'transfer', 'exchange', 'payroll'])],
            'box_id' => ['sometimes', 'integer'], 'party_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']]);
        $page = Transaction::query()
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($data['box_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('box_id', $v)->orWhere('counter_box_id', $v)))
            ->when($data['party_id'] ?? null, fn ($q, $v) => $q->where('party_id', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('date')->orderByDesc('id')->paginate(50);

        return ApiResponse::paginated($page);
    }

    public function storeTransaction(Request $request, string $kind): JsonResponse
    {
        $twoBoxes = in_array($kind, ['transfer', 'exchange'], true);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'box_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'], 'description' => ['required', 'string', 'max:500'],
            'counter_box_id' => [Rule::requiredIf($twoBoxes), Rule::prohibitedIf(! $twoBoxes), 'integer'],
            'counter_amount' => [Rule::requiredIf($kind === 'exchange'), Rule::prohibitedIf($kind !== 'exchange'), 'numeric', 'gt:0'],
            'party_id' => [Rule::prohibitedIf($twoBoxes), 'nullable', 'integer'], 'account_id' => [Rule::prohibitedIf($twoBoxes), 'nullable', 'integer'],
            'invoice_id' => [Rule::prohibitedIf($twoBoxes), 'nullable', 'integer'],
        ]);
        $data['invoice_id'] ??= null;
        $tx = match ($kind) {
            'receipt' => $this->cash->receipt($data, $request->user()),
            'payment' => $this->cash->payment($data, $request->user()),
            'transfer' => $this->cash->transfer($data, $request->user()),
            'exchange' => $this->cash->exchange($data, $request->user()),
        };

        return ApiResponse::created($tx);
    }

    public function decideTransaction(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::ok($this->cash->decide($transaction, $data['decision'] === 'approve', $data['note'] ?? null, $request->user()));
    }

    public function reverseTransaction(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::ok($this->cash->reverse($transaction, $data['date'], $data['reason'], $request->user()));
    }

    // ── Parties, items, invoices ──────────────────────────────────────────────

    public function parties(Request $request): JsonResponse
    {
        $request->validate(['kind' => ['sometimes', Rule::in(['customer', 'supplier'])], 'search' => ['sometimes', 'string', 'max:100']]);

        return ApiResponse::paginated(Party::query()->when($request->input('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', '%'.$v.'%'))->orderBy('name')->paginate(50));
    }

    public function storeParty(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['customer', 'supplier'])], 'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:1000'],
            // Sham Cash accounts are 16 digits.
            'sham_cash_wallet' => ['nullable', 'digits:16'], 'sham_cash_name' => ['nullable', 'string', 'max:150']]);

        return ApiResponse::created(Party::create($data));
    }

    public function updateParty(Request $request, Party $party): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'], 'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sham_cash_wallet' => ['sometimes', 'nullable', 'digits:16'], 'sham_cash_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'], 'active' => ['sometimes', 'boolean']]);
        if (array_key_exists('active', $data)) {
            $data['active'] = $request->boolean('active');
        }

        $party = DB::transaction(function () use ($party, $data) {
            $party = Party::query()->lockForUpdate()->findOrFail($party->id);
            if (($data['active'] ?? true) === false) {
                abort_if(Transaction::query()->where('status', 'pending')->where('party_id', $party->id)->lockForUpdate()->first(['id']) !== null,
                    422, 'للطرف عمليات بانتظار الاعتماد.');
            }
            $party->update($data);

            return $party->refresh();
        }, self::TX_ATTEMPTS);

        return ApiResponse::ok($party);
    }

    public function items(): JsonResponse
    {
        return ApiResponse::ok(Item::query()->orderBy('name')->get()->map(fn (Item $i) => [...$i->toArray(),
            'stock_value' => round((float) $i->quantity * (float) $i->average_cost, 2)]));
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150', 'unique:fin_items,name'], 'unit' => ['required', 'string', 'max:20'],
            'currency_code' => ['required', 'string', 'exists:sys_currencies,code'], 'revenue_account_id' => ['nullable', 'integer']]);
        if (! empty($data['revenue_account_id'])) {
            abort_unless($this->ledger->postable((int) $data['revenue_account_id'], 'revenue_account_id')->type === 'revenue', 422, 'حساب الإيراد يجب أن يكون من نوع إيرادات.');
        }

        return ApiResponse::created(Item::create([...$data, 'currency_code' => strtoupper($data['currency_code'])]));
    }

    public function updateItem(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150', Rule::unique('fin_items', 'name')->ignore($item->id)],
            'unit' => ['sometimes', 'string', 'max:20'], 'revenue_account_id' => ['sometimes', 'nullable', 'integer'], 'active' => ['sometimes', 'boolean']]);
        if (! empty($data['revenue_account_id'])) {
            abort_unless($this->ledger->postable((int) $data['revenue_account_id'], 'revenue_account_id')->type === 'revenue', 422, 'حساب الإيراد يجب أن يكون من نوع إيرادات.');
        }
        $item->update($data);

        return ApiResponse::ok($item->refresh());
    }

    public function stockMoves(Item $item): JsonResponse
    {
        return ApiResponse::paginated(StockMove::query()->where('item_id', $item->id)->orderByDesc('date')->orderByDesc('id')->paginate(50));
    }

    public function adjustStock(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'quantity' => ['required', 'numeric'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'], 'account_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::created($this->invoices->adjustStock($item, $data, $request->user()));
    }

    public function invoicesIndex(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['sometimes', Rule::in(['sale', 'purchase'])],
            'status' => ['sometimes', Rule::in(['draft', 'pending', 'posted', 'cancelled'])], 'party_id' => ['sometimes', 'integer'],
            'overdue' => ['sometimes', 'boolean']]);
        $page = Invoice::query()
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['party_id'] ?? null, fn ($q, $v) => $q->where('party_id', $v))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', 'posted')->whereColumn('paid', '<', 'total')->where('due_date', '<', now()->toDateString()))
            ->orderByDesc('date')->orderByDesc('id')->paginate(50);
        $page->setCollection($page->getCollection()->map(fn (Invoice $i) => $this->invoiceRow($i)));

        return ApiResponse::paginated($page);
    }

    public function showInvoice(Invoice $invoice): JsonResponse
    {
        return ApiResponse::ok([...$this->invoiceRow($invoice), 'lines' => $invoice->lines()->orderBy('id')->get(),
            'payments' => Transaction::query()->where('invoice_id', $invoice->id)->orderBy('date')->get(),
            'attachments' => InvoiceAttachment::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()]);
    }

    /** Scan or photo of the paper invoice (images or PDF). Kept on the private disk; downloaded through the API only. */
    public function storeAttachment(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240']]);
        abort_if($invoice->status === 'cancelled', 409, 'الفاتورة ملغاة.');
        abort_if(InvoiceAttachment::query()->where('invoice_id', $invoice->id)->count() >= 10, 422, 'الحد الأقصى 10 مرفقات للفاتورة.');
        $file = $request->file('file');
        $path = $file->store('finance/invoices/'.$invoice->id, 'local');
        $attachment = InvoiceAttachment::create(['invoice_id' => $invoice->id, 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType(),
            'size' => $file->getSize(), 'uploaded_by' => $request->user()->id]);
        $this->audit->log('finance.invoice_attachment_added', $invoice, null, ['attachment_id' => $attachment->id], $request->user()->id);

        return ApiResponse::created($attachment);
    }

    public function downloadAttachment(Invoice $invoice, InvoiceAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->invoice_id === $invoice->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404, 'الملف غير موجود.');

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime],
            str_starts_with((string) $attachment->mime, 'image/') || $attachment->mime === 'application/pdf' ? 'inline' : 'attachment');
    }

    /** A posted invoice keeps its documents; attachments are removed only while it is still being prepared. */
    public function destroyAttachment(Request $request, Invoice $invoice, InvoiceAttachment $attachment): JsonResponse
    {
        abort_unless($attachment->invoice_id === $invoice->id, 404);
        abort_unless($invoice->status === 'draft', 409, 'تُحذف المرفقات من المسودة فقط.');
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
        $this->audit->log('finance.invoice_attachment_removed', $invoice, null, ['attachment_id' => $attachment->id], $request->user()->id);

        return ApiResponse::ok(null);
    }

    public function saveInvoice(Request $request, ?Invoice $invoice = null): JsonResponse
    {
        $data = $request->validate([
            'type' => [Rule::requiredIf(! $invoice), Rule::in(['sale', 'purchase'])], 'party_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'currency_code' => ['required', 'string', 'exists:sys_currencies,code'], 'notes' => ['nullable', 'string', 'max:1000'],
            'version' => [Rule::requiredIf((bool) $invoice), 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.account_id' => ['nullable', 'integer'], 'lines.*.description' => ['required_without:lines.*.item_id', 'nullable', 'string', 'max:300'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
        ]);
        $saved = $this->invoices->save($data, $request->user(), $invoice);

        return $invoice ? ApiResponse::ok($saved) : ApiResponse::created($saved);
    }

    public function submitInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        return ApiResponse::ok($this->invoiceRow($this->invoices->submit($invoice, $request->user())));
    }

    public function decideInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::ok($this->invoiceRow($this->invoices->decide($invoice, $data['decision'] === 'approve', $data['note'] ?? null, $request->user())));
    }

    public function cancelInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::ok($this->invoiceRow($this->invoices->cancel($invoice, $data['date'], $data['reason'], $request->user())));
    }

    // ── Reports (one currency each) ───────────────────────────────────────────

    public function report(Request $request, string $report): JsonResponse
    {
        $data = $request->validate([
            'currency' => [Rule::requiredIf($report !== 'box'), 'string', 'exists:sys_currencies,code'],
            'from' => [Rule::requiredIf($report !== 'balance-sheet'), 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'box_id' => [Rule::requiredIf($report === 'box'), 'integer', 'exists:fin_boxes,id'],
            'account_id' => [Rule::requiredIf($report === 'ledger'), 'integer', 'exists:fin_accounts,id'],
            'party_id' => [Rule::requiredIf($report === 'party'), 'integer', 'exists:fin_parties,id'],
        ]);
        $currency = isset($data['currency']) ? strtoupper($data['currency']) : null;

        return ApiResponse::ok(match ($report) {
            'box' => $this->reports->boxStatement(Box::findOrFail($data['box_id']), $data['from'], $data['to']),
            'ledger' => $this->reports->ledger(Account::findOrFail($data['account_id']), $currency, $data['from'], $data['to']),
            'party' => $this->reports->partyStatement(Party::findOrFail($data['party_id']), $currency, $data['from'], $data['to']),
            'trial-balance' => $this->reports->trialBalance($currency, $data['from'], $data['to']),
            'income-statement' => $this->reports->incomeStatement($currency, $data['from'], $data['to']),
            'balance-sheet' => $this->reports->balanceSheet($currency, $data['to']),
        });
    }

    private function invoiceRow(Invoice $invoice): array
    {
        $remaining = round((float) $invoice->total - (float) $invoice->paid, 2);

        return [...$invoice->toArray(), 'party_name' => Party::find($invoice->party_id)?->name, 'remaining' => $remaining,
            'payment_status' => $invoice->status !== 'posted' ? null : ($remaining <= 0 ? 'paid' : ((float) $invoice->paid > 0 ? 'partial' : 'unpaid')),
            'overdue' => $invoice->status === 'posted' && $remaining > 0 && $invoice->due_date->toDateString() < now()->toDateString()];
    }
}
