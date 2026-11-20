<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\ContractorAgreement;
use App\Modules\Finance\Models\Custody;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Models\ProjectBudgetLine;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\Sequences;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Currency;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The project's finance profile (§5.6, D9): the general project lives in the Projects module (name, status, branch,
 * manager, dates); finance adds its code (sequence `project`), client, contract type, contract currency and value, fee
 * percentage and the categories the cost-plus fee is taken on.
 *
 * R14: contract type, fee percentage (and, conservatively, contract currency and client) are fixed once a billing is
 * approved, until every billing is reversed.
 */
class ProjectFinanceService
{
    private const TX_ATTEMPTS = 3;

    private const PROJECT_FIELDS = ['name', 'status', 'branch_id', 'manager_user_id', 'start_date', 'end_date', 'notes'];

    private const PROFILE_FIELDS = ['client_party_id', 'contract_type', 'contract_currency_id', 'contract_value', 'fee_pct', 'fee_base_category_ids'];

    /** Fixed after the first approved billing (R14 + the currency and client the billings were made in). */
    private const LOCKED_AFTER_BILLING = ['contract_type', 'fee_pct', 'contract_currency_id', 'client_party_id'];

    public function __construct(private ProjectService $projects, private ProjectAccess $access, private Sequences $sequences,
        private ProjectProfitability $profitability, private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $filters */
    public function list(array $filters, User $user): LengthAwarePaginator
    {
        $page = $this->query($filters, $user)->orderByDesc('id')->paginate(min(100, (int) ($filters['per_page'] ?? 25)));
        $projects = $this->projects->query()->whereIn('id', collect($page->items())->pluck('project_id'))->get()->keyBy('id');

        return $page->through(fn (ProjectProfile $p) => $this->present($p, $projects[$p->project_id]));
    }

    /**
     * T8 rows (projects summary): receipts, costs, remaining, fee %, fee, profit and status of every reachable project.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function summary(array $filters, User $user, ?string $date = null): array
    {
        $profiles = $this->query($filters, $user)->orderBy('code')->get();
        $projects = $this->projects->query()->whereIn('id', $profiles->pluck('project_id'))->get()->keyBy('id');

        return $profiles->map(function (ProjectProfile $profile) use ($projects, $date) {
            $p = $this->profitability->for($profile, $date);
            $project = $projects[$profile->project_id];

            return ['project_id' => $project->id, 'code' => $profile->code, 'name' => $project->name, 'status' => $project->status,
                'branch_id' => $project->branch_id, 'client' => $profile->client?->name, 'contract_type' => $profile->contract_type,
                'currency' => $p['currency'], 'receipts' => $p['receipts'], 'costs' => $p['total_costs'],
                'remaining' => Money::sub($p['receipts'], $p['total_costs']), 'fee_pct' => $p['fee_pct'], 'fee' => $p['fee'],
                'revenue' => $p['revenue'], 'profit' => $p['net_margin'], 'client_balance' => $p['client_balance']];
        })->values()->all();
    }

    /** The profile with the project and a summary per tab (§9.4). */
    public function show(int $projectId, User $user): array
    {
        $profile = $this->access->readableProfile($user, $projectId);
        $project = $this->projects->find($projectId)->load('members');
        $budgets = ProjectBudgetLine::query()->where('project_id', $projectId)->get();
        $agreements = ContractorAgreement::query()->where('project_id', $projectId)->get();
        $custodies = Custody::query()->where('project_id', $projectId)->where('status', 'open')->get();
        try {
            $profitability = $this->profitability->for($profile);
        } catch (ValidationException $e) {
            $profitability = ['error' => collect($e->errors())->flatten()->first()];
        }

        return [
            ...$this->present($profile, $project),
            'project' => $project,
            'summary' => [
                'profitability' => $profitability,
                'budget' => ['lines' => $budgets->count(), 'warning' => $budgets->where('alert_level', 'warning')->count(),
                    'exceeded' => $budgets->where('alert_level', 'exceeded')->count()],
                'agreements' => ['count' => $agreements->count(), 'open' => $agreements->where('status', 'open')->count()],
                'custodies' => ['open' => $custodies->count(), 'balances' => $custodies->groupBy('currency_id')
                    ->map(fn ($rows, $currencyId) => ['currency' => Currency::query()->whereKey($currencyId)->value('code'),
                        'balance' => Money::sum($rows->map(fn ($c) => (string) $c->balance))])->values()->all()],
                'billings' => ['approved' => Voucher::query()->where('type', 'project_billing')->where('project_id', $projectId)
                    ->where('status', 'approved')->count()],
            ],
        ];
    }

    /** Creates the general project through ProjectService (or links `project_id`, an existing one) and its finance profile. */
    public function create(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            if (! empty($data['project_id'])) {
                $project = $this->access->readable($actor, (int) $data['project_id']);
                $this->access->assertManage($actor, $project);
                abort_if(ProjectProfile::query()->where('project_id', $project->id)->exists(), 409, 'للمشروع ملف مالي مسبقاً.');
            } else {
                $project = $this->projects->create(array_intersect_key($data, array_flip(self::PROJECT_FIELDS)), $actor);
            }
            $values = $this->check(array_intersect_key($data, array_flip(self::PROFILE_FIELDS)));
            $profile = ProjectProfile::create([...$values, 'project_id' => $project->id,
                'code' => $this->sequences->next('project', now()->toDateString()), 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->audit->log('finance.project_profile_created', $profile, null, $profile->getAttributes(), $actor->id);

            return $this->present($profile->refresh(), $project->refresh());
        }, self::TX_ATTEMPTS);
    }

    public function update(int $projectId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($projectId, $data, $actor) {
            $project = $this->access->readable($actor, $projectId);
            $this->access->assertManage($actor, $project);
            $profile = ProjectProfile::query()->where('project_id', $projectId)->lockForUpdate()->first();
            abort_if($profile === null, 404, 'لا يوجد ملف مالي لهذا المشروع.');
            $changes = array_intersect_key($data, array_flip(self::PROFILE_FIELDS));
            $this->assertUnlocked($profile, $changes);
            $projectChanges = array_intersect_key($data, array_flip(self::PROJECT_FIELDS));
            unset($projectChanges['status']); // status goes through setStatus()
            if ($projectChanges !== []) {
                $project = $this->projects->update($project, $projectChanges, $actor);
            }
            $old = $profile->getAttributes();
            $values = $this->check([...$profile->only(self::PROFILE_FIELDS), ...$changes]);
            $profile->update([...$values, 'updated_by' => $actor->id]);
            $this->audit->log('finance.project_profile_updated', $profile, $old, $profile->getAttributes(), $actor->id);

            return $this->present($profile->refresh(), $project);
        }, self::TX_ATTEMPTS);
    }

    public function setStatus(int $projectId, string $status, User $actor): array
    {
        $project = $this->access->readable($actor, $projectId);
        $this->access->assertManage($actor, $project);
        $project = $this->projects->setStatus($project, $status, $actor);
        $profile = ProjectProfile::query()->where('project_id', $projectId)->first();

        return $profile === null ? $project->toArray() : $this->present($profile, $project);
    }

    public function present(ProjectProfile $profile, Project $project): array
    {
        $profile->loadMissing('client:id,name');

        return [
            ...$profile->toArray(),
            'contract_currency' => Currency::query()->whereKey($profile->contract_currency_id)->value('code'),
            'name' => $project->name, 'status' => $project->status, 'branch_id' => $project->branch_id,
            'manager_user_id' => $project->manager_user_id, 'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'billing_locked' => $this->hasApprovedBilling($profile->project_id),
        ];
    }

    /** R14: an approved (not reversed) billing exists. */
    public function hasApprovedBilling(int $projectId): bool
    {
        return Voucher::query()->where('type', 'project_billing')->where('project_id', $projectId)->where('status', 'approved')->exists();
    }

    /** @param  array<string, mixed>  $filters */
    private function query(array $filters, User $user)
    {
        $projects = $this->access->visibleProjects($user)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('name', 'like', '%'.$v.'%'));

        return ProjectProfile::query()->with('client:id,name')
            ->when(! empty($filters['search']), fn ($q) => $q->where(fn ($q) => $q->whereIn('project_id', (clone $projects)->select('id'))
                ->orWhere('code', 'like', '%'.$filters['search'].'%')))
            ->whereIn('project_id', $this->access->visibleProjects($user)
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))->select('id'))
            ->when($filters['client_party_id'] ?? null, fn ($q, $v) => $q->where('client_party_id', $v))
            ->when($filters['contract_type'] ?? null, fn ($q, $v) => $q->where('contract_type', $v));
    }

    /** @param  array<string, mixed>  $changes */
    private function assertUnlocked(ProjectProfile $profile, array $changes): void
    {
        if (! $this->hasApprovedBilling($profile->project_id)) {
            return;
        }
        foreach (self::LOCKED_AFTER_BILLING as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }
            $old = $profile->{$field};
            $new = $changes[$field];
            $same = $field === 'fee_pct'
                ? ($old === null && $new === null) || ($old !== null && $new !== null && Money::cmp((string) $old, Money::of((string) $new)) === 0)
                : (string) $old === (string) $new;
            if (! $same) {
                throw ValidationException::withMessages([$field => ['لا يُعدَّل نوع العقد ونسبة الأتعاب وعملته وعميله بعد أول مطالبة معتمدة؛ اعكس المطالبات أولاً.']]);
            }
        }
    }

    /**
     * Contract rules (§5.6): known type; active contract currency; a client (role client) except for internal projects;
     * lump_sum needs contract_value > 0; cost_plus and percentage_of_receipts need fee_pct in (0, 100]; the fee base is a
     * list of billable categories (cost_plus only).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function check(array $data): array
    {
        $type = $data['contract_type'] ?? null;
        if (! in_array($type, ProjectProfile::CONTRACT_TYPES, true)) {
            throw ValidationException::withMessages(['contract_type' => ['اختر نوع العقد.']]);
        }
        $currency = Currency::query()->find($data['contract_currency_id'] ?? null);
        if ($currency === null || ! $currency->is_active) {
            throw ValidationException::withMessages(['contract_currency_id' => ['اختر عملة عقد فعّالة.']]);
        }
        $clientId = empty($data['client_party_id']) ? null : (int) $data['client_party_id'];
        if ($clientId === null && $type !== 'internal') {
            throw ValidationException::withMessages(['client_party_id' => ['حدّد عميل المشروع.']]);
        }
        if ($clientId !== null) {
            $client = Party::query()->find($clientId);
            if ($client === null || ! $client->is_active || ! PartyRole::query()->where('party_id', $clientId)->where('role', 'client')->exists()) {
                throw ValidationException::withMessages(['client_party_id' => ['اختر جهة تعامل فعّالة بدور عميل.']]);
            }
        }
        $value = ($data['contract_value'] ?? null) === null || $data['contract_value'] === '' ? null
            : Money::round(Money::of((string) $data['contract_value']), (int) $currency->decimals);
        if ($type === 'lump_sum' && ($value === null || ! Money::isPositive($value))) {
            throw ValidationException::withMessages(['contract_value' => ['عقد المقطوعية يحتاج قيمة العقد.']]);
        }
        if ($value !== null && Money::isNegative($value)) {
            throw ValidationException::withMessages(['contract_value' => ['قيمة العقد لا تكون سالبة.']]);
        }
        $fee = ($data['fee_pct'] ?? null) === null || $data['fee_pct'] === '' ? null : Money::round(Money::of((string) $data['fee_pct']), 4);
        if (in_array($type, ['cost_plus', 'percentage_of_receipts'], true)) {
            if ($fee === null || ! Money::isPositive($fee) || Money::cmp($fee, '100') > 0) {
                throw ValidationException::withMessages(['fee_pct' => ['حدّد نسبة الأتعاب (أكبر من صفر وحتى 100).']]);
            }
        } else {
            $fee = null;
        }
        $base = null;
        if ($type === 'cost_plus' && ! empty($data['fee_base_category_ids'])) {
            $base = array_values(array_unique(array_map('intval', (array) $data['fee_base_category_ids'])));
            sort($base);
            if (ProjectCostCategory::query()->whereIn('id', $base)->where('is_billable', true)->count() !== count($base)) {
                throw ValidationException::withMessages(['fee_base_category_ids' => ['وعاء الأتعاب من تصنيفات التكلفة القابلة للفوترة فقط.']]);
            }
        }

        return ['client_party_id' => $clientId, 'contract_type' => $type, 'contract_currency_id' => $currency->id, 'contract_value' => $value,
            'fee_pct' => $fee, 'fee_base_category_ids' => $base];
    }
}
