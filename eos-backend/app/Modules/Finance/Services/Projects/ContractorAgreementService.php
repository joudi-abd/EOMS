<?php

namespace App\Modules\Finance\Services\Projects;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\ContractorAgreement;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contractor agreements (§5.6, D11, #4: no entry when created — an administrative commitment). net_value =
 * agreed_value − discount, computed here. What the contractor got is read from the ledger: payments (#5, #7) are
 * project_costs debits carrying the agreement, deducted advances are contractor_advances credits carrying it.
 *
 *   remaining = net_value − cash paid − advances deducted   (= net_value − Σ project_costs of the agreement)
 */
class ContractorAgreementService
{
    private const TX_ATTEMPTS = 3;

    public const STATUSES = ['open', 'completed', 'cancelled'];

    public const MODES = ['agreement', 'payments_only'];

    public function __construct(private SystemAccounts $roles, private ProjectAccess $access, private SettingService $settings,
        private AuditLogger $audit) {}

    /** D11: the party's own mode, else the company default `finance.contractor_tracking_mode_default`. */
    public function trackingMode(Party $party): string
    {
        $mode = $party->contractor_tracking_mode ?: (string) $this->settings->get('finance.contractor_tracking_mode_default', 'agreement');

        return in_array($mode, self::MODES, true) ? $mode : 'agreement';
    }

    /** @param  array<string, mixed>  $filters */
    public function list(array $filters, User $user): LengthAwarePaginator
    {
        $page = ContractorAgreement::query()->with(['contractor:id,name', 'category:id,name'])
            ->whereIn('project_id', $this->access->visibleProjects($user)->select('id'))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($filters['party_id'] ?? null, fn ($q, $v) => $q->where('contractor_party_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')->paginate(min(100, (int) ($filters['per_page'] ?? 25)));

        return $page->through(fn (ContractorAgreement $a) => [...$a->toArray(), ...$this->totals($a)]);
    }

    public function create(array $data, User $actor): ContractorAgreement
    {
        return DB::transaction(function () use ($data, $actor) {
            $project = $this->access->readable($actor, (int) $data['project_id']);
            $this->access->assertManage($actor, $project);
            $data['project_cost_category_id'] ??= ProjectCostCategory::query()->where('system_key', 'labor')->value('id');
            $values = $this->check($data);
            $agreement = ContractorAgreement::create([...$values, 'project_id' => $project->id, 'status' => $data['status'] ?? 'open',
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->audit->log('finance.contractor_agreement_created', $agreement, null, $agreement->getAttributes(), $actor->id);

            return $agreement->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** Party, project, currency and category are fixed once a voucher uses the agreement. */
    public function update(ContractorAgreement $agreement, array $data, User $actor): ContractorAgreement
    {
        return DB::transaction(function () use ($agreement, $data, $actor) {
            $agreement = ContractorAgreement::query()->lockForUpdate()->findOrFail($agreement->id);
            $this->access->assertManage($actor, $this->access->readable($actor, $agreement->project_id));
            $fixed = ['contractor_party_id', 'currency_id', 'project_cost_category_id'];
            if ($this->used($agreement)) {
                foreach ($fixed as $field) {
                    if (array_key_exists($field, $data) && (int) $data[$field] !== (int) $agreement->{$field}) {
                        throw ValidationException::withMessages([$field => ['لا يُعدَّل هذا الحقل بعد ربط سندات بالاتفاقية.']]);
                    }
                }
            }
            $values = $this->check([...$agreement->only([...$fixed, 'scope', 'agreed_value', 'discount', 'notes']), ...$data]);
            if (isset($data['status'])) {
                abort_unless(in_array($data['status'], self::STATUSES, true), 422, 'حالة الاتفاقية غير معروفة.');
                $values['status'] = $data['status'];
            }
            $old = $agreement->getAttributes();
            $agreement->update([...$values, 'updated_by' => $actor->id]);
            $this->audit->log('finance.contractor_agreement_updated', $agreement, $old, $agreement->getAttributes(), $actor->id);

            return $agreement->refresh();
        }, self::TX_ATTEMPTS);
    }

    public function delete(ContractorAgreement $agreement, User $actor): void
    {
        DB::transaction(function () use ($agreement, $actor) {
            $agreement = ContractorAgreement::query()->lockForUpdate()->findOrFail($agreement->id);
            $this->access->assertManage($actor, $this->access->readable($actor, $agreement->project_id));
            abort_if($this->used($agreement), 409, 'الاتفاقية مرتبطة بسندات؛ ألغها (cancelled) بدل حذفها.');
            $this->audit->log('finance.contractor_agreement_deleted', $agreement, $agreement->getAttributes(), null, $actor->id);
            $agreement->delete();
        }, self::TX_ATTEMPTS);
    }

    /** The agreement view (§9.4 tab 5): value, discount, net, paid, advances, remaining and the dated payments with their box. */
    public function present(ContractorAgreement $agreement): array
    {
        $agreement->loadMissing(['contractor:id,name', 'category:id,name']);
        $vouchers = Voucher::query()->where('contractor_agreement_id', $agreement->id)->where('status', 'approved')
            ->whereIn('purpose', ['contractor_payment', 'contractor_advance'])->orderBy('voucher_date')->orderBy('id')->get();
        $boxes = CashBox::query()->whereIn('id', $vouchers->pluck('cash_box_id')->filter()->unique())->pluck('name', 'id');
        $row = fn (Voucher $v) => ['voucher_id' => $v->id, 'number' => $v->number, 'date' => $v->voucher_date->toDateString(),
            'cash_box_id' => $v->cash_box_id, 'cash_box' => $boxes[$v->cash_box_id] ?? null, 'gross' => Money::of((string) $v->amount),
            'advance_deduction' => Money::of((string) ($v->advance_deduction ?? '0')),
            'cash' => Money::sub((string) $v->amount, (string) ($v->advance_deduction ?? '0')), 'description' => $v->description,
            'override_reason' => $v->override_reason];

        return [
            ...$agreement->toArray(),
            'currency' => Currency::query()->whereKey($agreement->currency_id)->value('code'),
            ...$this->totals($agreement),
            'payments' => $vouchers->where('purpose', 'contractor_payment')->map($row)->values()->all(),
            'advances' => $vouchers->where('purpose', 'contractor_advance')->map($row)->values()->all(),
        ];
    }

    /**
     * @return array{gross_paid: string, cash_paid: string, advances_deducted: string, advances_outstanding: string, remaining: string,
     *     overrun: bool}
     */
    public function totals(ContractorAgreement $agreement): array
    {
        $gross = $this->grossPaid($agreement);
        $paymentEntries = JournalEntry::query()->select('id')->where('source_type', 'voucher')
            ->whereIn('source_id', Voucher::query()->select('id')->where('purpose', 'contractor_payment'));
        $deducted = Money::neg($this->sum(JournalLine::query()->where('account_id', $this->roles->mappedId('contractor_advances') ?? 0)
            ->where('contractor_agreement_id', $agreement->id)->where('currency_id', $agreement->currency_id)
            ->whereIn('journal_entry_id', $paymentEntries)));
        // net − cash paid − advances deducted; the gross on project_costs already holds both.
        $remaining = Money::sub(Money::of((string) $agreement->net_value), $gross);

        return [
            'gross_paid' => $gross,
            'cash_paid' => Money::sub($gross, $deducted),
            'advances_deducted' => $deducted,
            'advances_outstanding' => $this->advanceBalance($agreement->contractor_party_id, $agreement->currency_id, $agreement->id),
            'remaining' => $remaining,
            'overrun' => Money::isNegative($remaining),
        ];
    }

    /** Σ project_costs (net) carrying the agreement, in its currency: what the agreement has consumed so far (R11). */
    public function grossPaid(ContractorAgreement $agreement): string
    {
        return $this->sum(JournalLine::query()->where('account_id', $this->roles->mappedId('project_costs') ?? 0)
            ->where('contractor_agreement_id', $agreement->id)->where('currency_id', $agreement->currency_id));
    }

    /** Contractor advances still open for the party in the currency; of one agreement when given (#6, #7). */
    public function advanceBalance(int $partyId, int $currencyId, ?int $agreementId = null): string
    {
        return $this->sum(JournalLine::query()->where('account_id', $this->roles->mappedId('contractor_advances') ?? 0)
            ->where('party_id', $partyId)->where('currency_id', $currencyId)
            ->when($agreementId !== null, fn ($q) => $q->where('contractor_agreement_id', $agreementId)));
    }

    private function sum($lines): string
    {
        return Money::round(Money::of($lines->sum(DB::raw('debit - credit'))), Money::SCALE);
    }

    private function used(ContractorAgreement $agreement): bool
    {
        return Voucher::query()->where('contractor_agreement_id', $agreement->id)->exists();
    }

    /** Party is an active contractor, category active, currency active, 0 ≤ discount ≤ agreed value; net computed. */
    private function check(array $data): array
    {
        $party = Party::query()->find($data['contractor_party_id'] ?? null);
        if ($party === null || ! $party->is_active || ! PartyRole::query()->where('party_id', $party->id)->where('role', 'contractor')->exists()) {
            throw ValidationException::withMessages(['contractor_party_id' => ['اختر مقاولاً فعّالاً.']]);
        }
        $category = ProjectCostCategory::query()->find($data['project_cost_category_id'] ?? null);
        if ($category === null || ! $category->is_active) {
            throw ValidationException::withMessages(['project_cost_category_id' => ['اختر تصنيف تكلفة فعّالاً.']]);
        }
        $currency = Currency::query()->find($data['currency_id'] ?? null);
        if ($currency === null || ! $currency->is_active) {
            throw ValidationException::withMessages(['currency_id' => ['اختر عملة فعّالة.']]);
        }
        $agreed = Money::round(Money::of((string) ($data['agreed_value'] ?? '0')), (int) $currency->decimals);
        $discount = Money::round(Money::of((string) ($data['discount'] ?? '0')), (int) $currency->decimals);
        if (Money::isNegative($agreed) || Money::isNegative($discount) || Money::cmp($discount, $agreed) > 0) {
            throw ValidationException::withMessages(['discount' => ['الحسم بين صفر وقيمة الاتفاقية.']]);
        }

        return ['contractor_party_id' => $party->id, 'project_cost_category_id' => $category->id, 'currency_id' => $currency->id,
            'scope' => (string) ($data['scope'] ?? ''), 'agreed_value' => $agreed, 'discount' => $discount, 'net_value' => Money::sub($agreed, $discount),
            'notes' => $data['notes'] ?? null];
    }
}
