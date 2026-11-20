<?php

namespace App\Modules\Finance\Services\Advances;

use App\Modules\Finance\Models\Custody;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Models\VoucherLine;
use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\FinanceNotifier;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Access\Access;
use App\Modules\Identity\Access\Scope;
use App\Modules\Identity\Contracts\EmployeeDirectory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Custodies (spec §5.7, entries #19–#21): money handed to a holder (supervisor, workshop master) to spend and settle
 * with invoices. One record per open (holder, project or general, currency); issues top it up (R15, with explicit
 * confirmation), settlements (R16, never above the balance) and returns draw it down. The cached totals are
 * recomputed from the posted, non-reversed journal lines on the custody account carrying the custody's id after
 * every post and reversal; a zero balance closes it, a reversal that brings money back reopens it.
 */
class CustodyService
{
    /** Voucher types that move a custody. */
    public const TYPES = ['custody_issue', 'custody_settlement', 'custody_return'];

    public function __construct(private SystemAccounts $roles, private FinanceScope $scope, private SettingService $settings,
        private FinanceNotifier $notifier, private Access $access, private EmployeeDirectory $directory, private ProjectService $projects,
        private AuditLogger $audit) {}

    /** The open custody of a holder for a project (null = general) in a currency. */
    public function openFor(int $holderPartyId, ?int $projectId, int $currencyId, bool $lock = false): ?Custody
    {
        return Custody::query()->where('holder_party_id', $holderPartyId)->where('currency_id', $currencyId)->where('status', 'open')
            ->when($projectId === null, fn ($q) => $q->whereNull('project_id'), fn ($q) => $q->where('project_id', $projectId))
            ->when($lock, fn ($q) => $q->lockForUpdate())->orderBy('id')->first();
    }

    /** Confirmation flag of R15 on the voucher (`meta.confirm_existing`). */
    public static function confirmed(Voucher $voucher): bool
    {
        return filter_var(($voucher->meta ?? [])['confirm_existing'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * R15 for an issue: an open custody for the same holder, project (or both general) and currency is topped up only
     * with `meta.confirm_existing = true`. Returns that custody (or null when a new one will be opened).
     */
    public function assertIssueAllowed(Voucher $voucher, bool $lock = false): ?Custody
    {
        $existing = $this->openFor((int) $voucher->party_id, $voucher->project_id, (int) $voucher->currency_id, $lock);
        if ($voucher->custody_id !== null && ($existing === null || $existing->id !== $voucher->custody_id)) {
            throw ValidationException::withMessages(['custody_id' => ['العهدة المختارة ليست العهدة المفتوحة لهذا الشخص على المشروع والعملة نفسيهما.']]);
        }
        if ($existing !== null && ! self::confirmed($voucher)) {
            $totals = $this->totals($existing);
            $where = $existing->project_id === null ? 'عهدة عامة' : 'عهدة على المشروع نفسه';
            throw ValidationException::withMessages(['meta.confirm_existing' => [
                "لدى صاحب العهدة {$where} مفتوحة برصيد ".Money::format($totals['balance'], $this->decimals($existing)).' '.$this->code($existing)
                .'. أكّد الصرف الإضافي عليها (confirm_existing) أو سوِّها أولاً.',
            ]]);
        }

        return $existing;
    }

    /**
     * The custody an issue posts to, inside the posting transaction: the holder is locked so two issues to the same
     * person serialise; the open custody is reused (R15 confirmed) or a new one is opened. The voucher gets its id.
     */
    public function resolveForIssue(Voucher $voucher, ?int $actorId): Custody
    {
        Party::query()->whereKey($voucher->party_id)->lockForUpdate()->first();
        $custody = $this->assertIssueAllowed($voucher, lock: true);
        if ($custody === null) {
            $custody = Custody::create(['holder_party_id' => $voucher->party_id, 'project_id' => $voucher->project_id, 'currency_id' => $voucher->currency_id,
                'branch_id' => $voucher->branch_id, 'status' => 'open', 'opened_on' => $voucher->voucher_date->toDateString(),
                'created_by' => $actorId, 'updated_by' => $actorId]);
            $this->audit->log('finance.custody_opened', $custody, null, $custody->getAttributes(), $actorId);
        }
        if ($voucher->custody_id !== $custody->id) {
            $voucher->forceFill(['custody_id' => $custody->id])->saveQuietly();
        }

        return $custody;
    }

    /** Locks the custody a settlement or return draws on; 422 when missing. */
    public function lockForDrawing(?int $custodyId): Custody
    {
        $custody = $custodyId === null ? null : Custody::query()->lockForUpdate()->find($custodyId);
        if ($custody === null) {
            throw ValidationException::withMessages(['custody_id' => ['اختر العهدة.']]);
        }

        return $custody;
    }

    /**
     * Issued (debits), returned (credits of custody_return vouchers) and settled (other credits) from the posted,
     * non-reversed lines on the custody account with this custody's id; balance = issued − settled − returned.
     *
     * @return array{issued: string, settled: string, returned: string, balance: string}
     */
    public function totals(Custody $custody): array
    {
        $row = JournalLine::query()
            ->join('fin_journal_entries as e', 'e.id', '=', 'fin_journal_lines.journal_entry_id')
            ->leftJoin('fin_vouchers as v', fn ($j) => $j->on('v.id', '=', 'e.source_id')->where('e.source_type', 'voucher'))
            ->where('fin_journal_lines.custody_id', $custody->id)->where('fin_journal_lines.account_id', $this->roles->id('custody'))
            ->where('e.status', 'posted')->whereNull('e.reversal_of_id')
            ->selectRaw("COALESCE(SUM(fin_journal_lines.debit), 0) as issued, COALESCE(SUM(fin_journal_lines.credit), 0) as credited,
                COALESCE(SUM(CASE WHEN v.type = 'custody_return' THEN fin_journal_lines.credit ELSE 0 END), 0) as returned")
            ->first();
        $issued = Money::of((string) $row->issued);
        $returned = Money::of((string) $row->returned);
        $settled = Money::sub(Money::of((string) $row->credited), $returned);

        return ['issued' => $issued, 'settled' => $settled, 'returned' => $returned, 'balance' => Money::sub(Money::sub($issued, $settled), $returned)];
    }

    /**
     * Rewrites the cached totals after a post or reversal dated `$date`. Zero balance → closed (closed_on = `$date`);
     * a positive balance → open. A negative balance (reversing an issue that was already spent) is refused, which
     * rolls the reversal back.
     */
    public function refresh(int $custodyId, string $date, ?int $actorId): Custody
    {
        $custody = Custody::query()->lockForUpdate()->findOrFail($custodyId);
        $totals = $this->totals($custody);
        if (Money::isNegative($totals['balance'])) {
            throw ValidationException::withMessages(['custody_id' => ['لا يُعكس صرف العهدة قبل عكس تسوياتها أو ردّها؛ الرصيد سيصبح سالباً.']]);
        }
        $open = Money::isPositive($totals['balance']);
        $old = $custody->only(['status', 'issued_total', 'settled_total', 'returned_total', 'balance', 'closed_on']);
        $custody->update([
            'issued_total' => $totals['issued'], 'settled_total' => $totals['settled'], 'returned_total' => $totals['returned'], 'balance' => $totals['balance'],
            'status' => $open ? 'open' : 'closed',
            'closed_on' => $open ? null : ($custody->status === 'closed' && $custody->closed_on !== null ? $custody->closed_on->toDateString() : $date),
            'updated_by' => $actorId ?? $custody->updated_by,
        ]);
        if ($old['status'] !== $custody->status) {
            $this->audit->log($open ? 'finance.custody_reopened' : 'finance.custody_closed', $custody, $old, $custody->only(array_keys($old)), $actorId);
        }

        return $custody;
    }

    /** @param  array<string, mixed>  $filters */
    public function list(array $filters, User $user): LengthAwarePaginator
    {
        $overdueBefore = $this->overdueBefore();
        $page = $this->scope->apply(Custody::query(), $user, 'finance.view')
            ->with(['holder:id,name', 'currency:id,code,decimals'])
            ->when($filters['holder_party_id'] ?? null, fn ($q, $v) => $q->where('holder_party_id', $v))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($filters['currency_id'] ?? null, fn ($q, $v) => $q->where('currency_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(array_key_exists('overdue', $filters), fn ($q) => filter_var($filters['overdue'], FILTER_VALIDATE_BOOL)
                ? $q->where('status', 'open')->where('opened_on', '<', $overdueBefore)
                : $q->where(fn ($q) => $q->where('status', 'closed')->orWhere('opened_on', '>=', $overdueBefore)))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderBy('opened_on')->orderBy('id')
            ->paginate(min(100, (int) ($filters['per_page'] ?? 25)));
        $projects = $this->projectNames($page->getCollection()->pluck('project_id')->all());

        return $page->through(fn (Custody $c) => $this->row($c, $projects));
    }

    /** One custody with every voucher that moved it (issues, settlement lines, returns) and its balance (spec §9.5). */
    public function show(Custody $custody, User $user): array
    {
        $this->scope->assertBranch($user, 'finance.view', $custody->branch_id);
        $custody->load(['holder:id,name', 'currency:id,code,decimals']);
        $vouchers = Voucher::query()->where('custody_id', $custody->id)->whereIn('status', ['approved', 'reversed'])
            ->orderBy('voucher_date')->orderBy('id')->get();
        $lines = VoucherLine::query()->whereIn('voucher_id', $vouchers->where('type', 'custody_settlement')->pluck('id'))->orderBy('line_no')->get()
            ->groupBy('voucher_id');
        $history = $vouchers->map(fn (Voucher $v) => [
            'id' => $v->id, 'number' => $v->number, 'type' => $v->type, 'purpose' => $v->purpose, 'kind' => $this->kind($v), 'voucher_date' => $v->voucher_date?->toDateString(),
            'status' => $v->status, 'cash_box_id' => $v->cash_box_id, 'description' => $v->description,
            'amount' => $v->type === 'custody_settlement' ? Money::sum(($lines[$v->id] ?? collect())->map(fn ($l) => (string) $l->debit)) : Money::of((string) $v->amount),
            'lines' => ($lines[$v->id] ?? collect())->map(fn (VoucherLine $l) => $l->only(['line_no', 'project_id', 'project_cost_category_id',
                'expense_category_id', 'party_id', 'asset_id', 'debit', 'memo']))->values()->all(),
        ])->values()->all();

        return [...$this->row($custody, $this->projectNames([$custody->project_id])), 'vouchers' => $history];
    }

    /**
     * "عهدتي" (spec §9.6) for the signed-in supervisor: the custodies held by the party linked to the user's employee
     * record, with balances and the last movements. Nothing when the account has no employee or party.
     */
    public function mine(User $user, int $movements = 20): array
    {
        $employeeId = $this->directory->employeeIdForUser($user->id);
        $party = $employeeId === null ? null : Party::query()->where('employee_id', $employeeId)->first();
        if ($party === null) {
            return ['party' => null, 'custodies' => [], 'balances' => [], 'movements' => []];
        }
        $custodies = Custody::query()->where('holder_party_id', $party->id)->with('currency:id,code,decimals')
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByDesc('opened_on')->orderByDesc('id')->limit(50)->get();
        $projects = $this->projectNames($custodies->pluck('project_id')->all());
        $balances = [];
        foreach ($custodies->where('status', 'open') as $custody) {
            $code = $custody->currency?->code;
            $balances[$code] = Money::add($balances[$code] ?? '0', (string) $custody->balance);
        }
        $recent = Voucher::query()->whereIn('custody_id', $custodies->pluck('id'))->where('status', 'approved')
            ->orderByDesc('voucher_date')->orderByDesc('id')->limit($movements)->get();
        $lineTotals = VoucherLine::query()->whereIn('voucher_id', $recent->where('type', 'custody_settlement')->pluck('id'))
            ->selectRaw('voucher_id, SUM(debit) as total')->groupBy('voucher_id')->pluck('total', 'voucher_id');

        return [
            'party' => ['id' => $party->id, 'name' => $party->name],
            'custodies' => $custodies->map(fn (Custody $c) => $this->row($c, $projects))->values()->all(),
            'balances' => collect($balances)->map(fn ($amount, $code) => ['currency' => $code, 'balance' => $amount])->values()->all(),
            'movements' => $recent->map(fn (Voucher $v) => ['voucher_id' => $v->id, 'number' => $v->number, 'kind' => $this->kind($v),
                'custody_id' => $v->custody_id, 'voucher_date' => $v->voucher_date?->toDateString(), 'description' => $v->description,
                'currency' => $custodies->firstWhere('id', $v->custody_id)?->currency?->code,
                'amount' => $v->type === 'custody_settlement' ? Money::of((string) ($lineTotals[$v->id] ?? '0')) : Money::of((string) $v->amount)])
                ->values()->all(),
        ];
    }

    /**
     * T16: open custodies with their age (days since opened), oldest first, and the open balance per currency.
     *
     * @return array{custodies: list<array<string, mixed>>, totals: list<array{currency: string, balance: string, count: int}>, overdue_days: int}
     */
    public function openReport(User $user): array
    {
        $custodies = $this->scope->apply(Custody::query(), $user, 'finance.view')->where('status', 'open')
            ->with(['holder:id,name', 'currency:id,code,decimals'])->orderBy('opened_on')->orderBy('id')->get();
        $projects = $this->projectNames($custodies->pluck('project_id')->all());
        $totals = [];
        foreach ($custodies as $custody) {
            $code = $custody->currency?->code;
            $totals[$code] ??= ['currency' => $code, 'balance' => '0', 'count' => 0];
            $totals[$code]['balance'] = Money::add($totals[$code]['balance'], (string) $custody->balance);
            $totals[$code]['count']++;
        }
        ksort($totals);

        return ['custodies' => $custodies->map(fn (Custody $c) => $this->row($c, $projects))->values()->all(), 'totals' => array_values($totals),
            'overdue_days' => $this->overdueDays()];
    }

    /**
     * R17: one notice per overdue open custody per day to the `finance.vouchers` holders who reach its branch.
     *
     * @return int custodies notified
     */
    public function notifyOverdue(): int
    {
        $today = now()->toDateString();
        $recipients = $this->access->usersWith('finance.vouchers', Scope::OWN);
        $sent = 0;
        $overdue = Custody::query()->where('status', 'open')->where('opened_on', '<', $this->overdueBefore())
            ->where(fn ($q) => $q->whereNull('overdue_notified_on')->orWhere('overdue_notified_on', '<', $today))
            ->with(['holder:id,name', 'currency:id,code,decimals'])->orderBy('id')->get();
        foreach ($overdue as $custody) {
            $claimed = Custody::query()->whereKey($custody->id)
                ->where(fn ($q) => $q->whereNull('overdue_notified_on')->orWhere('overdue_notified_on', '<', $today))
                ->update(['overdue_notified_on' => $today]);
            if ($claimed === 0) {
                continue; // another run took it
            }
            $age = $this->age($custody);
            $message = 'عهدة '.($custody->holder?->name ?? '').' بعملة '.$custody->currency?->code.' مفتوحة منذ '.$age.' يوماً، ورصيدها '
                .Money::format((string) $custody->balance, (int) $custody->currency?->decimals).'.';
            foreach ($recipients->filter(fn (User $u) => $this->scope->allows($u, 'finance.vouchers', $custody->branch_id)) as $user) {
                $this->notifier->toUser($user->id, 'finance.custody_overdue', $message, ['custody_id' => $custody->id, 'age_days' => $age]);
            }
            $sent++;
        }

        return $sent;
    }

    public function overdueDays(): int
    {
        return max(1, (int) $this->settings->get('finance.custody_overdue_days', 30));
    }

    /** Custodies opened before this date are overdue. */
    private function overdueBefore(): string
    {
        return now()->subDays($this->overdueDays())->toDateString();
    }

    private function age(Custody $custody): int
    {
        return (int) CarbonImmutable::parse($custody->opened_on->toDateString())->diffInDays(now()->startOfDay(), absolute: true);
    }

    /** issue | settlement | return, from the voucher type (payment.custody is an issue). */
    private function kind(Voucher $voucher): string
    {
        return match ($voucher->type) {
            'custody_settlement' => 'settlement',
            'custody_return' => 'return',
            default => 'issue',
        };
    }

    /** @param  array<int, string>  $projects */
    private function row(Custody $custody, array $projects): array
    {
        $age = $this->age($custody);

        return [
            'id' => $custody->id, 'holder_party_id' => $custody->holder_party_id, 'holder_name' => $custody->holder?->name,
            'project_id' => $custody->project_id, 'project_name' => $custody->project_id === null ? null : ($projects[$custody->project_id] ?? null),
            'currency_id' => $custody->currency_id, 'currency' => $custody->currency?->code, 'branch_id' => $custody->branch_id, 'status' => $custody->status,
            'opened_on' => $custody->opened_on?->toDateString(), 'closed_on' => $custody->closed_on?->toDateString(),
            'issued_total' => Money::of((string) $custody->issued_total), 'settled_total' => Money::of((string) $custody->settled_total),
            'returned_total' => Money::of((string) $custody->returned_total), 'balance' => Money::of((string) $custody->balance),
            'age_days' => $age, 'overdue' => $custody->status === 'open' && $age > $this->overdueDays(),
        ];
    }

    /**
     * @param  list<int|null>  $ids
     * @return array<int, string>
     */
    private function projectNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : $this->projects->query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function code(Custody $custody): string
    {
        return (string) Currency::query()->whereKey($custody->currency_id)->value('code');
    }

    private function decimals(Custody $custody): int
    {
        return (int) Currency::query()->whereKey($custody->currency_id)->value('decimals');
    }
}
