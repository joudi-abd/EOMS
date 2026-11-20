<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\PostingService;
use App\Modules\Finance\Services\Core\VoucherService;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Project billings from the project screen (§9.4 tab 7) under `finance.projects`: a `project_billing` voucher to the
 * project's client in the contract currency, posted by ProjectBillingPoster (#2). The same voucher type is also
 * accepted on POST /finance/vouchers (`finance.vouchers`).
 */
class ProjectBillingService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private ProjectAccess $access, private VoucherService $vouchers, private PostingService $posting,
        private ProjectBillingCalculator $calculator) {}

    /** @return list<array<string, mixed>> */
    public function list(int $projectId, User $user): array
    {
        $this->access->readableProfile($user, $projectId);

        return Voucher::query()->where('type', 'project_billing')->where('project_id', $projectId)->orderByDesc('voucher_date')->orderByDesc('id')
            ->get()->map(fn (Voucher $v) => [...$v->toArray(), 'display_number' => $v->number ?? 'مسودة #'.$v->id])->all();
    }

    public function suggest(int $projectId, User $user, ?string $date): array
    {
        return $this->calculator->suggest($this->access->readableProfile($user, $projectId), $date ?? now()->toDateString());
    }

    /** Creates the billing (posted unless `approve` is false). */
    public function create(int $projectId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($projectId, $data, $actor) {
            $project = $this->access->readable($actor, $projectId);
            $this->access->assertManage($actor, $project);
            $profile = $this->access->readableProfile($actor, $projectId);
            abort_if($profile->contract_type === 'internal', 422, 'المشروع داخلي ولا تُصدر له مطالبات.');
            $voucher = $this->vouchers->createInternal(['type' => 'project_billing', 'project_id' => $projectId, 'party_id' => $profile->client_party_id,
                'currency_id' => $profile->contract_currency_id, 'amount' => $data['amount'], 'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'branch_id' => $project->branch_id, 'description' => $data['description'] ?? null, 'reference_no' => $data['reference_no'] ?? null], $actor);
            if ($data['approve'] ?? true) {
                $voucher = $this->posting->approve($voucher, $actor);
            }

            return $this->vouchers->present($voucher);
        }, self::TX_ATTEMPTS);
    }
}
