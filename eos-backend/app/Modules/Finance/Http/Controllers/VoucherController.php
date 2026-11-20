<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Http\Requests\StoreVoucherRequest;
use App\Modules\Finance\Models\Attachment;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\AttachmentService;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Settings\Services\ExchangeRateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoucherController extends Controller
{
    public function __construct(private VoucherService $vouchers, private AttachmentService $attachments, private FinanceScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(Voucher::TYPES)], 'purpose' => ['sometimes', Rule::in(Voucher::PURPOSES)],
            'status' => ['sometimes', Rule::in(Voucher::STATUSES)], 'box_id' => ['sometimes', 'integer'], 'party_id' => ['sometimes', 'integer'],
            'project_id' => ['sometimes', 'integer'], 'branch_id' => ['sometimes', 'integer'], 'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'], 'search' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::paginated($this->vouchers->list($filters, $request->user()));
    }

    public function show(Request $request, Voucher $voucher): JsonResponse
    {
        return ApiResponse::ok($this->vouchers->show($voucher, $request->user()));
    }

    /** Creates a draft; with `approve: true` it is posted in the same call. */
    public function store(StoreVoucherRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['approve'] = $request->boolean('approve');

        return ApiResponse::created($this->vouchers->present($this->vouchers->create($data, $request->user())));
    }

    public function update(StoreVoucherRequest $request, Voucher $voucher): JsonResponse
    {
        $data = $request->validated();
        $data['approve'] = $request->boolean('approve');

        return ApiResponse::ok($this->vouchers->present($this->vouchers->update($voucher, $data, $request->user())));
    }

    public function destroy(Request $request, Voucher $voucher): JsonResponse
    {
        $this->vouchers->delete($voucher, $request->user());

        return ApiResponse::message('حُذفت المسودة.');
    }

    public function submit(Request $request, Voucher $voucher): JsonResponse
    {
        $this->scope->assertBranch($request->user(), 'finance.vouchers', $voucher->branch_id);

        return ApiResponse::ok($this->vouchers->present($this->vouchers->submit($voucher, $request->user())));
    }

    public function approve(Request $request, Voucher $voucher): JsonResponse
    {
        return ApiResponse::ok($this->vouchers->present($this->vouchers->approve($voucher, $request->user())));
    }

    public function reject(Request $request, Voucher $voucher): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::ok($this->vouchers->present($this->vouchers->reject($voucher, $data['reason'], $request->user())));
    }

    public function reverse(Request $request, Voucher $voucher): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::ok($this->vouchers->present($this->vouchers->reverse($voucher, $data['date'], $data['reason'], $request->user())));
    }

    /** The bulletin cross rate (units of `to` per one `from`) on or before `date`, to prefill an exchange (R10). */
    public function suggestRate(Request $request, ExchangeRateService $rates): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'string', 'exists:sys_currencies,code'], 'to' => ['required', 'string', 'exists:sys_currencies,code'],
            'date' => ['sometimes', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? now()->toDateString();

        return ApiResponse::ok(['from' => strtoupper($data['from']), 'to' => strtoupper($data['to']), 'date' => $date,
            'rate' => $rates->crossRateOnOrBefore($data['from'], $data['to'], $date)]);
    }

    public function storeAttachment(Request $request, Voucher $voucher): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:'.implode(',', AttachmentService::MIMES), 'max:'.AttachmentService::MAX_KB]]);
        $this->scope->assertBranch($request->user(), 'finance.vouchers', $voucher->branch_id);
        abort_if(in_array($voucher->status, ['rejected', 'reversed'], true), 409, 'لا تُضاف مرفقات لسند مرفوض أو معكوس.');

        return ApiResponse::created($this->attachments->store($voucher, $request->file('file'), $request->user()));
    }

    public function downloadAttachment(Request $request, Voucher $voucher, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->attachable_type === $voucher->getMorphClass() && $attachment->attachable_id === $voucher->id, 404);
        $this->scope->assertBranch($request->user(), 'finance.view', $voucher->branch_id);

        return $this->attachments->download($attachment);
    }

    public function destroyAttachment(Request $request, Voucher $voucher, Attachment $attachment): JsonResponse
    {
        abort_unless($attachment->attachable_type === $voucher->getMorphClass() && $attachment->attachable_id === $voucher->id, 404);
        abort_unless($voucher->status === 'draft', 409, 'تُحذف المرفقات من المسودة فقط.');
        $this->scope->assertBranch($request->user(), 'finance.vouchers', $voucher->branch_id);
        $this->attachments->delete($attachment, $request->user());

        return ApiResponse::message('حُذف المرفق.');
    }
}
