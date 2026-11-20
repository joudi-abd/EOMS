<?php

namespace App\Modules\Finance\Services\Core;

use App\Modules\Finance\Contracts\PeriodCloseCheck;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\CashCount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Period;
use App\Modules\Finance\Models\SiteRequest;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly fiscal periods (R1). A month gets its row the first time something posts into it; closed months take no
 * postings and no reversals. Months close in calendar order after the pre-close checks (spec §9.8) and reopen with a
 * reason, latest closed month first (OP-18).
 */
class PeriodService
{
    private const TX_ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit) {}

    /** Locks the month's row (creating it when missing) and refuses a closed month. */
    public function ensureOpen(string $date): Period
    {
        $period = $this->lockRow((int) substr($date, 0, 4), (int) substr($date, 5, 2));
        if ($period->status === 'closed') {
            throw ValidationException::withMessages(['date' => ['الشهر '.$this->label($period->year, $period->month).' مقفل؛ سجّل في فترة مفتوحة.']]);
        }

        return $period;
    }

    public function isClosed(string $date): bool
    {
        return Period::query()->where('year', (int) substr($date, 0, 4))->where('month', (int) substr($date, 5, 2))->where('status', 'closed')->exists();
    }

    /** @return Collection<int, Period> */
    public function list(): Collection
    {
        return Period::query()->orderByDesc('year')->orderByDesc('month')->get();
    }

    /**
     * What stands in the way of closing the month (blockers) and what only deserves a look (warnings).
     *
     * @return array{month: string, blockers: list<string>, warnings: list<string>}
     */
    public function checks(int $year, int $month): array
    {
        $blockers = [];
        $warnings = [];
        foreach ($this->checklist($year, $month) as $item) {
            foreach ($item['passed'] ? [] : $item['messages'] as $message) {
                if ($item['level'] === 'blocker') {
                    $blockers[] = $message;
                } else {
                    $warnings[] = $message;
                }
            }
        }

        return ['month' => $this->label($year, $month), 'blockers' => $blockers, 'warnings' => $warnings];
    }

    /**
     * The closing screen's checklist (spec §9.8): every check with its level, whether it passes, the count behind it and
     * its messages — timing, earlier open months, draft vouchers, site requests, cash counts, the month's allocations
     * (warnings) and the checks other modules contribute (payroll: the month's pay run).
     *
     * @return list<array{key: string, label: string, level: string, passed: bool, count: int|null, messages: list<string>}>
     */
    public function checklist(int $year, int $month): array
    {
        [$from, $to] = $this->bounds($year, $month);
        $item = fn (string $key, string $label, string $level, ?int $count, array $messages) => ['key' => $key, 'label' => $label, 'level' => $level,
            'passed' => $messages === [], 'count' => $count, 'messages' => $messages];
        $items = [];
        $items[] = $item('month_ended', 'انتهاء الشهر', 'blocker', null,
            $this->label($year, $month) >= now()->format('Y-m') ? ['يُقفل الشهر بعد انتهائه.'] : []);
        $open = $this->earlierOpenMonths($year, $month);
        $items[] = $item('earlier_months', 'الأشهر السابقة مقفلة', 'blocker', count($open),
            $open === [] ? [] : ['أقفل الأشهر السابقة أولاً: '.implode('، ', array_slice($open, 0, 6)).'.']);
        $pending = Voucher::query()->whereIn('status', ['draft', 'submitted'])->whereBetween('voucher_date', [$from, $to])->count();
        $items[] = $item('pending_vouchers', 'مسودات معلّقة', 'blocker', $pending,
            $pending > 0 ? ["توجد $pending سندات مسودة أو بانتظار الاعتماد في هذا الشهر."] : []);
        $requests = SiteRequest::query()->where('status', 'submitted')->where('created_at', '<=', $to.' 23:59:59')->count();
        $items[] = $item('site_requests', 'طلبات المشرفين غير المعالجة', 'blocker', $requests,
            $requests > 0 ? ["توجد $requests طلبات مصروف موقع غير معالجة."] : []);
        $counts = CashCount::query()->whereIn('status', ['draft', 'submitted'])->whereBetween('count_date', [$from, $to])->count();
        $items[] = $item('cash_counts', 'جرد غير معتمد', 'blocker', $counts, $counts > 0 ? ["يوجد $counts جرد غير معتمد في هذا الشهر."] : []);
        foreach (['payroll' => 'توزيع الرواتب على المشاريع', 'overhead' => 'توزيع المصاريف العامة على المشاريع'] as $kind => $label) {
            $done = Allocation::query()->where('kind', $kind)->where('year', $year)->where('month', $month)->where('status', 'approved')->exists();
            $items[] = $item("allocation_$kind", $label, 'warning', $done ? 1 : 0, $done ? [] : ["لم يُنفَّذ $label لهذا الشهر."]);
        }
        foreach (app()->tagged(PeriodCloseCheck::TAG) as $check) {
            $result = $check->check($year, $month);
            $items[] = $item('module:'.class_basename($check), 'كشف رواتب الشهر وفحوصات الوحدات الأخرى', 'blocker', null, $result['blockers']);
            if ($result['warnings'] !== []) {
                $items[] = $item('module_warning:'.class_basename($check), 'كشف رواتب الشهر وفحوصات الوحدات الأخرى', 'warning', null, $result['warnings']);
            }
        }

        return $items;
    }

    /**
     * The closing screen's list (spec §9.8): every month from the first posting (or the first period row) up to the
     * current month, newest first, with its status and, for open months, the checklist and its blocker / warning counts.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $first = JournalEntry::query()->min('entry_date') ?? Period::query()->orderBy('year')->orderBy('month')->first()?->only(['year', 'month']);
        if ($first === null) {
            return [];
        }
        $start = is_array($first) ? CarbonImmutable::create($first['year'], $first['month'], 1) : CarbonImmutable::createFromFormat('!Y-m', substr((string) $first, 0, 7));
        $periods = Period::query()->get()->keyBy(fn (Period $p) => $this->label($p->year, $p->month));
        $rows = [];
        for ($m = $start; $m->format('Y-m') <= now()->format('Y-m'); $m = $m->addMonth()) {
            $label = $m->format('Y-m');
            $period = $periods->get($label);
            $status = $period?->status ?? 'open';
            $row = ['month' => $label, 'year' => (int) $m->format('Y'), 'month_number' => (int) $m->format('n'), 'status' => $status,
                'closed_at' => $period?->closed_at?->toIso8601String(), 'closed_by' => $period?->closed_by,
                'reopened_at' => $period?->reopened_at?->toIso8601String(), 'reopen_reason' => $period?->reopen_reason];
            if ($status === 'open') {
                $checklist = $this->checklist((int) $m->format('Y'), (int) $m->format('n'));
                $failing = array_filter($checklist, fn ($i) => ! $i['passed']);
                $row['blockers'] = count(array_filter($failing, fn ($i) => $i['level'] === 'blocker'));
                $row['warnings'] = count(array_filter($failing, fn ($i) => $i['level'] === 'warning'));
                $row['can_close'] = $row['blockers'] === 0;
                $row['checklist'] = $checklist;
            }
            $rows[] = $row;
        }

        return array_reverse($rows);
    }

    /** @return array{period: Period, warnings: list<string>} */
    public function close(int $year, int $month, User $actor): array
    {
        return DB::transaction(function () use ($year, $month, $actor) {
            $period = $this->lockRow($year, $month);
            abort_if($period->status === 'closed', 409, 'الشهر مقفل مسبقاً.');
            // Checked under the period lock: nothing can post into the month while it closes.
            $checks = $this->checks($year, $month);
            if ($checks['blockers'] !== []) {
                throw ValidationException::withMessages(['period' => $checks['blockers']]);
            }
            $period->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()]);
            $this->audit->log('finance.period_closed', $period, ['status' => 'open'], ['status' => 'closed', 'month' => $checks['month'],
                'warnings' => $checks['warnings']], $actor->id);

            return ['period' => $period->refresh(), 'warnings' => $checks['warnings']];
        }, self::TX_ATTEMPTS);
    }

    public function reopen(int $year, int $month, string $reason, User $actor): Period
    {
        return DB::transaction(function () use ($year, $month, $reason, $actor) {
            $period = Period::query()->where('year', $year)->where('month', $month)->lockForUpdate()->first();
            abort_unless($period !== null && $period->status === 'closed', 409, 'الشهر غير مقفل.');
            // Closed months stay a prefix of the calendar: reopening a month under a later closed one would change
            // that month's opening balances behind its back.
            $laterClosed = Period::query()->where('status', 'closed')
                ->where(fn ($q) => $q->where('year', '>', $year)->orWhere(fn ($q) => $q->where('year', $year)->where('month', '>', $month)))->exists();
            abort_if($laterClosed, 422, 'أعد فتح الأشهر اللاحقة المقفلة أولاً.');
            $period->update(['status' => 'open', 'reopened_by' => $actor->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);
            $this->audit->log('finance.period_reopened', $period, ['status' => 'closed'], ['status' => 'open', 'reason' => $reason], $actor->id);

            return $period->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** @return array{0: string, 1: string} first and last day */
    public function bounds(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1);

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    public function label(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }

    /** Insert-or-ignore first so two concurrent callers lock the same row instead of racing on the unique key. */
    private function lockRow(int $year, int $month): Period
    {
        Period::query()->insertOrIgnore(['year' => $year, 'month' => $month, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        return Period::query()->where('year', $year)->where('month', $month)->lockForUpdate()->firstOrFail();
    }

    /** @return list<string> months with postings before this one that are still open */
    private function earlierOpenMonths(int $year, int $month): array
    {
        $first = JournalEntry::query()->min('entry_date');
        if ($first === null || substr((string) $first, 0, 7) >= $this->label($year, $month)) {
            return [];
        }
        $closed = Period::query()->where('status', 'closed')->get(['year', 'month'])->map(fn (Period $p) => $this->label($p->year, $p->month))->all();
        $open = [];
        for ($m = CarbonImmutable::createFromFormat('!Y-m', substr((string) $first, 0, 7)); $m->format('Y-m') < $this->label($year, $month); $m = $m->addMonth()) {
            if (! in_array($m->format('Y-m'), $closed, true)) {
                $open[] = $m->format('Y-m');
            }
        }

        return $open;
    }
}
