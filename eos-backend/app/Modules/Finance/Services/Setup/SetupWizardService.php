<?php

namespace App\Modules\Finance\Services\Setup;

use App\Modules\Finance\Database\Seeders\FinanceChartSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\CashBoxCurrency;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Models\ProjectBudgetLine;
use App\Modules\Finance\Models\ProjectProfile;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Services\Core\SystemAccounts;
use App\Modules\Finance\Services\Reports\Sums;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;

/**
 * The setup wizard (spec §12): the state of its eight steps, the starter chart, and the opening check (step 8).
 * Step 7 (opening balances) is posted by OpeningBalanceService; existing assets by `POST /assets/opening`.
 */
class SetupWizardService
{
    public function __construct(private SystemAccounts $roles, private ProjectService $projects, private AuditLogger $audit) {}

    /**
     * Each step with done / pending and what is missing.
     *
     * @return array{steps: list<array<string, mixed>>, done: int, total: int, completed: bool}
     */
    public function status(): array
    {
        $steps = [$this->currencies(), $this->branches(), $this->chart(), $this->boxes(), $this->parties(), $this->projectsStep(), $this->openings()];
        $check = $this->openingCheck(null);
        $steps[] = ['step' => 8, 'key' => 'opening_check', 'title' => 'التحقق من ميزان المراجعة الافتتاحي',
            'done' => $check['date'] !== null && $check['balanced'] && $check['equity_matches'],
            'missing' => $check['date'] === null ? ['لا توجد أرصدة افتتاحية للتحقق منها.'] : array_values(array_filter([
                $check['balanced'] ? null : 'ميزان المراجعة الافتتاحي غير متوازن في: '.implode('، ', array_column(array_filter($check['currencies'], fn ($c) => ! $c['balanced']), 'currency')).'.',
                $check['equity_matches'] ? null : 'حساب الأرصدة الافتتاحية لا يطابق صافي الأصول.',
            ])), 'details' => ['date' => $check['date'], 'link' => '/api/v1/finance/setup/opening-check']];
        $done = count(array_filter($steps, fn ($s) => $s['done']));

        return ['steps' => $steps, 'done' => $done, 'total' => count($steps), 'completed' => $done === count($steps)];
    }

    /**
     * The starter chart (FinanceChartSeeder: accounts mapped to every role, expense categories, project cost categories,
     * asset categories), only while the chart is empty.
     */
    public function starterChart(User $actor): array
    {
        return DB::transaction(function () use ($actor) {
            abort_if(Account::query()->lockForUpdate()->exists(), 409, 'دليل الحسابات ليس فارغاً؛ دليل البداية يُنشأ على دليل فارغ فقط.');
            app(FinanceChartSeeder::class)->run();
            $this->audit->log('finance.starter_chart_created', null, null, ['accounts' => Account::query()->count()], $actor->id);

            return ['accounts' => Account::query()->count(), 'system_accounts' => $this->roles->all()];
        });
    }

    /**
     * Step 8: the trial balance on the opening date (by default the earliest posted opening balance) balanced in each
     * currency, and `opening_balance_equity` against the net assets: OBE = assets − liabilities − other equity −
     * profit to date (opening project costs and billings sit in the profit to date).
     */
    public function openingCheck(?string $date): array
    {
        $date ??= Voucher::query()->where('type', 'opening_balance')->where('status', 'approved')->min('voucher_date');
        if ($date === null) {
            return ['date' => null, 'balanced' => false, 'equity_matches' => false, 'currencies' => []];
        }
        $date = substr((string) $date, 0, 10);
        $obe = $this->roles->mappedId('opening_balance_equity');
        $rows = JournalLine::query()->join('fin_accounts as a', 'a.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journal_lines.entry_date', '<=', $date)->groupBy('fin_journal_lines.currency_id', 'a.type')
            ->selectRaw('fin_journal_lines.currency_id, a.type, SUM(fin_journal_lines.debit) as debit, SUM(fin_journal_lines.credit) as credit,
                SUM(CASE WHEN fin_journal_lines.account_id = ? THEN fin_journal_lines.credit - fin_journal_lines.debit ELSE 0 END) as obe', [$obe ?? 0])
            ->get();
        $codes = Currency::query()->pluck('code', 'id');
        $currencies = [];
        foreach ($rows->groupBy('currency_id') as $currencyId => $group) {
            $by = fn (string $type) => $group->firstWhere('type', $type);
            $net = fn (string $type) => Money::sub(Sums::num($by($type)?->debit), Sums::num($by($type)?->credit)); // debit − credit
            $debit = Money::sum($group->map(fn ($r) => Sums::num($r->debit)));
            $credit = Money::sum($group->map(fn ($r) => Sums::num($r->credit)));
            $equityObe = Money::sum($group->map(fn ($r) => Sums::num($r->obe)));
            $netAssets = Money::add($net('asset'), $net('liability'));
            $otherEquity = Money::sub(Money::neg($net('equity')), $equityObe);
            $profit = Money::neg(Money::add($net('revenue'), $net('expense')));
            $expected = Money::sub(Money::sub($netAssets, $otherEquity), $profit);
            $currencies[] = ['currency_id' => (int) $currencyId, 'currency' => $codes[$currencyId] ?? null, 'total_debit' => $debit, 'total_credit' => $credit,
                'balanced' => Money::cmp($debit, $credit) === 0, 'opening_balance_equity' => $equityObe, 'net_assets' => $netAssets,
                'other_equity' => $otherEquity, 'profit_to_date' => $profit, 'equity_matches' => Money::cmp($equityObe, $expected) === 0];
        }
        usort($currencies, fn ($a, $b) => strcmp((string) $a['currency'], (string) $b['currency']));

        return ['date' => $date, 'balanced' => $currencies !== [] && ! in_array(false, array_column($currencies, 'balanced'), true),
            'equity_matches' => $currencies !== [] && ! in_array(false, array_column($currencies, 'equity_matches'), true), 'currencies' => $currencies];
    }

    private function step(int $n, string $key, string $title, array $missing, array $details = [], ?bool $done = null): array
    {
        return ['step' => $n, 'key' => $key, 'title' => $title, 'done' => $done ?? $missing === [], 'missing' => $missing, 'details' => $details];
    }

    private function currencies(): array
    {
        $active = Currency::query()->where('is_active', true)->get();
        $missing = [];
        if ($active->firstWhere('is_base', true) === null) {
            $missing[] = 'لا توجد عملة أساسية فعّالة (الدولار).';
        }
        $rated = ExchangeRate::query()->distinct()->pluck('currency_id')->map(fn ($id) => (int) $id)->all();
        $noRate = $active->where('is_base', false)->reject(fn ($c) => in_array($c->id, $rated, true))->pluck('code')->all();
        if ($noRate !== []) {
            $missing[] = 'أدخل النشرة الأولى للعملات: '.implode('، ', $noRate).'.';
        }

        return $this->step(1, 'currencies', 'العملات والنشرة الأولى', $missing, ['active' => $active->pluck('code')->all(), 'without_rate' => $noRate]);
    }

    private function branches(): array
    {
        $active = Branch::query()->where('is_active', true)->get(['id', 'name', 'is_head_office']);
        $missing = [];
        if ($active->isEmpty()) {
            $missing[] = 'أضف فرعاً واحداً على الأقل.';
        } elseif (! $active->contains('is_head_office', true)) {
            $missing[] = 'حدّد الشركة الرئيسية.';
        }

        return $this->step(2, 'branches', 'الفروع', $missing, ['count' => $active->count()]);
    }

    private function chart(): array
    {
        $accounts = Account::query()->count();
        $roles = $this->roles->all();
        $unmapped = array_column(array_filter($roles['roles'], fn ($r) => $r['unmapped']), 'label');
        $missing = $accounts === 0 ? ['أنشئ دليل الحسابات أو دليل البداية.'] : [];
        if ($unmapped !== []) {
            $missing[] = 'اربط الحسابات الإلزامية: '.implode('، ', $unmapped).'.';
        }

        return $this->step(3, 'chart', 'دليل الحسابات وربط الحسابات الإلزامية', $missing, ['accounts' => $accounts, 'unmapped_count' => $roles['unmapped_count'],
            'starter_chart_available' => $accounts === 0]);
    }

    private function boxes(): array
    {
        $boxes = CashBox::query()->where('is_active', true)->get(['id', 'name']);
        $withCurrency = CashBoxCurrency::query()->whereIn('cash_box_id', $boxes->pluck('id'))->distinct()->pluck('cash_box_id')->all();
        $bare = $boxes->reject(fn ($b) => in_array($b->id, $withCurrency, true))->pluck('name')->all();
        $missing = $boxes->isEmpty() ? ['أضف صندوقاً واحداً على الأقل.'] : [];
        if ($bare !== []) {
            $missing[] = 'صناديق بلا عملات: '.implode('، ', $bare).'.';
        }

        return $this->step(4, 'boxes', 'الصناديق وعملاتها', $missing, ['count' => $boxes->count()]);
    }

    private function parties(): array
    {
        $roles = PartyRole::query()->whereIn('party_id', Party::query()->select('id')->where('is_active', true))
            ->groupBy('role')->selectRaw('role, COUNT(*) as n')->pluck('n', 'role')->map(fn ($n) => (int) $n)->all();
        $count = Party::query()->where('is_active', true)->count();

        return $this->step(5, 'parties', 'جهات التعامل', $count === 0 ? ['أضف جهات التعامل أو استوردها (POST /finance/parties/import).'] : [],
            ['count' => $count, 'by_role' => $roles]);
    }

    private function projectsStep(): array
    {
        $active = $this->projects->query()->where('status', 'active')->get(['id', 'name']);
        $profiles = ProjectProfile::query()->whereIn('project_id', $active->pluck('id'))->pluck('project_id')->map(fn ($id) => (int) $id)->all();
        $budgets = ProjectBudgetLine::query()->whereIn('project_id', $active->pluck('id'))->distinct()->pluck('project_id')->map(fn ($id) => (int) $id)->all();
        $noProfile = $active->reject(fn ($p) => in_array($p->id, $profiles, true))->pluck('name')->all();
        $noBudget = $active->filter(fn ($p) => in_array($p->id, $profiles, true) && ! in_array($p->id, $budgets, true))->pluck('name')->all();
        $missing = $noProfile === [] ? [] : ['مشاريع نشطة بلا عقد مالي: '.implode('، ', $noProfile).'.'];

        return $this->step(6, 'projects', 'المشاريع النشطة: العقد والموازنة والاتفاقيات', $missing, ['active' => $active->count(),
            'without_budget' => $noBudget]);
    }

    private function openings(): array
    {
        $vouchers = Voucher::query()->where('type', 'opening_balance')->where('status', 'approved')->orderBy('voucher_date')->get(['id', 'number', 'voucher_date', 'branch_id', 'meta']);
        $wizard = $vouchers->filter(fn (Voucher $v) => ($v->meta['setup_wizard'] ?? false) === true);
        $assets = Asset::query()->whereIn('purchase_voucher_id', $vouchers->pluck('id'))->count();

        return $this->step(7, 'opening_balances', 'الأرصدة الافتتاحية', $vouchers->isEmpty() ? ['رحّل الأرصدة الافتتاحية (POST /finance/setup/opening-balances).'] : [], [
            'vouchers' => $vouchers->map(fn (Voucher $v) => ['id' => $v->id, 'number' => $v->number, 'date' => $v->voucher_date->toDateString(),
                'branch_id' => $v->branch_id, 'setup_wizard' => ($v->meta['setup_wizard'] ?? false) === true])->values()->all(),
            'start_date' => $wizard->first()?->voucher_date?->toDateString(),
            'opening_assets' => $assets, 'assets_link' => '/api/v1/finance/assets/opening', 'assets_import_link' => '/api/v1/finance/assets/import',
        ]);
    }
}
