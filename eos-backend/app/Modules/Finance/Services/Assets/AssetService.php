<?php

namespace App\Modules\Finance\Services\Assets;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\AssetCategory;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\PostingService;
use App\Modules\Finance\Services\Core\Sequences;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Currency;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The asset register (D22, spec §5.8, §9.7): list, asset card, descriptive edits, purchase from the asset screen
 * (#22, code AS-0001) and existing assets entered as opening balances (#29, spec §12 step 7). No depreciation ever:
 * book value = purchase cost until disposal. Cost, currency and status change only through vouchers.
 */
class AssetService
{
    private const TX_ATTEMPTS = 3;

    /** Descriptive fields an asset card may edit. */
    private const EDITABLE = ['name', 'category_id', 'serial_no', 'notes'];

    public function __construct(private VoucherService $vouchers, private PostingService $posting, private FinanceScope $scope,
        private Sequences $sequences, private SystemAccounts $roles, private AssetHolders $holders, private AssetExpenses $expenses,
        private AssetCategoryService $categories, private AuditLogger $audit) {}

    /**
     * The register in the caller's branches. Filters: status (cancelled assets only when asked for), holder_employee_id,
     * category_id (with its sub-categories), branch_id, search (code, name, serial).
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, User $user): LengthAwarePaginator
    {
        $page = $this->filtered(Asset::query(), $filters, $user, 'finance.view')->orderByDesc('id')
            ->paginate(min(100, (int) ($filters['per_page'] ?? 25)));
        $page->setCollection(collect($this->presentMany($page->getCollection())));

        return $page;
    }

    /** The register query with the shared filters and the branch reach of `$permission` (also used by T20). */
    public function filtered(Builder $query, array $filters, User $user, string $permission): Builder
    {
        return $this->scope->apply($query, $user, $permission)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v), fn ($q) => $q->where('status', '!=', AssetGuard::CANCELLED))
            ->when($filters['holder_employee_id'] ?? null, fn ($q, $v) => $q->where('current_holder_employee_id', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->whereIn('category_id', $this->categories->subtree((int) $v)))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('code', 'like', "%$v%")
                ->orWhere('name', 'like', "%$v%")->orWhere('serial_no', 'like', "%$v%")));
    }

    /** The asset card: data, custody history, maintenance, disposals and linked operating expenses with totals per currency. */
    public function card(Asset $asset, User $user): array
    {
        $this->scope->assertBranch($user, 'finance.view', $asset->branch_id);
        $asset->load(['custodyHistory' => fn ($q) => $q->orderByDesc('transferred_at')->orderByDesc('id'),
            'maintenance' => fn ($q) => $q->orderByDesc('date')->orderByDesc('id'), 'maintenance.voucher', 'disposals.voucher']);
        $people = $this->holders->describe($asset->custodyHistory->flatMap(fn ($h) => [$h->from_employee_id, $h->to_employee_id]));
        $totals = $this->expenses->totals([$asset->id])[$asset->id];

        return [
            ...$this->presentMany(collect([$asset]))[0],
            'custody_history' => $asset->custodyHistory->map(fn ($h) => [...$h->only(['id', 'transferred_at', 'notes', 'recorded_by']),
                'transferred_at' => $h->transferred_at?->toDateString(),
                'from' => $people[$h->from_employee_id] ?? null, 'to' => $people[$h->to_employee_id] ?? null])->values()->all(),
            'maintenance' => $asset->maintenance->map(fn ($m) => [...$m->only(['id', 'description', 'project_id', 'voucher_id']),
                'date' => $m->date?->toDateString(), 'voucher' => $this->voucherSummary($m->voucher)])->values()->all(),
            'disposals' => $asset->disposals->map(fn ($d) => [...$d->only(['id', 'type', 'sale_amount', 'notes', 'voucher_id']),
                'date' => $d->date?->toDateString(), 'currency' => $d->currency_id === null ? null : $this->currencyCode($d->currency_id),
                'voucher' => $this->voucherSummary($d->voucher)])->values()->all(),
            'expenses' => ['lines' => $this->expenses->lines($asset->id), 'totals' => $totals],
        ];
    }

    /** Descriptive fields only (name, category, serial, notes); cost, currency and status change through vouchers. */
    public function update(Asset $asset, array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            $this->scope->assertBranch($actor, 'finance.assets', $asset->branch_id);
            $changes = array_intersect_key($data, array_flip(self::EDITABLE));
            $old = array_intersect_key($asset->getAttributes(), $changes);
            $asset->update([...$changes, 'updated_by' => $actor->id]);
            $this->audit->log('finance.asset_updated', $asset, $old, $changes, $actor->id);

            return $asset->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Purchase from the asset screen (D22, #22): creates the asset (in service, code AS-0001) and its asset_purchase
     * voucher on the chosen box; posted at once unless `approve` is false (the draft then waits on the voucher screen).
     *
     * @return array{asset: Asset, voucher: Voucher}
     */
    public function purchase(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $currency = $this->currency($data);
            $branchId = (int) $data['branch_id'];
            $this->scope->assertBranch($actor, 'finance.assets', $branchId);
            $box = CashBox::query()->find($data['cash_box_id'] ?? null)
                ?? throw ValidationException::withMessages(['cash_box_id' => ['الصندوق غير موجود.']]);
            $this->scope->assertBranch($actor, 'finance.assets', $box->branch_id);
            $asset = $this->makeAsset($data, $currency, $branchId, $actor);
            $voucher = $this->vouchers->createInternal(['type' => 'asset_purchase', 'voucher_date' => $asset->purchase_date->toDateString(),
                'branch_id' => $branchId, 'cash_box_id' => $box->id, 'currency_id' => $currency->id, 'amount' => (string) $asset->total_cost,
                'asset_id' => $asset->id, 'reference_no' => $data['reference_no'] ?? null,
                'description' => Str::limit($data['description'] ?? 'شراء أصل '.$asset->code.' — '.$asset->name, 495)], $actor);
            if ($data['approve'] ?? true) {
                $voucher = $this->posting->approve($voucher, $actor);
            }

            return ['asset' => $asset->refresh(), 'voucher' => $voucher];
        }, self::TX_ATTEMPTS);
    }

    /**
     * Existing assets without cash (spec §12 step 7): each asset plus one posted opening_balance voucher with a line
     * Dr fixed_assets (asset) per asset; OpeningBalancePoster adds the opening_balance_equity counter line per currency (#29).
     *
     * @param  list<array<string, mixed>>  $rows  asset fields; currency_id resolved, category_id, holder_employee_id, status
     * @return array{assets: list<Asset>, voucher: Voucher}
     */
    public function registerOpening(array $rows, string $date, int $branchId, User $actor): array
    {
        return DB::transaction(function () use ($rows, $date, $branchId, $actor) {
            $this->scope->assertBranch($actor, 'finance.assets', $branchId);
            $fixedAssets = $this->roles->id('fixed_assets');
            $assets = [];
            $lines = [];
            foreach ($rows as $row) {
                $asset = $this->makeAsset([...$row, 'purchase_date' => $row['purchase_date'] ?? $date], $this->currency($row), $branchId, $actor);
                $assets[] = $asset;
                $lines[] = ['account_id' => $fixedAssets, 'asset_id' => $asset->id, 'currency_id' => $asset->currency_id,
                    'debit' => (string) $asset->total_cost, 'memo' => Str::limit('رصيد افتتاحي — '.$asset->code.' '.$asset->name, 495)];
            }
            $voucher = $this->vouchers->createInternal(['type' => 'opening_balance', 'voucher_date' => $date, 'branch_id' => $branchId,
                'description' => 'أصول قائمة (رصيد افتتاحي)', 'lines' => $lines], $actor);
            $voucher = $this->posting->approve($voucher, $actor);
            Asset::query()->whereIn('id', array_map(fn (Asset $a) => $a->id, $assets))->update(['purchase_voucher_id' => $voucher->id]);

            return ['assets' => array_map(fn (Asset $a) => $a->refresh(), $assets), 'voucher' => $voucher];
        }, self::TX_ATTEMPTS);
    }

    /**
     * Removes an asset whose purchase was never posted (a draft from the asset screen, or one whose draft voucher was
     * deleted). Draft purchase vouchers of the asset are deleted with it.
     */
    public function delete(Asset $asset, User $actor): void
    {
        DB::transaction(function () use ($asset, $actor) {
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            $this->scope->assertBranch($actor, 'finance.assets', $asset->branch_id);
            $vouchers = Voucher::query()->where('asset_id', $asset->id)->orderBy('id')->get();
            abort_if($asset->purchase_voucher_id !== null || $vouchers->contains(fn (Voucher $v) => $v->status !== 'draft')
                || JournalLine::query()->where('asset_id', $asset->id)->exists(), 409,
                'لا يُحذف أصل له سندات معتمدة؛ اعكس سند الشراء بدلاً من ذلك.');
            foreach ($vouchers as $voucher) {
                $this->vouchers->delete($voucher, $actor);
            }
            $this->audit->log('finance.asset_deleted', $asset, $asset->getAttributes(), null, $actor->id);
            $asset->custodyHistory()->delete();
            $asset->delete();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Register rows with category, currency, branch, holder, book value and acquisition voucher, loaded in batches.
     *
     * @param  Collection<int, Asset>  $assets
     * @return list<array<string, mixed>>
     */
    public function presentMany(Collection $assets): array
    {
        $categories = AssetCategory::query()->whereIn('id', $assets->pluck('category_id')->filter()->unique())->pluck('name', 'id');
        $currencies = Currency::query()->whereIn('id', $assets->pluck('currency_id')->unique())->pluck('code', 'id');
        $branches = Branch::query()->whereIn('id', $assets->pluck('branch_id')->unique())->pluck('name', 'id');
        $holders = $this->holders->describe($assets->pluck('current_holder_employee_id'));
        $acquisitions = Voucher::query()->whereIn('id', $assets->pluck('purchase_voucher_id')->filter()->unique())->get()->keyBy('id');
        $pending = Voucher::query()->where('type', 'asset_purchase')->whereIn('status', ['draft', 'submitted'])
            ->whereIn('asset_id', $assets->whereNull('purchase_voucher_id')->pluck('id'))->orderBy('id')->get()->keyBy('asset_id');

        return $assets->map(function (Asset $asset) use ($categories, $currencies, $branches, $holders, $acquisitions, $pending) {
            $acquisition = $asset->purchase_voucher_id !== null ? $acquisitions->get($asset->purchase_voucher_id) : $pending->get($asset->id);
            $onBooks = in_array($asset->status, AssetGuard::ACTIVE, true) && $acquisition?->status === 'approved';

            return [
                ...$asset->toArray(),
                'category' => $asset->category_id === null ? null : ['id' => $asset->category_id, 'name' => $categories[$asset->category_id] ?? null],
                'currency' => $currencies[$asset->currency_id] ?? null,
                'branch' => ['id' => $asset->branch_id, 'name' => $branches[$asset->branch_id] ?? null],
                'holder' => $holders[$asset->current_holder_employee_id] ?? null,
                'book_value' => $onBooks ? Money::of((string) $asset->total_cost) : '0',
                'acquisition' => $this->voucherSummary($acquisition),
            ];
        })->values()->all();
    }

    /** A new asset row: code AS-0001…, cost = quantity × unit cost rounded to the currency, holder checked in HR. */
    private function makeAsset(array $data, Currency $currency, int $branchId, User $actor): Asset
    {
        $holder = $this->holders->assertAssignable(isset($data['holder_employee_id']) ? (int) $data['holder_employee_id'] : null);
        $quantity = Money::of((string) ($data['quantity'] ?? '1'));
        $unitCost = Money::of((string) $data['unit_cost']);
        $total = Money::round(Money::mul($quantity, $unitCost, 8), (int) $currency->decimals);
        if (! Money::isPositive($quantity) || ! Money::isPositive($total)) {
            throw ValidationException::withMessages(['unit_cost' => ['الكمية والتكلفة يجب أن تكونا أكبر من صفر.']]);
        }
        $date = (string) ($data['purchase_date'] ?? now()->toDateString());
        $asset = Asset::create([
            'code' => $this->sequences->next('asset', $date), 'name' => $data['name'], 'category_id' => $data['category_id'] ?? null,
            'purchase_date' => $date, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_cost' => $total, 'currency_id' => $currency->id,
            'current_holder_employee_id' => $holder?->id, 'branch_id' => $branchId,
            'status' => in_array($data['status'] ?? null, AssetGuard::ACTIVE, true) ? $data['status'] : 'in_service',
            'serial_no' => $data['serial_no'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $this->audit->log('finance.asset_created', $asset, null, $asset->getAttributes(), $actor->id);

        return $asset;
    }

    /** An active currency from `currency_id` or `currency` (code). */
    public function currency(array $data): Currency
    {
        $currency = ! empty($data['currency_id'])
            ? Currency::query()->find($data['currency_id'])
            : Currency::query()->where('code', strtoupper((string) ($data['currency'] ?? '')))->first();
        if ($currency === null || ! $currency->is_active) {
            throw ValidationException::withMessages(['currency' => ['العملة غير موجودة أو غير مفعّلة.']]);
        }

        return $currency;
    }

    private function currencyCode(int $currencyId): ?string
    {
        return Currency::query()->whereKey($currencyId)->value('code');
    }

    private function voucherSummary(?Voucher $voucher): ?array
    {
        return $voucher === null ? null : ['id' => $voucher->id, 'type' => $voucher->type, 'number' => $voucher->number ?? 'مسودة #'.$voucher->id,
            'status' => $voucher->status, 'date' => $voucher->voucher_date?->toDateString(), 'amount' => $voucher->amount,
            'currency' => $voucher->currency_id === null ? null : $this->currencyCode($voucher->currency_id)];
    }
}
