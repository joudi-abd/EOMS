<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SystemAccountController extends Controller
{
    public function __construct(private SystemAccounts $roles) {}

    /** Every system role, its account, and `unmapped: true` for the roles still waiting for an account. */
    public function index(): JsonResponse
    {
        return ApiResponse::ok($this->roles->all());
    }

    /** Body: `{mappings: {role_key: account_id}}`. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['mappings' => ['required', 'array', 'min:1'], 'mappings.*' => ['required', 'integer']]);

        return ApiResponse::ok($this->roles->set($data['mappings'], $request->user()));
    }
}
