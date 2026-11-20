<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\Core\AccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    /** The chart as a tree (`active=1` hides inactive accounts). Each node lists the system roles it plays and its box. */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->accounts->tree($request->boolean('active')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('fin_accounts', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'parent_id' => ['nullable', 'integer', 'exists:fin_accounts,id'],
            'is_postable' => ['sometimes', 'boolean'],
            'requires_party' => ['sometimes', 'boolean'],
            'requires_project' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::created($this->accounts->create($this->flags($request, $data), $request->user()));
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:30', Rule::unique('fin_accounts', 'code')->ignore($account->id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:fin_accounts,id'],
            'is_postable' => ['sometimes', 'boolean'],
            'requires_party' => ['sometimes', 'boolean'],
            'requires_project' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::ok($this->accounts->update($account, $this->flags($request, $data), $request->user()));
    }

    public function deactivate(Request $request, Account $account): JsonResponse
    {
        return ApiResponse::ok($this->accounts->deactivate($account, $request->user()));
    }

    /** `boolean` accepts 0/"0"/"false"; the service compares strictly, so normalise first. */
    private function flags(Request $request, array $data): array
    {
        foreach (['is_postable', 'requires_party', 'requires_project', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        return $data;
    }
}
