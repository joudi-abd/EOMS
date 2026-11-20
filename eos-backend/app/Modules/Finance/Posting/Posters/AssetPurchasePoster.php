<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Assets\AssetGuard;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Services\AuditLogger;

/**
 * Asset purchase from the asset screen (#22, D22): Dr fixed_assets (asset) / Cr box, for the asset's total cost in
 * its currency. The asset row is created first by AssetService::purchase(); posting links it as its purchase voucher.
 * Reversal takes the asset out of the books (status `cancelled`) and is refused once the asset has a custody transfer,
 * a maintenance, a disposal or other live entries on it — reverse those first.
 */
class AssetPurchasePoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $amount = $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $asset = $voucher->asset_id === null ? null : Asset::query()->lockForUpdate()->find($voucher->asset_id);
        if ($asset === null || $asset->status === AssetGuard::CANCELLED) {
            $this->fail('asset_id', 'الأصل غير موجود أو ملغى؛ اشترِ الأصل من شاشة الأصول.');
        }
        if ($asset->purchase_voucher_id !== null && $asset->purchase_voucher_id !== $voucher->id) {
            $this->fail('asset_id', 'الأصل '.$asset->code.' مسجَّل بسند آخر.');
        }
        if ($voucher->currency_id !== $asset->currency_id || Money::cmp($amount, (string) $asset->total_cost) !== 0) {
            $this->fail('amount', 'مبلغ السند وعملته يجب أن يطابقا تكلفة الأصل '.$asset->code.'.');
        }
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $amount = (string) $voucher->amount;
        $code = Asset::query()->whereKey($voucher->asset_id)->value('code');

        return [
            LineDraft::debit($this->roles->id('fixed_assets'), $voucher->currency_id, $amount, ['asset_id' => $voucher->asset_id], 'شراء أصل '.$code),
            LineDraft::credit($box->account_id, $voucher->currency_id, $amount, [], $voucher->description),
        ];
    }

    public function afterPost(Voucher $voucher): void
    {
        Asset::query()->whereKey($voucher->asset_id)->update(['purchase_voucher_id' => $voucher->id]);
    }

    public function afterReverse(Voucher $voucher): void
    {
        $asset = Asset::query()->lockForUpdate()->findOrFail($voucher->asset_id);
        $otherLines = JournalLine::query()->where('asset_id', $asset->id)
            ->whereNotIn('journal_entry_id', array_filter([$voucher->journal_entry_id, $voucher->reversal_entry_id]))
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereNull('reversal_of_id'))->exists();
        abort_if($asset->custodyHistory()->exists() || $asset->maintenance()->exists() || $asset->disposals()->exists() || $otherLines, 409,
            'للأصل '.$asset->code.' عمليات لاحقة (نقل عهدة أو صيانة أو استبعاد أو مصاريف)؛ اعكسها أولاً.');
        $old = $asset->status;
        $asset->update(['status' => AssetGuard::CANCELLED, 'updated_by' => $voucher->reversed_by]);
        app(AuditLogger::class)->log('finance.asset_cancelled', $asset, ['status' => $old], ['status' => AssetGuard::CANCELLED,
            'voucher' => $voucher->number], $voucher->reversed_by);
    }
}
