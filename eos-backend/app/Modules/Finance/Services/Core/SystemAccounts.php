<?php

namespace App\Modules\Finance\Services\Core;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\SystemAccount;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The fixed roles automatic postings rely on (spec §4.3, plus rounding_differences OP-16 and asset_maintenance #23).
 * Finance builds its own chart and picks which account plays each role; nothing posts while a role it needs is
 * unmapped.
 */
class SystemAccounts
{
    /** role => [Arabic label, the account type the starter chart uses] */
    public const ROLES = [
        'cash_boxes_parent' => ['الأب الذي تُنشأ تحته حسابات الصناديق', 'asset'],
        'receivables_clients' => ['ذمم العملاء', 'asset'],
        'client_advances' => ['دفعات العملاء المقدمة', 'liability'],
        'payables_suppliers' => ['ذمم الموردين', 'liability'],
        'payables_contractors' => ['ذمم المقاولين', 'liability'],
        'contractor_advances' => ['سلف المقاولين', 'asset'],
        'employee_advances' => ['سلف الموظفين', 'asset'],
        'custody' => ['العُهد', 'asset'],
        'salaries_payable' => ['رواتب مستحقة', 'liability'],
        'salaries_expense' => ['مصروف الرواتب', 'expense'],
        'project_costs' => ['تكاليف المشاريع', 'expense'],
        'project_revenue' => ['إيرادات المشاريع', 'revenue'],
        'payroll_allocation_contra' => ['مقابل تحميل الرواتب على المشاريع', 'expense'],
        'overhead_allocation_contra' => ['مقابل تحميل المصاريف العامة على المشاريع', 'expense'],
        'fx_position' => ['مركز تصريف العملات', 'asset'],
        'transfer_fees' => ['عمولات التحويل والصرافة', 'expense'],
        'partners_current' => ['الحسابات الجارية للشركاء', 'equity'],
        'partners_capital' => ['رأس المال', 'equity'],
        'fixed_assets' => ['الأصول الثابتة', 'asset'],
        'asset_disposal_gain_loss' => ['أرباح أو خسائر بيع الأصول', 'revenue'],
        'asset_maintenance' => ['صيانة أصول', 'expense'],
        'cash_over_short' => ['فروقات الجرد', 'expense'],
        'other_receivables' => ['ذمم أخرى مدينة', 'asset'],
        'other_payables' => ['ذمم أخرى دائنة', 'liability'],
        'opening_balance_equity' => ['حساب الأرصدة الافتتاحية', 'equity'],
        'rounding_differences' => ['فروقات التقريب', 'expense'],
    ];

    public function __construct(private AuditLogger $audit) {}

    public static function label(string $role): string
    {
        return self::ROLES[$role][0] ?? $role;
    }

    /** The account id playing `$role`; 422 when the role is not mapped. */
    public function id(string $role): int
    {
        $id = $this->mappedId($role);
        if ($id === null) {
            throw ValidationException::withMessages(['system_accounts' => ['اربط الحساب "'.self::label($role).'" من إعدادات الحسابات الإلزامية.']]);
        }

        return $id;
    }

    public function account(string $role): Account
    {
        return Account::query()->findOrFail($this->id($role));
    }

    public function mappedId(string $role): ?int
    {
        return $this->map()[$role] ?? null;
    }

    /** @return list<string> roles the account plays */
    public function rolesOf(int $accountId): array
    {
        return array_keys(array_filter($this->map(), fn (int $id) => $id === $accountId));
    }

    /** True when the account plays a role other than the `$allowed` ones. */
    public function isRoleAccount(int $accountId, array $allowed = []): bool
    {
        return array_diff($this->rolesOf($accountId), $allowed) !== [];
    }

    /**
     * Every role with its account, flagged when unmapped.
     *
     * @return array{roles: list<array{role: string, label: string, account_id: ?int, account: ?array, unmapped: bool}>, unmapped_count: int}
     */
    public function all(): array
    {
        $map = $this->map();
        $accounts = Account::query()->whereIn('id', array_values($map))->get(['id', 'code', 'name', 'type', 'is_active'])->keyBy('id');
        $roles = [];
        foreach (self::ROLES as $role => [$label]) {
            $account = isset($map[$role]) ? $accounts->get($map[$role]) : null;
            $roles[] = ['role' => $role, 'label' => $label, 'account_id' => $account?->id, 'account' => $account?->only(['id', 'code', 'name', 'type', 'is_active']),
                'unmapped' => $account === null];
        }

        return ['roles' => $roles, 'unmapped_count' => count(array_filter($roles, fn ($r) => $r['unmapped']))];
    }

    /**
     * Maps roles to accounts. Every role except `cash_boxes_parent` needs an active postable account; the boxes'
     * parent must be an active asset account without postings of its own (box accounts are created under it).
     *
     * @param  array<string, int>  $mappings  role => account id
     */
    public function set(array $mappings, User $actor): array
    {
        DB::transaction(function () use ($mappings, $actor) {
            $errors = [];
            $old = SystemAccount::query()->pluck('account_id', 'role_key')->all();
            foreach ($mappings as $role => $accountId) {
                if (! isset(self::ROLES[$role])) {
                    $errors["mappings.$role"] = ['دور غير معروف.'];

                    continue;
                }
                $account = Account::query()->lockForUpdate()->find($accountId);
                if ($account === null || ! $account->is_active) {
                    $errors["mappings.$role"] = ['الحساب غير موجود أو غير فعّال.'];
                } elseif ($role === 'cash_boxes_parent') {
                    if ($account->type !== 'asset' || $account->is_system || JournalLine::query()->where('account_id', $account->id)->exists()) {
                        $errors["mappings.$role"] = ['أب الصناديق حساب أصول بلا حركات وليس حساب صندوق.'];
                    }
                } elseif (! $account->is_postable || $account->is_system) {
                    $errors["mappings.$role"] = ['اختر حساباً فرعياً يقبل القيود، وليس حساب صندوق.'];
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            foreach ($mappings as $role => $accountId) {
                SystemAccount::query()->updateOrCreate(['role_key' => $role], ['account_id' => (int) $accountId, 'updated_by' => $actor->id]);
            }
            $this->audit->log('finance.system_accounts_mapped', null, $old, $mappings, $actor->id);
        }, 3);

        return $this->all();
    }

    /**
     * Read on every call (no memo): services live on in cached controllers and long-running workers, and a
     * remapped role must take effect at once.
     *
     * @return array<string, int>
     */
    private function map(): array
    {
        return SystemAccount::query()->pluck('account_id', 'role_key')->map(fn ($id) => (int) $id)->all();
    }
}
