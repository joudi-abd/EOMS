<?php

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AssetCategory;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\ProjectCostCategory;
use App\Modules\Finance\Models\SystemAccount;
use Illuminate\Database\Seeder;

/**
 * A small starter chart that maps every system role, plus the expense category tree (spec §5.5), the project cost
 * categories (§5.6) and a few asset categories. Finance edits all of it afterwards. Safe to run again: existing codes,
 * mappings and categories are kept. Not run by migrations (finance builds its own chart); used by tests and the
 * setup wizard's "starter chart".
 */
class FinanceChartSeeder extends Seeder
{
    /** code => [name, type, parent code, role, requires_party, requires_project] */
    private const ACCOUNTS = [
        '1' => ['الأصول', 'asset', null, null, false, false],
        '1100' => ['النقدية والصناديق', 'asset', '1', 'cash_boxes_parent', false, false],
        '1200' => ['ذمم العملاء', 'asset', '1', 'receivables_clients', true, false],
        '1300' => ['ذمم أخرى مدينة', 'asset', '1', 'other_receivables', true, false],
        '1400' => ['سلف الموظفين', 'asset', '1', 'employee_advances', false, false],
        '1500' => ['سلف المقاولين', 'asset', '1', 'contractor_advances', true, false],
        '1600' => ['العُهد', 'asset', '1', 'custody', true, false],
        '1700' => ['مركز تصريف العملات', 'asset', '1', 'fx_position', false, false],
        '1800' => ['الأصول الثابتة', 'asset', '1', 'fixed_assets', false, false],
        '2' => ['الخصوم', 'liability', null, null, false, false],
        '2100' => ['ذمم الموردين', 'liability', '2', 'payables_suppliers', true, false],
        '2200' => ['ذمم المقاولين', 'liability', '2', 'payables_contractors', true, false],
        '2300' => ['دفعات العملاء المقدمة', 'liability', '2', 'client_advances', true, false],
        '2400' => ['رواتب مستحقة', 'liability', '2', 'salaries_payable', false, false],
        '2500' => ['ذمم أخرى دائنة', 'liability', '2', 'other_payables', true, false],
        '3' => ['حقوق الملكية', 'equity', null, null, false, false],
        '3100' => ['رأس المال', 'equity', '3', 'partners_capital', false, false],
        '3200' => ['الحسابات الجارية للشركاء', 'equity', '3', 'partners_current', true, false],
        '3900' => ['الأرصدة الافتتاحية', 'equity', '3', 'opening_balance_equity', false, false],
        '4' => ['الإيرادات', 'revenue', null, null, false, false],
        '4100' => ['إيرادات المشاريع', 'revenue', '4', 'project_revenue', false, true],
        '4200' => ['أرباح وخسائر بيع الأصول', 'revenue', '4', 'asset_disposal_gain_loss', false, false],
        '5' => ['المصروفات', 'expense', null, null, false, false],
        '5100' => ['مصروف الرواتب', 'expense', '5', 'salaries_expense', false, false],
        '5200' => ['تكاليف المشاريع', 'expense', '5', 'project_costs', false, true],
        '5300' => ['مقابل تحميل الرواتب على المشاريع', 'expense', '5', 'payroll_allocation_contra', false, false],
        '5400' => ['مقابل تحميل المصاريف العامة على المشاريع', 'expense', '5', 'overhead_allocation_contra', false, false],
        '5500' => ['عمولات التحويل والصرافة', 'expense', '5', 'transfer_fees', false, false],
        '5600' => ['صيانة أصول', 'expense', '5', 'asset_maintenance', false, false],
        '5700' => ['تشغيل أصول', 'expense', '5', null, false, false],
        '5800' => ['فروقات الجرد', 'expense', '5', 'cash_over_short', false, false],
        '5900' => ['فروقات التقريب', 'expense', '5', 'rounding_differences', false, false],
        '6100' => ['مصاريف تشغيلية', 'expense', '5', null, false, false],
        '6101' => ['بوفيه وضيافة', 'expense', '6100', null, false, false],
        '6102' => ['طعام', 'expense', '6100', null, false, false],
        '6103' => ['كهرباء وإنترنت', 'expense', '6100', null, false, false],
        '6104' => ['محروقات', 'expense', '6100', null, false, false],
        '6105' => ['مياه', 'expense', '6100', null, false, false],
        '6106' => ['نظافة ومستلزمات', 'expense', '6100', null, false, false],
        '6107' => ['قرطاسية وطباعة', 'expense', '6100', null, false, false],
        '6108' => ['مواصلات ومهام', 'expense', '6100', null, false, false],
        '6200' => ['مصاريف إدارية', 'expense', '5', null, false, false],
        '6201' => ['تراخيص ونقابة', 'expense', '6200', null, false, false],
        '6202' => ['برمجيات واشتراكات', 'expense', '6200', null, false, false],
    ];

    /** Expense category tree: parent name => [leaf name => account code]; a string value is a leaf at the top. */
    private const EXPENSE_CATEGORIES = [
        'مصاريف تشغيلية' => ['بوفيه وضيافة' => '6101', 'طعام' => '6102', 'كهرباء وإنترنت' => '6103', 'محروقات' => '6104', 'مياه' => '6105',
            'نظافة ومستلزمات' => '6106', 'قرطاسية وطباعة' => '6107', 'مواصلات ومهام' => '6108'],
        'مصاريف إدارية' => ['تراخيص ونقابة' => '6201', 'برمجيات واشتراكات' => '6202'],
        'صيانة أصول' => '5600',
        'تشغيل أصول (محروقات وصيانة سيارة ومولدة)' => '5700',
    ];

    /** system_key => [name, is_billable] (spec §5.6, OP-2: loaded costs are not billed). */
    private const COST_CATEGORIES = [
        'materials' => ['فواتير ومواد', true],
        'labor' => ['أجور اليد العاملة', true],
        'design' => ['تصميم ودراسات', true],
        'misc' => ['متفرقات', true],
        'project_overhead' => ['مصاريف عامة للمشروع', true],
        'loaded_payroll' => ['رواتب محمّلة', false],
        'loaded_overhead' => ['مصاريف عامة موزّعة', false],
        'asset_operation' => ['تشغيل أصول', true],
    ];

    private const ASSET_CATEGORIES = ['سيارات', 'أجهزة حاسوب', 'معدات وأدوات', 'أثاث ومفروشات', 'مولدات'];

    public function run(): void
    {
        $ids = [];
        foreach (self::ACCOUNTS as $code => [$name, $type, $parent, $role, $party, $project]) {
            $account = Account::query()->firstOrCreate(['code' => (string) $code], ['name' => $name, 'type' => $type,
                'parent_id' => $parent === null ? null : $ids[$parent], 'is_postable' => true, 'requires_party' => $party, 'requires_project' => $project,
                'is_system' => false, 'is_active' => true]);
            $ids[$code] = $account->id;
            if ($parent !== null) {
                Account::query()->whereKey($ids[$parent])->update(['is_postable' => false]);
            }
            if ($role !== null && ! SystemAccount::query()->where('role_key', $role)->exists()) {
                SystemAccount::query()->create(['role_key' => $role, 'account_id' => $account->id]);
            }
        }
        // The boxes' parent is a group: box accounts are created under it.
        Account::query()->whereKey($ids['1100'])->update(['is_postable' => false]);

        $sort = 0;
        foreach (self::EXPENSE_CATEGORIES as $name => $children) {
            if (is_string($children)) {
                ExpenseCategory::query()->firstOrCreate(['name' => $name, 'parent_id' => null],
                    ['account_id' => $ids[$children], 'is_overhead_allocatable' => true, 'is_active' => true]);

                continue;
            }
            $parent = ExpenseCategory::query()->firstOrCreate(['name' => $name, 'parent_id' => null], ['is_overhead_allocatable' => false, 'is_active' => true]);
            foreach ($children as $leaf => $code) {
                ExpenseCategory::query()->firstOrCreate(['name' => $leaf, 'parent_id' => $parent->id],
                    ['account_id' => $ids[$code], 'is_overhead_allocatable' => true, 'is_active' => true]);
            }
        }
        foreach (self::COST_CATEGORIES as $key => [$name, $billable]) {
            ProjectCostCategory::query()->firstOrCreate(['system_key' => $key], ['name' => $name, 'is_billable' => $billable, 'is_active' => true, 'sort' => ++$sort]);
        }
        foreach (self::ASSET_CATEGORIES as $name) {
            AssetCategory::query()->firstOrCreate(['name' => $name, 'parent_id' => null]);
        }
    }
}
