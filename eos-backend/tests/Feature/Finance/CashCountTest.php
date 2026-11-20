<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\TestCase;

/** Box counts (D24): the system balance is captured at submit (R23) and a difference posts only after a second person approves it (R22). */
class CashCountTest extends TestCase
{
    use FinanceFixture, RefreshDatabase;

    private CashBox $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->box = $this->box('Safe', ['USD' => null, 'TRY' => null]);
        $this->fund($this->box, 'USD', 1000);
        $this->fund($this->box, 'TRY', 4340);
    }

    private function newCount(array $lines, string $date = '2026-11-14'): array
    {
        return $this->postJson('/api/v1/finance/cash-counts', ['cash_box_id' => $this->box->id, 'count_date' => $date, 'lines' => $lines])
            ->assertCreated()->json('data');
    }

    public function test_a_shortage_is_not_posted_before_the_manager_approves_it(): void
    {
        Storage::fake('local');
        $count = $this->newCount([['currency' => 'USD', 'counted_amount' => 950, 'explanation' => 'ورقة 50 مفقودة'], ['currency' => 'TRY', 'counted_amount' => 4340]]);
        $this->post('/api/v1/finance/cash-counts/'.$count['id'].'/attachments', ['file' => UploadedFile::fake()->create('statement.pdf', 50, 'application/pdf')],
            ['Accept' => 'application/json'])->assertCreated();
        $submitted = $this->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/submit')->assertOk()->json('data');
        $usd = collect($submitted['lines'])->firstWhere('currency_id', $this->currencyId('USD'));
        $this->assertSame(['1000.0000', '-50.0000'], [$usd['system_balance'], $usd['difference']]);
        $this->assertSame(1, $this->director->notifications()->count());

        // Nothing posts on submit (§14.12); the counter and finance cannot approve.
        $this->assertSame('1000', $this->boxBalance($this->box, 'USD'));
        $this->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/approve')->assertForbidden();
        $this->assertSame(0, Voucher::where('type', 'count_adjustment')->count());

        $approved = $this->login($this->director)->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/approve')->assertOk()->json('data');
        $this->assertSame('approved', $approved['status']);
        $voucher = Voucher::findOrFail($approved['voucher_id']);
        $this->assertSame(['count_adjustment', 'approved', 'CA-2026-000001'], [$voucher->type, $voucher->status, $voucher->number]);
        $this->assertSame('950', $this->boxBalance($this->box, 'USD'));
        $this->assertSame('4340', $this->boxBalance($this->box, 'TRY'));
        $this->assertSame('50', $this->roleBalance('cash_over_short', 'USD'));
        $this->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/approve')->assertStatus(409);
    }

    public function test_the_counter_never_approves_even_holding_the_permission(): void
    {
        // The company manager approves counts but does not enter them.
        $this->login($this->director)->postJson('/api/v1/finance/cash-counts', ['cash_box_id' => $this->box->id, 'count_date' => '2026-11-14',
            'lines' => [['currency' => 'USD', 'counted_amount' => 1]]])->assertForbidden();

        $admin = $this->actor('system_admin');
        $count = $this->login($admin)->newCount([['currency' => 'USD', 'counted_amount' => 1010]]);
        $this->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/submit')->assertOk();
        $this->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/approve')->assertForbidden();

        $this->login($this->director)->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/approve')->assertOk();
        $this->assertSame('1010', $this->boxBalance($this->box, 'USD'), 'an overage enters the box');
        $this->assertSame('-10', $this->roleBalance('cash_over_short', 'USD'));
    }

    public function test_a_box_that_moved_after_submit_needs_a_fresh_capture(): void
    {
        $count = $this->newCount([['currency' => 'USD', 'counted_amount' => 900]]);
        $this->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/submit')->assertOk();
        // A payment dated the count day reaches the box after the capture (R23).
        $this->voucher(['type' => 'payment', 'purpose' => 'other', 'cash_box_id' => $this->box->id, 'currency' => 'USD', 'amount' => 100,
            'account_id' => $this->accountId('6104'), 'voucher_date' => '2026-11-14']);

        $this->login($this->director)->getJson('/api/v1/finance/cash-counts/'.$count['id'])->assertOk()->assertJsonPath('data.stale', true);
        $this->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/approve')->assertStatus(409);
        $fresh = $this->login($this->finance)->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/resnapshot')->assertOk()->json('data');
        $this->assertSame(['900.0000', '0.0000', false], [$fresh['lines'][0]['system_balance'], $fresh['lines'][0]['difference'], $fresh['stale']]);
        $approved = $this->login($this->director)->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/approve')->assertOk()->json('data');
        $this->assertNull($approved['voucher_id'], 'no difference, nothing to post');
    }

    public function test_drafts_are_edited_by_the_counter_and_rejections_go_back_with_a_reason(): void
    {
        $count = $this->newCount([['currency' => 'USD', 'counted_amount' => 990]]);
        $this->postJson('/api/v1/finance/cash-counts', ['cash_box_id' => $this->box->id, 'count_date' => '2026-11-14',
            'lines' => [['currency' => 'SYP', 'counted_amount' => 1]]])->assertUnprocessable(); // SYP is not on the box
        $this->putJson('/api/v1/finance/cash-counts/'.$count['id'], ['lines' => [['currency' => 'USD', 'counted_amount' => 995]]])->assertOk()
            ->assertJsonPath('data.lines.0.counted_amount', '995.0000');
        $this->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/submit')->assertOk();
        $this->putJson('/api/v1/finance/cash-counts/'.$count['id'], ['notes' => 'x'])->assertStatus(409);

        $this->login($this->director)->postJson('/api/v1/finance/cash-counts/'.$count['id'].'/reject', ['reason' => 'أعد العد'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejected_reason', 'أعد العد');
        $this->assertSame(1, $this->finance->notifications()->where('data->kind', 'finance.cash_count_rejected')->count());
        $this->assertSame('1000', $this->boxBalance($this->box, 'USD'));
        $this->assertSame(1, $this->getJson('/api/v1/finance/cash-counts?status=rejected')->assertOk()->json('meta.total'));
    }
}
