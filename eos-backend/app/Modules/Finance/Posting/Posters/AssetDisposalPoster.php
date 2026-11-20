<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\AssetDisposal;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Assets\AssetGuard;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Services\AuditLogger;

/**
 * Asset disposal (`meta.disposal_type`): the asset leaves the books at its cost (no depreciation, D22).
 * Sale (#24): Dr box price / Cr fixed_assets cost / the difference on asset_disposal_gain_loss (credit = gain,
 * debit = loss); the price must be in the asset's currency. Scrap or loss (#25): Dr asset_disposal_gain_loss /
 * Cr fixed_assets. Posting records the disposal and sets the asset sold | scrapped | lost; reversal deletes the
 * record and puts the asset back in service.
 */
class AssetDisposalPoster extends AbstractPoster
{
    private const STATUS = ['sale' => 'sold', 'scrap' => 'scrapped', 'loss' => 'lost'];

    public function __construct(SystemAccounts $roles, private AssetGuard $assets, private AuditLogger $audit)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $type = $this->type($voucher);
        $asset = $this->assets->operable($voucher->asset_id);
        if ($voucher->currency_id !== null && $voucher->currency_id !== $asset->currency_id) {
            $this->fail('currency', 'سعر البيع يُسجَّل بعملة الأصل نفسها.');
        }
        if ($type === 'sale') {
            $this->requireAmount($voucher);
            $this->box($voucher->cash_box_id, $asset->currency_id);
        } elseif ($voucher->cash_box_id !== null || ($voucher->amount !== null && ! Money::isZero((string) $voucher->amount))) {
            $this->fail('cash_box_id', 'الإتلاف أو الفقدان بلا صندوق ولا مبلغ.');
        }
    }

    public function lines(Voucher $voucher): array
    {
        $asset = Asset::query()->findOrFail($voucher->asset_id);
        $cost = (string) $asset->total_cost;
        $dims = ['asset_id' => $asset->id];
        $gainLoss = $this->roles->id('asset_disposal_gain_loss');
        $fixedAssets = LineDraft::credit($this->roles->id('fixed_assets'), $asset->currency_id, $cost, $dims, 'استبعاد '.$asset->code);
        if ($this->type($voucher) !== 'sale') {
            return [LineDraft::debit($gainLoss, $asset->currency_id, $cost, $dims, 'خسارة استبعاد '.$asset->code), $fixedAssets];
        }
        $price = (string) $voucher->amount;
        $box = $this->box($voucher->cash_box_id, $asset->currency_id);

        return array_values(array_filter([
            LineDraft::debit($box->account_id, $asset->currency_id, $price, [], $voucher->description),
            $fixedAssets,
            // Cost above price = loss (debit); price above cost = gain (credit).
            LineDraft::signed($gainLoss, $asset->currency_id, Money::sub($cost, $price), $dims, 'فرق بيع '.$asset->code),
        ]));
    }

    public function afterPost(Voucher $voucher): void
    {
        $type = $this->type($voucher);
        $asset = Asset::query()->lockForUpdate()->findOrFail($voucher->asset_id);
        AssetDisposal::create(['asset_id' => $asset->id, 'type' => $type, 'date' => $voucher->voucher_date->toDateString(),
            'sale_amount' => $type === 'sale' ? $voucher->amount : null, 'currency_id' => $asset->currency_id, 'voucher_id' => $voucher->id,
            'notes' => $voucher->meta['notes'] ?? null, 'created_by' => $voucher->approved_by, 'updated_by' => $voucher->approved_by]);
        $this->setStatus($asset, self::STATUS[$type], $voucher, $voucher->approved_by);
    }

    public function afterReverse(Voucher $voucher): void
    {
        $asset = Asset::query()->lockForUpdate()->findOrFail($voucher->asset_id);
        AssetDisposal::query()->where('voucher_id', $voucher->id)->delete();
        $this->setStatus($asset, 'in_service', $voucher, $voucher->reversed_by);
    }

    private function type(Voucher $voucher): string
    {
        $type = $voucher->meta['disposal_type'] ?? null;
        if (! in_array($type, array_keys(self::STATUS), true)) {
            $this->fail('meta.disposal_type', 'حدّد نوع الاستبعاد: بيع أو إتلاف أو فقدان.');
        }

        return $type;
    }

    private function setStatus(Asset $asset, string $status, Voucher $voucher, ?int $userId): void
    {
        $old = $asset->status;
        $asset->update(['status' => $status, 'updated_by' => $userId]);
        $this->audit->log('finance.asset_status_changed', $asset, ['status' => $old], ['status' => $status, 'voucher_id' => $voucher->id], $userId);
    }
}
