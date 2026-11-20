<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JournalEntryController extends Controller
{
    public function __construct(private FinanceScope $scope) {}

    /** Posted entries with their lines (T3 base), newest first. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
            'account_id' => ['sometimes', 'integer'], 'source_type' => ['sometimes', 'string', 'max:40'], 'source_id' => ['sometimes', 'integer'],
            'branch_id' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->scope->apply(JournalEntry::query(), $request->user(), 'finance.view')->with('lines')
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('entry_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('entry_date', '<=', $v))
            ->when($data['account_id'] ?? null, fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $v)))
            ->when($data['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', $v))
            ->when($data['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->when($data['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->orderByDesc('entry_date')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return ApiResponse::paginated($page);
    }

    public function show(Request $request, JournalEntry $entry): JsonResponse
    {
        $this->scope->assertBranch($request->user(), 'finance.view', $entry->branch_id);

        return ApiResponse::ok($entry->load('lines'));
    }
}
