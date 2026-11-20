<?php

namespace App\Modules\Finance\Services\Assets;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\Voucher;
use Illuminate\Validation\ValidationException;

/**
 * Which assets accept operations (custody transfer, maintenance, status change, disposal): an asset in service or
 * under maintenance whose acquisition (purchase #22 or opening balance #29) is posted and not reversed. A sold,
 * scrapped, lost or cancelled asset accepts nothing more (spec §5.8). Used by the asset services and the posters.
 */
class AssetGuard
{
    /** In service or under maintenance. */
    public const ACTIVE = ['in_service', 'under_maintenance'];

    /** Removed from the books by a disposal voucher (#24, #25). */
    public const DISPOSED = ['sold', 'scrapped', 'lost'];

    /** The purchase voucher was reversed: the asset never entered the books. Not in the spec's list (see docs). */
    public const CANCELLED = 'cancelled';

    /** Statuses the register accepts as a filter. */
    public const STATUSES = [...self::ACTIVE, ...self::DISPOSED, self::CANCELLED];

    /** The asset, locked, when it accepts an operation; 422 on `$field` otherwise. */
    public function operable(?int $assetId, string $field = 'asset_id', bool $lock = true): Asset
    {
        $query = Asset::query();
        $asset = $assetId === null ? null : ($lock ? $query->lockForUpdate()->find($assetId) : $query->find($assetId));
        if ($asset === null) {
            $this->fail($field, 'الأصل غير موجود.');
        }
        if (! in_array($asset->status, self::ACTIVE, true)) {
            $this->fail($field, 'الأصل '.$asset->code.' مستبعد أو ملغى؛ لا يقبل عمليات جديدة.');
        }
        if (! $this->acquired($asset)) {
            $this->fail($field, 'اعتمد سند شراء الأصل '.$asset->code.' أولاً.');
        }

        return $asset;
    }

    /** The acquisition voucher is posted and not reversed. */
    public function acquired(Asset $asset): bool
    {
        return $asset->purchase_voucher_id !== null
            && Voucher::query()->whereKey($asset->purchase_voucher_id)->where('status', 'approved')->exists();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
