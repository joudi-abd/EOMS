<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\AssetImportRequest;
use App\Modules\Finance\Http\Requests\AssetOpeningRequest;
use App\Modules\Finance\Http\Requests\AssetPurchaseRequest;
use App\Modules\Finance\Http\Requests\UpdateAssetRequest;
use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Assets\AssetGuard;
use App\Modules\Finance\Services\Assets\AssetImportService;
use App\Modules\Finance\Services\Assets\AssetReportService;
use App\Modules\Finance\Services\Assets\AssetService;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** The asset register (D22): list, card, descriptive edit, purchase, opening assets, CSV import, T20 data. */
class AssetController extends Controller
{
    public function __construct(private AssetService $assets, private VoucherService $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->assets->list($this->filters($request), $request->user()));
    }

    public function show(Request $request, Asset $asset): JsonResponse
    {
        return ApiResponse::ok($this->assets->card($asset, $request->user()));
    }

    public function update(UpdateAssetRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->assets->update($asset, $request->validated(), $request->user());

        return ApiResponse::ok($this->assets->card($asset, $request->user()));
    }

    public function destroy(Request $request, Asset $asset): JsonResponse
    {
        $this->assets->delete($asset, $request->user());

        return ApiResponse::message('تم حذف الأصل.');
    }

    /** Asset + asset_purchase voucher (#22), posted unless `approve` is false. */
    public function purchase(AssetPurchaseRequest $request): JsonResponse
    {
        $data = [...$request->validated(), 'approve' => $request->boolean('approve', true)];
        ['asset' => $asset, 'voucher' => $voucher] = $this->assets->purchase($data, $request->user());

        return $this->created($request, [$asset], $voucher);
    }

    /** An existing asset as an opening balance (#29). */
    public function opening(AssetOpeningRequest $request): JsonResponse
    {
        $data = $request->validated();
        ['assets' => $assets, 'voucher' => $voucher] = $this->assets->registerOpening([$data], $data['date'], (int) $data['branch_id'], $request->user());

        return $this->created($request, $assets, $voucher);
    }

    /** CSV of existing assets: all rows or none. */
    public function import(AssetImportRequest $request, AssetImportService $importer): JsonResponse
    {
        $data = $request->validated();
        ['assets' => $assets, 'voucher' => $voucher] = $importer->import($request->file('file'), $data['date'], (int) $data['branch_id'], $request->user());

        return ApiResponse::created(['count' => count($assets), 'assets' => $this->assets->presentMany(collect($assets)),
            'voucher' => $this->vouchers->present($voucher)]);
    }

    /** T20: the register grouped by status, holder, category or branch, with operating and maintenance expenses. */
    public function report(Request $request, AssetReportService $reports): JsonResponse
    {
        $filters = $this->filters($request) + $request->validate(['group_by' => ['sometimes', Rule::in(AssetReportService::GROUPS)]]);

        return ApiResponse::ok($reports->register($filters, $request->user()));
    }

    /** @param  list<Asset>  $assets */
    private function created(Request $request, array $assets, Voucher $voucher): JsonResponse
    {
        return ApiResponse::created([
            'asset' => count($assets) === 1 ? $this->assets->card($assets[0], $request->user()) : null,
            'voucher' => $this->vouchers->present($voucher),
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['sometimes', Rule::in(AssetGuard::STATUSES)], 'holder_employee_id' => ['sometimes', 'integer'],
            'category_id' => ['sometimes', 'integer'], 'branch_id' => ['sometimes', 'integer'], 'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
