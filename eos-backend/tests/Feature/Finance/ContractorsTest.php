<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Support\Money;
use App\Modules\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\Feature\Finance\Concerns\ProjectFixture;
use Tests\TestCase;

/** Contractors (phase 5): agreements (§5.6, D11), payments (#5, #7, R11, R12) and advances (#6). */
class ContractorsTest extends TestCase
{
    use FinanceFixture, ProjectFixture, RefreshDatabase;

    private int $projectId;

    private Party $contractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->setUpProjects();
        $client = $this->party('عميل', ['client']);
        $this->projectId = $this->project(['name' => 'شقق', 'client_party_id' => $client->id])['project_id'];
        $this->contractor = $this->party('أحمد جمو', ['contractor']);
    }

    private function agreement(array $payload = []): array
    {
        return $this->login($this->finance)->postJson('/api/v1/finance/contractor-agreements', $payload + ['project_id' => $this->projectId,
            'contractor_party_id' => $this->contractor->id, 'scope' => 'تمديد صحية', 'currency_id' => $this->currencyId('USD'),
            'agreed_value' => 10000, 'discount' => 500])->assertCreated()->json('data');
    }

    private function pay(array $payload, int $status = 201): ?array
    {
        return $this->login($this->finance)->voucher($payload + ['type' => 'payment', 'purpose' => 'contractor_payment', 'party_id' => $this->contractor->id,
            'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'description' => 'دفعة'], $status);
    }

    public function test_agreements_compute_the_net_value_and_check_their_parties(): void
    {
        $agreement = $this->agreement();
        $this->assertSame(['10000', '500', '9500', 'open', $this->categoryId('labor'), '9500'], [(string) (float) $agreement['agreed_value'],
            (string) (float) $agreement['discount'], (string) (float) $agreement['net_value'], $agreement['status'], $agreement['project_cost_category_id'],
            $agreement['remaining']]);

        $this->postJson('/api/v1/finance/contractor-agreements', ['project_id' => $this->projectId, 'contractor_party_id' => $this->supplier->id,
            'scope' => 'x', 'currency_id' => $this->currencyId('USD'), 'agreed_value' => 100])->assertUnprocessable()->assertJsonValidationErrors('contractor_party_id');
        $this->postJson('/api/v1/finance/contractor-agreements', ['project_id' => $this->projectId, 'contractor_party_id' => $this->contractor->id,
            'scope' => 'x', 'currency_id' => $this->currencyId('USD'), 'agreed_value' => 100, 'discount' => 101])->assertUnprocessable();

        $this->patchJson("/api/v1/finance/contractor-agreements/{$agreement['id']}", ['discount' => 1000])->assertOk()->assertJsonPath('data.net_value', '9000.0000');
        $this->getJson("/api/v1/finance/contractor-agreements?project_id={$this->projectId}")->assertOk()->assertJsonCount(1, 'data');

        // A project manager reads the agreements of his projects only and cannot write.
        $manager = $this->actor('project_manager');
        $this->login($manager)->getJson("/api/v1/finance/contractor-agreements/{$agreement['id']}")->assertNotFound();
        $this->getJson('/api/v1/finance/contractor-agreements')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/finance/contractor-agreements', ['project_id' => $this->projectId])->assertForbidden();
    }

    /** R11: in agreement mode a payment needs an open agreement of this contractor. */
    public function test_agreement_mode_needs_an_open_agreement(): void
    {
        $this->pay(['project_id' => $this->projectId, 'project_cost_category_id' => $this->categoryId('labor'), 'amount' => 100], 422);
        $agreement = $this->agreement();
        $otherContractor = $this->party('معلم كهرباء', ['contractor']);
        $this->pay(['party_id' => $otherContractor->id, 'contractor_agreement_id' => $agreement['id'], 'amount' => 100], 422);
        $this->pay(['contractor_agreement_id' => $agreement['id'], 'currency' => 'TRY', 'amount' => 100], 422);

        $payment = $this->pay(['contractor_agreement_id' => $agreement['id'], 'amount' => 1500]);
        $line = JournalLine::where('journal_entry_id', $payment['journal_entry_id'])->where('account_id', $this->roleId('project_costs'))->sole();
        $this->assertSame([$this->projectId, $this->categoryId('labor'), $this->contractor->id, $agreement['id'], '1500.0000'],
            [$line->project_id, $line->project_cost_category_id, $line->party_id, $line->contractor_agreement_id, (string) $line->debit]);

        $this->patchJson("/api/v1/finance/contractor-agreements/{$agreement['id']}", ['status' => 'completed'])->assertOk();
        $this->pay(['contractor_agreement_id' => $agreement['id'], 'amount' => 100], 422);
        // Used agreements keep their contractor and currency, and are not deleted.
        $this->patchJson("/api/v1/finance/contractor-agreements/{$agreement['id']}", ['currency_id' => $this->currencyId('TRY')])->assertUnprocessable();
        $this->deleteJson("/api/v1/finance/contractor-agreements/{$agreement['id']}")->assertConflict();
    }

    /** §14 item 7: passing net_value is refused without a reason, posted with a reason and a warning. */
    public function test_agreement_overrun_needs_a_reason_and_warns(): void
    {
        $agreement = $this->agreement();
        $this->pay(['contractor_agreement_id' => $agreement['id'], 'amount' => 9000]);
        $this->pay(['contractor_agreement_id' => $agreement['id'], 'amount' => 1000], 422);
        $this->assertSame('500', $this->getJson("/api/v1/finance/contractor-agreements/{$agreement['id']}")->json('data.remaining'));

        $over = $this->pay(['contractor_agreement_id' => $agreement['id'], 'amount' => 1000, 'override_reason' => 'أعمال إضافية متفق عليها']);
        $this->assertSame('approved', $over['status']);
        $this->assertCount(1, $over['warnings']);
        $this->assertStringContainsString('أعمال إضافية', $over['warnings'][0]);
        $view = $this->getJson("/api/v1/finance/contractor-agreements/{$agreement['id']}")->assertOk()->json('data');
        $this->assertSame(['10000', '-500', true], [$view['gross_paid'], $view['remaining'], $view['overrun']]);
    }

    /** #6 and #7: an advance, then deducted from the next payment; the agreement view lists the dated payments with their box. */
    public function test_contractor_advance_is_deducted_from_a_payment(): void
    {
        $agreement = $this->agreement();
        $advance = $this->login($this->finance)->voucher(['type' => 'payment', 'purpose' => 'contractor_advance', 'party_id' => $this->contractor->id,
            'contractor_agreement_id' => $agreement['id'], 'cash_box_id' => $this->cash->id, 'currency' => 'USD', 'amount' => 2000, 'voucher_date' => '2026-11-05']);
        $line = JournalLine::where('journal_entry_id', $advance['journal_entry_id'])->where('account_id', $this->roleId('contractor_advances'))->sole();
        $this->assertSame([$this->contractor->id, $agreement['id'], $this->projectId, '2000.0000'], [$line->party_id, $line->contractor_agreement_id,
            $line->project_id, (string) $line->debit]);
        $this->assertSame('2000', $this->roleBalance('contractor_advances', 'USD', $this->contractor->id));

        $this->pay(['contractor_agreement_id' => $agreement['id'], 'amount' => 5000, 'advance_deduction' => 2001], 422);
        $this->pay(['contractor_agreement_id' => $agreement['id'], 'amount' => 1000, 'advance_deduction' => 1500], 422);
        $before = $this->boxBalance($this->cash, 'USD');
        $payment = $this->pay(['contractor_agreement_id' => $agreement['id'], 'amount' => 5000, 'advance_deduction' => 2000]);
        $lines = JournalLine::where('journal_entry_id', $payment['journal_entry_id'])->orderBy('line_no')->get();
        $this->assertSame([
            [$this->roleId('project_costs'), '5000.0000', '0.0000', $agreement['id']],
            [$this->roleId('contractor_advances'), '0.0000', '2000.0000', $agreement['id']],
            [$this->cash->account_id, '0.0000', '3000.0000', null],
        ], $lines->map(fn ($l) => [$l->account_id, (string) $l->debit, (string) $l->credit, $l->contractor_agreement_id])->all());
        $this->assertSame(['3000', '0'], [Money::sub($before, $this->boxBalance($this->cash, 'USD')),
            $this->roleBalance('contractor_advances', 'USD', $this->contractor->id)]);

        $view = $this->getJson("/api/v1/finance/contractor-agreements/{$agreement['id']}")->assertOk()->json('data');
        $this->assertSame(['5000', '3000', '2000', '0', '4500'], [$view['gross_paid'], $view['cash_paid'], $view['advances_deducted'],
            $view['advances_outstanding'], $view['remaining']]);
        $this->assertSame([['2026-11-10', 'الخزنة', '5000', '2000', '3000']], array_map(fn ($p) => [$p['date'], $p['cash_box'], $p['gross'],
            $p['advance_deduction'], $p['cash']], $view['payments']));
        $this->assertCount(1, $view['advances']);
    }

    /** R12: in payments_only mode a payment needs only a project and a cost category (party setting, then company default). */
    public function test_payments_only_mode_needs_project_and_category(): void
    {
        $this->contractor->update(['contractor_tracking_mode' => 'payments_only']);
        $this->pay(['project_cost_category_id' => $this->categoryId('labor'), 'amount' => 100], 422);
        $this->pay(['project_id' => $this->projectId, 'amount' => 100], 422);
        $payment = $this->pay(['project_id' => $this->projectId, 'project_cost_category_id' => $this->categoryId('misc'), 'amount' => 100]);
        $line = JournalLine::where('journal_entry_id', $payment['journal_entry_id'])->where('account_id', $this->roleId('project_costs'))->sole();
        $this->assertSame([$this->projectId, $this->categoryId('misc'), null], [$line->project_id, $line->project_cost_category_id, $line->contractor_agreement_id]);

        // The company default applies to contractors without their own mode.
        $plain = $this->party('معلم دهان', ['contractor']);
        $this->pay(['party_id' => $plain->id, 'project_id' => $this->projectId, 'project_cost_category_id' => $this->categoryId('labor'), 'amount' => 50], 422);
        Setting::where('setting_key', 'finance.contractor_tracking_mode_default')->update(['setting_value' => 'payments_only']);
        $this->pay(['party_id' => $plain->id, 'project_id' => $this->projectId, 'project_cost_category_id' => $this->categoryId('labor'), 'amount' => 50]);
    }
}
