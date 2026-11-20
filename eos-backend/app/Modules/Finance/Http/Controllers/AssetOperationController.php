<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\AssetDisposalRequest;
use App\Modules\Finance\Http\Requests\AssetMaintenanceRequest;
use App\Modules\Finance\Http\Requests\AssetTransferRequest;
use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Assets\AssetGuard;
use App\Modules\Finance\Services\Assets\AssetOperationService;
use App\Modules\Finance\Services\Assets\AssetService;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Custody transfer, status toggle, maintenance (#23) and disposal (#24, #25) of an asset. */
class AssetOperationController extends Controller
{
    public function __construct(private AssetOperationService $operations, private AssetService $assets, private VoucherService $vouchers) {}

    public function transfer(AssetTransferRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->operations->transfer($asset, $request->validated(), $request->user());

        return ApiResponse::ok($this->assets->card($asset, $request->user()));
    }

    public function status(Request $request, Asset $asset): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(AssetGuard::ACTIVE)]]);
        $asset = $this->operations->setStatus($asset, $data['status'], $request->user());

        return ApiResponse::ok($this->assets->card($asset, $request->user()));
    }

    public function maintenance(AssetMaintenanceRequest $request, Asset $asset): JsonResponse
    {
        $data = [...$request->validated(), 'approve' => $request->boolean('approve', true)];

        return $this->created($request, $asset, $this->operations->maintain($asset, $data, $request->user()));
    }

    public function dispose(AssetDisposalRequest $request, Asset $asset): JsonResponse
    {
        $data = [...$request->validated(), 'approve' => $request->boolean('approve', true)];

        return $this->created($request, $asset, $this->operations->dispose($asset, $data, $request->user()));
    }

    private function created(Request $request, Asset $asset, Voucher $voucher): JsonResponse
    {
        return ApiResponse::created(['asset' => $this->assets->card($asset->refresh(), $request->user()), 'voucher' => $this->vouchers->present($voucher)]);
    }
}
