<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreCashCountRequest;
use App\Modules\Finance\Models\Attachment;
use App\Modules\Finance\Models\CashCount;
use App\Modules\Finance\Services\Core\AttachmentService;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Treasury\CashCountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashCountController extends Controller
{
    public function __construct(private CashCountService $counts, private AttachmentService $attachments, private FinanceScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['box_id' => ['sometimes', 'integer'], 'status' => ['sometimes', Rule::in(['draft', 'submitted', 'approved', 'rejected'])],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::paginated($this->counts->list($request->user(), $filters));
    }

    /** One count; `stale: true` when the box moved after submit (R23) and approval waits for a new capture. */
    public function show(Request $request, CashCount $count): JsonResponse
    {
        $this->scope->assertBranch($request->user(), 'finance.view', $count->branch_id);

        return ApiResponse::ok($this->counts->present($count));
    }

    public function store(StoreCashCountRequest $request): JsonResponse
    {
        return ApiResponse::created($this->counts->present($this->counts->create($request->validated(), $request->user())));
    }

    public function update(StoreCashCountRequest $request, CashCount $count): JsonResponse
    {
        return ApiResponse::ok($this->counts->present($this->counts->update($count, $request->validated(), $request->user())));
    }

    public function destroy(Request $request, CashCount $count): JsonResponse
    {
        $this->counts->delete($count, $request->user());

        return ApiResponse::message('حُذف الجرد.');
    }

    public function submit(Request $request, CashCount $count): JsonResponse
    {
        return ApiResponse::ok($this->counts->present($this->counts->submit($count, $request->user())));
    }

    public function resnapshot(Request $request, CashCount $count): JsonResponse
    {
        return ApiResponse::ok($this->counts->present($this->counts->resnapshot($count, $request->user())));
    }

    public function approve(Request $request, CashCount $count): JsonResponse
    {
        return ApiResponse::ok($this->counts->present($this->counts->approve($count, $request->user())));
    }

    public function reject(Request $request, CashCount $count): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::ok($this->counts->present($this->counts->reject($count, $data['reason'], $request->user())));
    }

    /** The exchange office's statement scan or a photo of the count. */
    public function storeAttachment(Request $request, CashCount $count): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:'.implode(',', AttachmentService::MIMES), 'max:'.AttachmentService::MAX_KB]]);
        $this->scope->assertBranch($request->user(), 'finance.counts', $count->branch_id);
        abort_unless(in_array($count->status, ['draft', 'submitted'], true), 409, 'الجرد محسوم.');

        return ApiResponse::created($this->attachments->store($count, $request->file('file'), $request->user()));
    }

    public function downloadAttachment(Request $request, CashCount $count, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->attachable_type === $count->getMorphClass() && $attachment->attachable_id === $count->id, 404);
        $this->scope->assertBranch($request->user(), 'finance.view', $count->branch_id);

        return $this->attachments->download($attachment);
    }
}
