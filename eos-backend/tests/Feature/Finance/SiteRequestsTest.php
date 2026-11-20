<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Attachment;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\SiteRequest;
use App\Modules\Finance\Models\Voucher;
use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SystemNotification;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Finance\Concerns\FinanceFixture;
use Tests\TestCase;

/** Supervisor site requests (phase 4, R24/R25): submit with the invoice, finance rejects or approves (custody or own money). */
class SiteRequestsTest extends TestCase
{
    use FinanceFixture, RefreshDatabase;

    private User $supervisor;

    private Party $holder;

    private Party $supplier;

    private Project $project;

    private CashBox $safe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        Storage::fake('local');
        $this->safe = $this->box('الخزنة', ['USD' => null]);
        $this->fund($this->safe, 'USD', 5000);
        $this->supervisor = $this->actor('site_supervisor');
        $employee = Employee::create(['employee_no' => 'E-SUP-1', 'user_id' => $this->supervisor->id, 'first_name' => 'أبو', 'last_name' => 'خالد',
            'national_id' => '555000111', 'national_id_hash' => hash('sha256', '555000111'), 'hire_date' => '2026-01-01']);
        $this->holder = Party::create(['name' => 'أبو خالد المشرف', 'employee_id' => $employee->id]);
        PartyRole::create(['party_id' => $this->holder->id, 'role' => 'employee']);
        $this->supplier = Party::create(['name' => 'مورد البلوك']);
        PartyRole::create(['party_id' => $this->supplier->id, 'role' => 'supplier']);
        $this->project = Project::create(['name' => 'بناء السلام', 'status' => 'active', 'branch_id' => $this->hq->id]);
        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $this->supervisor->id, 'role' => 'supervisor']);
    }

    private function submit(array $extra = [], ?User $as = null): TestResponse
    {
        return $this->login($as ?? $this->supervisor)->post('/api/v1/finance/site-requests', [
            'project_id' => $this->project->id, 'project_cost_category_id' => ProjectCostCategory::where('system_key', 'materials')->value('id'),
            'amount' => 120, 'currency' => 'USD', 'supplier_party_id' => $this->supplier->id, 'description' => 'بلوك للجدار الشرقي',
            'payment_source' => 'own_money', 'invoice' => UploadedFile::fake()->image('invoice.jpg'), ...$extra,
        ], ['Accept' => 'application/json']);
    }

    public function test_submitted_request_is_rejected_with_a_reason_and_the_supervisor_is_notified(): void
    {
        Notification::fake();
        $request = $this->submit()->assertCreated()->json('data');
        $this->assertSame(['submitted', $this->holder->id, 'أبو خالد المشرف', 1], [$request['status'], $request['holder_party_id'], $request['holder_name'], count($request['attachments'])]);
        $this->assertSame([$request['id']], collect($this->getJson('/api/v1/finance/site-requests/mine')->assertOk()->json('data'))->pluck('id')->all());
        Notification::assertSentTo($this->finance, SystemNotification::class);

        // The inbox is finance's; the supervisor never decides (spec §14 item 11).
        $this->getJson('/api/v1/finance/site-requests')->assertForbidden();
        $this->postJson('/api/v1/finance/site-requests/'.$request['id'].'/approve')->assertForbidden();
        $this->postJson('/api/v1/finance/site-requests/'.$request['id'].'/reject', ['reason' => 'لا'])->assertForbidden();

        $this->login($this->finance);
        $this->assertSame([$request['id']], collect($this->getJson('/api/v1/finance/site-requests?status=submitted')->json('data'))->pluck('id')->all());
        $this->postJson('/api/v1/finance/site-requests/'.$request['id'].'/reject')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/finance/site-requests/'.$request['id'].'/reject', ['reason' => 'الفاتورة غير واضحة'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejected_reason', 'الفاتورة غير واضحة');
        Notification::assertSentTo($this->supervisor, SystemNotification::class,
            fn (SystemNotification $n) => str_contains(json_encode($n->toArray($this->supervisor), JSON_UNESCAPED_UNICODE), 'الفاتورة غير واضحة'));
        $this->postJson('/api/v1/finance/site-requests/'.$request['id'].'/approve')->assertStatus(409);
        $this->assertSame(['opening_balance'], Voucher::pluck('type')->all());
    }

    public function test_approving_an_own_money_request_books_a_debt_to_the_supervisor(): void
    {
        $request = $this->submit()->assertCreated()->json('data');
        $approved = $this->login($this->finance)->postJson('/api/v1/finance/site-requests/'.$request['id'].'/approve')->assertOk()->json('data');
        $this->assertSame('approved', $approved['status']);

        $voucher = Voucher::findOrFail($approved['voucher_id']);
        $this->assertSame(['manual_journal', 'approved', $approved['voucher_number']], [$voucher->type, $voucher->status, $voucher->number]);
        $lines = JournalLine::where('journal_entry_id', $voucher->journal_entry_id)->get();
        $debit = $lines->firstWhere('debit', '>', 0);
        $credit = $lines->firstWhere('credit', '>', 0);
        // R25: Dr project costs (project, category, supplier) / Cr other payables (the supervisor).
        $this->assertSame([$this->roleId('project_costs'), $this->project->id, $this->supplier->id, '120.0000'],
            [$debit->account_id, $debit->project_id, $debit->party_id, (string) $debit->debit]);
        $this->assertSame([$this->roleId('other_payables'), $this->holder->id], [$credit->account_id, $credit->party_id]);
        $this->assertSame('-120', $this->roleBalance('other_payables', 'USD', $this->holder->id));
        // The invoice image travels with the voucher.
        $this->assertSame(1, Attachment::where('attachable_type', 'fin_voucher')->where('attachable_id', $voucher->id)->count());
        // The supervisor's party statement shows what the company owes them.
        $this->assertSame('علينا', $this->getJson('/api/v1/finance/parties/'.$this->holder->id.'/statement?currency=USD')->json('data.currencies.0.closing_side'));
    }

    public function test_approving_a_custody_request_settles_the_supervisors_open_custody(): void
    {
        // No open custody: a custody-paid request is refused at submission.
        $this->submit(['payment_source' => 'custody'])->assertUnprocessable()->assertJsonValidationErrors('payment_source');

        $this->login($this->finance);
        $this->voucher(['type' => 'custody_issue', 'cash_box_id' => $this->safe->id, 'currency' => 'USD', 'amount' => 500, 'party_id' => $this->holder->id,
            'project_id' => $this->project->id]);
        $request = $this->submit(['payment_source' => 'custody'])->assertCreated()->json('data');
        $approved = $this->login($this->finance)->postJson('/api/v1/finance/site-requests/'.$request['id'].'/approve')->assertOk()->json('data');

        $voucher = Voucher::findOrFail($approved['voucher_id']);
        $this->assertSame(['custody_settlement', 'approved', $approved['custody_id']], [$voucher->type, $voucher->status, $voucher->custody_id]);
        $this->assertSame('380', $this->roleBalance('custody', 'USD', $this->holder->id));
        $this->assertSame(1, $voucher->attachments()->count());
    }

    public function test_supervisor_submits_only_on_supervised_projects_and_needs_a_linked_party_and_an_invoice(): void
    {
        $other = Project::create(['name' => 'مشروع آخر', 'status' => 'active', 'branch_id' => $this->hq->id]);
        $this->submit(['project_id' => $other->id])->assertForbidden();
        $this->submit(['invoice' => null])->assertUnprocessable()->assertJsonValidationErrors('invoice');

        // An account without an employee linked to a party cannot submit.
        $stranger = $this->actor('site_supervisor');
        ProjectMember::create(['project_id' => $this->project->id, 'user_id' => $stranger->id, 'role' => 'supervisor']);
        $this->submit([], $stranger)->assertUnprocessable()->assertJsonValidationErrors('requested_by');

        // A request is visible to its requester and to finance, not to other supervisors.
        $mine = $this->submit()->assertCreated()->json('data');
        $this->login($stranger)->getJson('/api/v1/finance/site-requests/'.$mine['id'])->assertForbidden();
        $this->login($this->supervisor)->getJson('/api/v1/finance/site-requests/'.$mine['id'])->assertOk();
        $this->assertSame(1, SiteRequest::count());
    }
}
