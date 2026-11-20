<?php

namespace App\Modules\Finance\Posting\Posters;

use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Posting\AbstractPoster;
use App\Modules\Finance\Posting\LineDraft;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Projects\ProjectLedger;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Currency;
use Illuminate\Support\Facades\DB;

/**
 * Project billing (#2): amount B in the contract currency; A = the client's advances still open on the project in that
 * currency. Dr `client_advances` min(A, B) / Dr `receivables_clients` B − min(A, B) / Cr `project_revenue` B (party =
 * the project's client, project). Not for `internal` projects; a lump-sum billing may not pass contract_value − already
 * billed (OP-3: lump-sum revenue comes from these billings). The suggestion comes from ProjectBillingCalculator (§6.1).
 */
class ProjectBillingPoster extends AbstractPoster
{
    public function __construct(SystemAccounts $roles, private ProjectLedger $ledger)
    {
        parent::__construct($roles);
    }

    public function validate(Voucher $voucher): void
    {
        $amount = $this->requireAmount($voucher);
        $profile = $this->profile($voucher);
        if ($profile->contract_type === 'internal') {
            $this->fail('project_id', 'المشروع داخلي ولا تُصدر له مطالبات.');
        }
        if ($profile->client_party_id === null) {
            $this->fail('party_id', 'حدّد عميل المشروع في العقد أولاً.');
        }
        if ($voucher->party_id !== null && $voucher->party_id !== $profile->client_party_id) {
            $this->fail('party_id', 'المطالبة تُصدر لعميل المشروع فقط.');
        }
        $this->party($profile->client_party_id, ['client']);
        if ($voucher->currency_id !== $profile->contract_currency_id) {
            $this->fail('currency_id', 'عملة المطالبة هي عملة العقد '.$this->code($profile->contract_currency_id).'.');
        }
        if ($profile->contract_type === 'lump_sum') {
            $currency = Currency::query()->findOrFail($profile->contract_currency_id);
            $billed = Money::neg($this->ledger->net($this->ledger->lines('project_revenue', $profile->project_id), $currency, now()->toDateString()));
            $left = Money::sub(Money::of((string) $profile->contract_value), $billed);
            if (Money::cmp($amount, $left) > 0) {
                $this->fail('amount', 'المطالبة تتجاوز المتبقي من قيمة العقد: المتبقي '.Money::format(Money::max('0', $left), (int) $currency->decimals).'.');
            }
        }
    }

    public function lines(Voucher $voucher): array
    {
        $profile = $this->profile($voucher);
        $amount = (string) $voucher->amount;
        $currency = $profile->contract_currency_id;
        $party = $profile->client_party_id;
        $dims = ['party_id' => $party, 'project_id' => $profile->project_id];
        $advances = fn (?string $date) => Money::neg(Money::round(Money::of($this->ledger->lines('client_advances', $profile->project_id, $date)
            ->where('party_id', $party)->where('currency_id', $currency)->sum(DB::raw('debit - credit'))), Money::SCALE));
        // Advances received by the billing date and still open today (a later billing may already have used some).
        $available = Money::max('0', Money::min($advances($voucher->voucher_date->toDateString()), $advances(null)));
        $fromAdvances = Money::min($available, $amount);
        $rest = Money::sub($amount, $fromAdvances);
        $lines = [];
        if (Money::isPositive($fromAdvances)) {
            $lines[] = LineDraft::debit($this->roles->id('client_advances'), $currency, $fromAdvances, $dims, 'من دفعات العميل المقدمة');
        }
        if (Money::isPositive($rest)) {
            $lines[] = LineDraft::debit($this->roles->id('receivables_clients'), $currency, $rest, $dims, 'ذمة على العميل');
        }
        $lines[] = LineDraft::credit($this->roles->id('project_revenue'), $currency, $amount, $dims, $voucher->description);

        return $lines;
    }

    /** The project's finance profile, locked so billings of one project post one at a time. */
    private function profile(Voucher $voucher): ProjectProfile
    {
        if ($voucher->project_id === null) {
            $this->fail('project_id', 'حدّد المشروع.');
        }
        $profile = ProjectProfile::query()->where('project_id', $voucher->project_id)->lockForUpdate()->first();
        if ($profile === null) {
            $this->fail('project_id', 'المشروع بلا ملف مالي؛ أنشئ العقد أولاً.');
        }

        return $profile;
    }
}
