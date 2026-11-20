<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\BranchService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

#[Group('07 - Settings / Configuration', weight: 7)]
class BranchController extends Controller
{
    public function __construct(private BranchService $branches) {}

    /** List branches (head office first). `active=1` limits to active ones. */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->branches->list($request->boolean('active')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('sys_branches', 'code')],
            'is_head_office' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::created($this->branches->create($data));
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'code' => ['sometimes', 'string', 'max:20', 'alpha_dash', Rule::unique('sys_branches', 'code')->ignore($branch->id)],
            'is_head_office' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        foreach (['is_head_office', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        return ApiResponse::ok($this->branches->update($branch, $data));
    }
}
