<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\ContractorAgreement;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Projects\ContractorAgreementService;
use App\Modules\Finance\Support\Money;

/**
 * A payment to a contractor (#5, #7). Gross G = amount, advance deduction D = advance_deduction (0 ≤ D ≤ G, D ≤ the
 * party's open contractor advances in the currency — of the agreement when the payment has one):
 *
 *   Dr project_costs G (project, cost category, party, agreement) / Cr contractor_advances D (party, agreement) / Cr box G − D
 *
 * Tracking mode (D11) = party.contractor_tracking_mode ?? `finance.contractor_tracking_mode_default`.
 * R11 agreement mode: an open agreement of this contractor is required; project and category are the agreement's and the
 * currency is its currency; when the agreement's payments would pass net_value the payment needs `override_reason` and
 * then posts with a warning. R12 payments_only: project and cost category are enough.
 */
class ContractorPaymentPoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private ContractorAgreementService $agreements)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $gross = $this->requireAmount($voucher);
        $party = $this->party($voucher->party_id, ['contractor']);
        // Lock the contractor, then the agreement, so two payments on the same advances or agreement queue up.
        Party::query()->whereKey($party->id)->lockForUpdate()->first();
        $agreement = $this->agreement($voucher, $party);
        if ($agreement === null) {
            if ($this->agreements->trackingMode($party) === 'agreement') {
                $this->fail('contractor_agreement_id', 'المقاول يُتابَع باتفاقيات: اربط الدفعة باتفاقية مفتوحة.');
            }
            if ($voucher->project_id === null) {
                $this->fail('project_id', 'حدّد المشروع.');
            }
            $category = $voucher->project_cost_category_id === null ? null : ProjectCostCategory::query()->find($voucher->project_cost_category_id);
            if ($category === null || ! $category->is_active) {
                $this->fail('project_cost_category_id', 'حدّد تصنيف تكلفة المشروع.');
            }
        } elseif ($this->overrun($agreement, $gross) && trim((string) $voucher->override_reason) === '') {
            $this->fail('override_reason', 'الدفعة تتجاوز صافي الاتفاقية: المتبقي '.Money::format($this->remaining($agreement), $this->decimals($agreement->currency_id))
                .'. اذكر سبب التجاوز للمتابعة.');
        }
        $deduction = $this->deduction($voucher);
        if (Money::cmp($deduction, $gross) > 0) {
            $this->fail('advance_deduction', 'السلفة المخصومة لا تتجاوز مبلغ الدفعة.');
        }
        if (Money::isPositive($deduction)) {
            $open = $this->agreements->advanceBalance($party->id, $voucher->currency_id, $agreement?->id);
            if (Money::cmp($deduction, $open) > 0) {
                $this->fail('advance_deduction', 'السلفة المخصومة أكبر من سلف المقاول المفتوحة: '.Money::format(Money::max('0', $open), $this->decimals($voucher->currency_id)).'.');
            }
        }
        if (Money::cmp($deduction, $gross) < 0) {
            $this->box($voucher->cash_box_id, $voucher->currency_id);
        }
    }

    public function lines(Voucher $voucher): array
    {
        $party = Party::query()->findOrFail($voucher->party_id);
        $agreement = $this->agreement($voucher, $party);
        $gross = (string) $voucher->amount;
        $deduction = $this->deduction($voucher);
        $currency = $voucher->currency_id;
        $dims = ['party_id' => $party->id, 'project_id' => $agreement->project_id ?? $voucher->project_id,
            'project_cost_category_id' => $agreement->project_cost_category_id ?? $voucher->project_cost_category_id,
            'contractor_agreement_id' => $agreement?->id];
        $lines = [LineDraft::debit($this->roles->id('project_costs'), $currency, $gross, $dims, $voucher->description)];
        if (Money::isPositive($deduction)) {
            $lines[] = LineDraft::credit($this->roles->id('contractor_advances'), $currency, $deduction,
                ['party_id' => $party->id, 'contractor_agreement_id' => $agreement?->id], 'خصم سلفة مقاول');
        }
        $cash = Money::sub($gross, $deduction);
        if (Money::isPositive($cash)) {
            $lines[] = LineDraft::credit($this->box($voucher->cash_box_id, $currency)->account_id, $currency, $cash, [], $voucher->description);
        }

        return $lines;
    }

    /** R11: the posted payment that passed net_value with a reason. */
    public function warnings(Voucher $voucher): array
    {
        if ($voucher->contractor_agreement_id === null || trim((string) $voucher->override_reason) === '') {
            return [];
        }
        $agreement = ContractorAgreement::query()->find($voucher->contractor_agreement_id);
        if ($agreement === null || ! Money::isNegative($this->remaining($agreement))) {
            return [];
        }

        return ['مجموع دفعات الاتفاقية "'.$agreement->scope.'" تجاوز صافيها ('.Money::format((string) $agreement->net_value, $this->decimals($agreement->currency_id))
            .') بمقدار '.Money::format(Money::abs($this->remaining($agreement)), $this->decimals($agreement->currency_id)).'. السبب: '.$voucher->override_reason];
    }

    /** The voucher's agreement (locked), checked against the contractor, project, category and currency; null when none. */
    private function agreement(Voucher $voucher, Party $party): ?ContractorAgreement
    {
        if ($voucher->contractor_agreement_id === null) {
            return null;
        }
        $agreement = ContractorAgreement::query()->whereKey($voucher->contractor_agreement_id)->lockForUpdate()->first();
        if ($agreement === null || ($voucher->status !== 'approved' && $agreement->status !== 'open')) {
            $this->fail('contractor_agreement_id', 'الاتفاقية غير موجودة أو غير مفتوحة.');
        }
        if ($agreement->contractor_party_id !== $party->id) {
            $this->fail('contractor_agreement_id', 'الاتفاقية ليست لهذا المقاول.');
        }
        if ($voucher->project_id !== null && $voucher->project_id !== $agreement->project_id) {
            $this->fail('project_id', 'المشروع لا يطابق مشروع الاتفاقية.');
        }
        if ($voucher->project_cost_category_id !== null && $voucher->project_cost_category_id !== $agreement->project_cost_category_id) {
            $this->fail('project_cost_category_id', 'تصنيف التكلفة هو تصنيف الاتفاقية.');
        }
        if ($voucher->currency_id !== $agreement->currency_id) {
            $this->fail('currency_id', 'الدفعة بعملة الاتفاقية '.$this->code($agreement->currency_id).'.');
        }

        return $agreement;
    }

    private function overrun(ContractorAgreement $agreement, string $gross): bool
    {
        return Money::cmp($gross, $this->remaining($agreement)) > 0;
    }

    private function remaining(ContractorAgreement $agreement): string
    {
        return Money::sub(Money::of((string) $agreement->net_value), $this->agreements->grossPaid($agreement));
    }

    private function deduction(Voucher $voucher): string
    {
        $deduction = Money::of((string) ($voucher->advance_deduction ?? '0'));
        if (Money::isNegative($deduction)) {
            $this->fail('advance_deduction', 'السلفة المخصومة لا تكون سالبة.');
        }

        return $deduction;
    }
}
