<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;

/**
 * A client's payment for a project (#1): Dr box [c] / Cr `client_advances` [c] (party = client, project). The money
 * stays an advance until a project billing (#2) consumes it. The party must hold the client role and be the client on
 * the project's finance profile.
 */
class ClientPaymentPoster extends AbstractPoster
{
    public function validate(Voucher $voucher): void
    {
        $this->requireAmount($voucher);
        $this->box($voucher->cash_box_id, $voucher->currency_id);
        $party = $this->party($voucher->party_id, ['client']);
        if ($voucher->project_id === null) {
            $this->fail('project_id', 'حدّد المشروع.');
        }
        $profile = ProjectProfile::query()->where('project_id', $voucher->project_id)->first();
        if ($profile === null) {
            $this->fail('project_id', 'المشروع بلا ملف مالي؛ أنشئ العقد أولاً.');
        }
        if ($profile->client_party_id !== $party->id) {
            $this->fail('party_id', 'جهة التعامل ليست عميل هذا المشروع.');
        }
    }

    public function lines(Voucher $voucher): array
    {
        $box = $this->box($voucher->cash_box_id, $voucher->currency_id);
        $amount = (string) $voucher->amount;
        $dims = ['party_id' => $voucher->party_id, 'project_id' => $voucher->project_id];

        return [
            LineDraft::debit($box->account_id, $voucher->currency_id, $amount, [], $voucher->description),
            LineDraft::credit($this->roles->id('client_advances'), $voucher->currency_id, $amount, $dims, $voucher->description),
        ];
    }
}
