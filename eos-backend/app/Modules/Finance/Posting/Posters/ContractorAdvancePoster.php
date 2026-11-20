<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\ContractorAgreement;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;

/**
 * An advance to a contractor (#6): Dr `contractor_advances` (party, agreement?, project?) / Cr box. Deducted later from a
 * contractor payment (#7). With an agreement, the agreement must be open and belong to the party, and the advance is
 * in its currency and on its project.
 */
class ContractorAdvancePoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $party = $this->party($voucher->party_id, ['contractor']);
        if ($voucher->contractor_agreement_id === null) {
            return;
        }
        $agreement = ContractorAgreement::query()->find($voucher->contractor_agreement_id);
        if ($agreement === null || $agreement->status !== 'open') {
            $this->fail('contractor_agreement_id', 'الاتفاقية غير موجودة أو غير مفتوحة.');
        }
        if ($agreement->contractor_party_id !== $party->id) {
            $this->fail('contractor_agreement_id', 'الاتفاقية ليست لهذا المقاول.');
        }
        if ($voucher->project_id !== null && $voucher->project_id !== $agreement->project_id) {
            $this->fail('project_id', 'المشروع لا يطابق مشروع الاتفاقية.');
        }
        if ($voucher->currency_id !== $agreement->currency_id) {
            $this->fail('currency_id', 'السلفة بعملة الاتفاقية '.$this->code($agreement->currency_id).'.');
        }
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $amount = (string) $voucher->amount;
        $projectId = $voucher->project_id ?? ($voucher->contractor_agreement_id === null ? null
            : ContractorAgreement::query()->whereKey($voucher->contractor_agreement_id)->value('project_id'));
        $dims = ['party_id' => $voucher->party_id, 'contractor_agreement_id' => $voucher->contractor_agreement_id, 'project_id' => $projectId];

        return [
            LineDraft::debit($this->roles->id('contractor_advances'), $voucher->currency_id, $amount, $dims, $voucher->description),
            LineDraft::credit($box->account_id, $voucher->currency_id, $amount, [], $voucher->description),
        ];
    }
}
