<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreSiteRequestRequest;
use App\Modules\Finance\Models\Attachment;
use App\Modules\Finance\Models\SiteRequest;
use App\Modules\Finance\Services\Core\AttachmentService;
use App\Modules\Finance\Services\Expenses\SiteRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Site expense requests (R24/R25): supervisors submit and follow theirs; finance decides from its inbox. */
class SiteRequestController extends Controller
{
    public function __construct(private SiteRequestService $requests, private AttachmentService $attachments) {}

    /** Finance inbox (`finance.vouchers`), pending first. */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->requests->inbox($request->user(), $this->filters($request)));
    }

    /** "طلباتي": the caller's own requests (`finance.site_requests`). */
    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->requests->mine($request->user(), $this->filters($request)));
    }

    public function show(Request $request, SiteRequest $siteRequest): JsonResponse
    {
        $this->requests->assertVisible($siteRequest, $request->user());

        return ApiResponse::ok($this->requests->present($siteRequest));
    }

    public function store(StoreSiteRequestRequest $request): JsonResponse
    {
        $data = $request->safe()->except('invoice');

        return ApiResponse::created($this->requests->present($this->requests->submit($data, $request->file('invoice'), $request->user())));
    }

    public function approve(Request $request, SiteRequest $siteRequest): JsonResponse
    {
        $data = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);

        return ApiResponse::ok($this->requests->present($this->requests->approve($siteRequest, $request->user(), $data['date'] ?? null)));
    }

    public function reject(Request $request, SiteRequest $siteRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::ok($this->requests->present($this->requests->reject($siteRequest, $data['reason'], $request->user())));
    }

    public function downloadAttachment(Request $request, SiteRequest $siteRequest, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->attachable_type === $siteRequest->getMorphClass() && $attachment->attachable_id === $siteRequest->id, 404);
        $this->requests->assertVisible($siteRequest, $request->user());

        return $this->attachments->download($attachment);
    }

    private function filters(Request $request): array
    {
        return $request->validate(['status' => ['sometimes', Rule::in(['submitted', 'approved', 'rejected'])], 'project_id' => ['sometimes', 'integer'],
            'payment_source' => ['sometimes', Rule::in(SiteRequestService::SOURCES)], 'requested_by' => ['sometimes', 'integer'],
            'branch_id' => ['sometimes', 'integer'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
    }
}
