<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StorePartnerRequest;
use App\Modules\Finance\Models\Partner;
use App\Modules\Finance\Services\Parties\PartnerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Partners and their shares (D16). */
class PartnerController extends Controller
{
    public function __construct(private PartnerService $partners) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['active' => ['sometimes', 'boolean']]);

        return ApiResponse::ok($this->partners->list($request->has('active') ? $request->boolean('active') : null));
    }

    public function store(StorePartnerRequest $request): JsonResponse
    {
        return ApiResponse::created($this->partners->create($this->normalised($request), $request->user()));
    }

    public function update(StorePartnerRequest $request, Partner $partner): JsonResponse
    {
        return ApiResponse::ok($this->partners->update($partner, $this->normalised($request), $request->user()));
    }

    private function normalised(StorePartnerRequest $request): array
    {
        $data = $request->validated();
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $request->boolean('is_active');
        }

        return $data;
    }
}
