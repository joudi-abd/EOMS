<?php

namespace App\Modules\Identity\Access;

/**
 * Every permission the system enforces, in one place. This is the source of truth: `permissions:sync`
 * writes it to `usr_permissions`, the role editor shows it, and a test fails when a permission here is
 * not enforced anywhere or the code checks one that is not here.
 *
 * Scopes: a permission lists the reaches it may be granted with (see Scope). Most are company-wide only.
 * A module adds its permissions here when it is built; nothing is declared ahead of its code.
 */
final class PermissionCatalog
{
    private const ALL = [Scope::ALL];

    private const OWN_OR_ALL = [Scope::ALL, Scope::OWN];

    /**
     * @return array<string, array{ar: string, en: string, permissions: array<string, array{ar: string, en: string, hint_ar: string, hint_en: string, scopes: list<string>, sensitive?: bool}>}>
     */
    public static function modules(): array
    {
        return [
            'employees' => [
                'ar' => 'الموظفون', 'en' => 'Employees',
                'permissions' => [
                    'employees.view' => self::p('عرض الموظفين', 'View employees', 'دليل الموظفين وملفاتهم الأساسية.', 'The staff directory and basic files.'),
                    'employees.manage' => self::p('إدارة الموظفين والعقود', 'Manage employees and contracts', 'إضافة الموظفين وتعديلهم والعقود وإنهاء الخدمة وإعدادات الموارد البشرية.', 'Add and edit staff, contracts, terminations and HR settings.'),
                    'employees.view_sensitive' => self::p('عرض البيانات الشخصية', 'View personal data', 'الرقم الوطني والعنوان وبيانات التواصل الخاصة.', 'National ID, address and private contact details.', sensitive: true),
                    'employees.view_financial' => self::p('عرض الراتب والمحفظة', 'View salary and wallet', 'الراتب الأساسي ونوع الأجر في العقود.', 'Base salary and pay type on contracts.', sensitive: true),
                    'employees.documents.view' => self::p('عرض مستندات الموظفين', 'View employee documents', 'تنزيل المستندات الخاصة المرفوعة على ملف الموظف.', 'Download the private documents on an employee file.', sensitive: true),
                    'employees.documents.manage' => self::p('إدارة مستندات الموظفين', 'Manage employee documents', 'رفع مستندات الموظف وحذفها.', 'Upload and delete employee documents.'),
                ],
            ],
            'attendance' => [
                'ar' => 'الحضور', 'en' => 'Attendance',
                'permissions' => [
                    'attendance.view' => self::p('عرض الحضور', 'View attendance', 'أيام الحضور والتقارير. بنطاق القسم: موظفو الأقسام التي يديرها فقط.', 'Attendance days and reports. Department scope: only the departments the user manages.', [Scope::ALL, Scope::DEPARTMENT]),
                    'attendance.manage' => self::p('إدارة أجهزة البصمة والدوام', 'Manage devices and shifts', 'الأجهزة وربط الموظفين والبصمات وسياسات الدوام.', 'Devices, bindings, fingerprints and shift policies.'),
                    'attendance.adjust' => self::p('تصحيح الحضور', 'Correct attendance', 'طلب تصحيح أيام الآخرين (كل موظف يطلب تصحيح أيامه دون صلاحية).', 'Request corrections on other people\'s days (everyone may on their own).'),
                    'attendance.approve' => self::p('اعتماد الحضور', 'Approve attendance', 'اعتماد الأيام والبت في التصحيحات ومراجعة البصمات المعزولة.', 'Approve days, decide corrections, review quarantined punches.'),
                ],
            ],
            'leaves' => [
                'ar' => 'الإجازات', 'en' => 'Leave',
                'permissions' => [
                    'leaves.view' => self::p('عرض الإجازات', 'View leave', 'طلبات الإجازة وأسبابها. بنطاق القسم: موظفو أقسامه دون الأسباب.', 'Leave requests and reasons. Department scope: their departments, without reasons.', [Scope::ALL, Scope::DEPARTMENT]),
                    'leaves.manage' => self::p('إدارة الإجازات', 'Manage leave', 'الأنواع والسياسات والأرصدة، وتقديم إجازة نيابة عن موظف.', 'Types, policies and balances; file leave on someone\'s behalf.'),
                    'leaves.approve' => self::p('اعتماد إجازات الموظفين', 'Approve staff leave', 'البت في طلبات الموظفين الذين لا يعتمدون الإجازات.', 'Decide requests from staff who do not approve leave themselves.'),
                    'leaves.approve_reviewers' => self::p('اعتماد إجازات المعتمِدين', 'Approve approvers\' leave', 'البت في طلبات من يملكون اعتماد الإجازات. إجازات حامل هذه الصلاحية تُعتمد مباشرة.', 'Decide requests from people who approve leave. Holders\' own leave is approved directly.'),
                ],
            ],
            'payroll' => [
                'ar' => 'الرواتب', 'en' => 'Payroll',
                'permissions' => [
                    'payroll.view' => self::p('عرض الرواتب', 'View payroll', 'كشوف الرواتب ومدخلاتها.', 'Pay runs and their inputs.', sensitive: true),
                    'payroll.manage' => self::p('إعداد كشف الرواتب', 'Prepare payroll', 'إنشاء الكشف وتعديل البنود والخصومات.', 'Create the run, edit items and deductions.'),
                    'payroll.approve' => self::p('مراجعة الرواتب واعتمادها', 'Review and approve payroll', 'مراجعة البنود واعتماد الكشف.', 'Review items and approve the run.'),
                    'payroll.pay' => self::p('صرف الرواتب', 'Pay salaries', 'تسجيل الصرف وتصدير شام كاش.', 'Record payment and export Sham Cash.'),
                ],
            ],
            'finance' => [
                'ar' => 'المالية', 'en' => 'Finance',
                'permissions' => [
                    'finance.view' => self::p('عرض المالية', 'View finance', 'الصناديق والسندات والقيود والحسابات. الذات: الفروع المسندة إليه فقط.', 'Boxes, vouchers, entries and accounts. Own: only the branches assigned to the account.', self::OWN_OR_ALL, sensitive: true),
                    'finance.settings' => self::p('إعدادات المالية', 'Finance settings', 'العملات والفروع ودليل الحسابات والحسابات الإلزامية والصناديق والتصنيفات وإسناد الفروع للمستخدمين.', 'Currencies, branches, chart of accounts, system-account roles, boxes, categories and user↔branch assignments.'),
                    'finance.rates' => self::p('نشرة الأسعار', 'Rate bulletin', 'إدخال أسعار الصرف اليومية مقابل الدولار.', 'Enter the daily exchange rates against USD.', self::OWN_OR_ALL),
                    'finance.vouchers' => self::p('السندات', 'Vouchers', 'إنشاء السندات وتعديل مسوداتها واعتمادها ورفضها. الذات: في فروعه فقط.', 'Create vouchers, edit drafts, approve and reject them. Own: in the assigned branches only.', self::OWN_OR_ALL),
                    'finance.reverse' => self::p('عكس السندات', 'Reverse vouchers', 'عكس السندات المعتمدة بقيد معاكس. الذات: في فروعه فقط.', 'Reverse approved vouchers with a mirror entry. Own: in the assigned branches only.', self::OWN_OR_ALL),
                    'finance.projects' => self::p('مالية المشاريع', 'Project finance', 'إنشاء المشاريع وعقودها وموازناتها واتفاقيات المقاولين والمطالبات. الذات: مشاريع فروعه.', 'Create projects, contracts, budgets, contractor agreements and billing. Own: projects of the assigned branches.', self::OWN_OR_ALL),
                    'finance.projects.view' => self::p('عرض مالية المشاريع', 'View project finance', 'عرض المشاريع وأرقامها المالية. الذات: المشاريع التي يديرها.', 'View projects and their figures. Own: the projects the account manages.', self::OWN_OR_ALL, sensitive: true),
                    'finance.site_requests' => self::p('طلبات مصروف الموقع', 'Site expense requests', 'تقديم طلبات مصروف الموقع وعرض عهدته وطلباته. الذات: المشاريع التي يشرف عليها.', 'Submit site expense requests and see own custody and requests. Own: the projects the account supervises.', self::OWN_OR_ALL),
                    'finance.allocations' => self::p('التوزيع على المشاريع', 'Project allocations', 'نسب وقت الموظفين وتوزيع الرواتب والمصاريف العامة على المشاريع.', 'Employee time shares and allocation of payroll and overhead to projects.'),
                    'finance.counts' => self::p('جرد الصناديق', 'Cash counts', 'إدخال جرد الصناديق وإرساله. الذات: صناديق فروعه.', 'Enter and submit box counts. Own: boxes of the assigned branches.', self::OWN_OR_ALL),
                    'finance.counts.approve' => self::p('اعتماد فروقات الجرد', 'Approve count differences', 'اعتماد الجرد وترحيل فرقه أو رفضه. لا يعتمد من أجرى الجرد.', 'Approve a count and post its difference, or reject it. The counter never approves.'),
                    'finance.assets' => self::p('الأصول', 'Assets', 'سجل الأصول والشراء ونقل العهدة والصيانة والاستبعاد. الذات: أصول فروعه.', 'Asset register, purchase, custody transfer, maintenance and disposal. Own: assets of the assigned branches.', self::OWN_OR_ALL),
                    'finance.periods' => self::p('إقفال الفترات', 'Close periods', 'إقفال الأشهر المالية وإعادة فتحها بسبب.', 'Close fiscal months and reopen them with a reason.'),
                    'finance.reports' => self::p('التقارير المالية', 'Finance reports', 'التقارير ولوحة المدير. الذات: فروعه.', 'Reports and the dashboard. Own: the assigned branches.', self::OWN_OR_ALL, sensitive: true),
                    'finance.audit' => self::p('سجل التدقيق المالي', 'Finance audit log', 'سجل كل عمليات المالية والأسعار والإعدادات.', 'The trail of every finance, rate and settings change.', sensitive: true),
                ],
            ],
            'tasks' => [
                'ar' => 'المهام', 'en' => 'Tasks',
                'permissions' => [
                    'tasks.view' => self::p('المهام', 'Tasks', 'الذات: لوحته الشخصية واللوحات التي هو عضو فيها. القسم: يقرأ أيضاً لوحات موظفي أقسامه. الكل: يقرأ كل اللوحات غير الشخصية.', 'Own: their personal board and boards they belong to. Department: also reads their departments\' boards. All: reads every non-personal board.', [Scope::ALL, Scope::DEPARTMENT, Scope::OWN]),
                    'tasks.manage' => self::p('إنشاء لوحات المهام', 'Create task boards', 'الذات: لوحات فرق. القسم: ولوحات أقسامه. الكل: ولوحة لأي قسم.', 'Own: team boards. Department: also their departments\' boards. All: a board for any department.', [Scope::ALL, Scope::DEPARTMENT, Scope::OWN]),
                ],
            ],
            'settings' => [
                'ar' => 'الإعدادات', 'en' => 'Settings',
                'permissions' => [
                    'settings.view' => self::p('عرض الإعدادات', 'View settings', 'إعدادات النظام العامة.', 'General system settings.'),
                    'settings.manage' => self::p('تعديل الإعدادات', 'Change settings', 'تعديل إعدادات النظام العامة.', 'Change general system settings.'),
                ],
            ],
            'users' => [
                'ar' => 'الحسابات', 'en' => 'Accounts',
                'permissions' => [
                    'users.manage' => self::p('إدارة الحسابات', 'Manage accounts', 'إنشاء حسابات الدخول وتعديلها وتعطيلها.', 'Create, edit and disable sign-in accounts.'),
                ],
            ],
            'roles' => [
                'ar' => 'الأدوار', 'en' => 'Roles',
                'permissions' => [
                    'roles.manage' => self::p('إدارة الأدوار', 'Manage roles', 'إنشاء الأدوار وتعديل صلاحياتها وإسنادها للحسابات، في حدود صلاحياته هو.', 'Create roles, edit their permissions and assign them, within the holder\'s own permissions.', sensitive: true),
                ],
            ],
        ];
    }

    /** @return array<string, array{ar: string, en: string, hint_ar: string, hint_en: string, scopes: list<string>, sensitive?: bool, module: string}> */
    public static function permissions(): array
    {
        static $flat = null;
        if ($flat === null) {
            $flat = [];
            foreach (self::modules() as $module => $group) {
                foreach ($group['permissions'] as $name => $permission) {
                    $flat[$name] = $permission + ['module' => $module];
                }
            }
        }

        return $flat;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::permissions());
    }

    public static function has(string $name): bool
    {
        return isset(self::permissions()[$name]);
    }

    /** @return list<string> */
    public static function scopes(string $name): array
    {
        return self::permissions()[$name]['scopes'] ?? [];
    }

    /** The widest scope a permission may be granted with. */
    public static function widest(string $name): string
    {
        return array_reduce(self::scopes($name), fn (?string $carry, string $scope) => Scope::wider($carry, $scope), null) ?? Scope::ALL;
    }

    private static function p(string $ar, string $en, string $hintAr, string $hintEn, array $scopes = self::ALL, bool $sensitive = false): array
    {
        return ['ar' => $ar, 'en' => $en, 'hint_ar' => $hintAr, 'hint_en' => $hintEn, 'scopes' => $scopes, 'sensitive' => $sensitive];
    }
}
