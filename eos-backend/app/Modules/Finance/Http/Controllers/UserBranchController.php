<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Identity\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UserBranchController extends Controller
{
    public function __construct(private FinanceScope $scope) {}

    /** Branches assigned to an account: its reach for finance permissions held at "own" scope. */
    public function show(User $user): JsonResponse
    {
        return ApiResponse::ok(['user_id' => $user->id, 'branch_ids' => $this->scope->assignedBranches($user->id)]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['branch_ids' => ['present', 'array', 'max:100'], 'branch_ids.*' => ['integer', 'distinct']]);

        return ApiResponse::ok(['user_id' => $user->id, 'branch_ids' => $this->scope->assign($user, $data['branch_ids'], $request->user())]);
    }

    /** The signed-in account's reach: `branch_ids: null` means every branch. */
    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::ok(['assigned' => $this->scope->assignedBranches($request->user()->id),
            'branch_ids' => $this->scope->branchIds($request->user(), 'finance.view')]);
    }
}
