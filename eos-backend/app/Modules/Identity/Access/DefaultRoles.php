<?php

namespace App\Modules\Identity\Access;

/**
 * The roles the system ships with. `permissions:sync` creates them once; after that they are edited from the
 * roles screen like any other role, cannot be deleted, and can be put back to these definitions ("restore defaults").
 *
 * Kinds:
 * - admin: holds every permission at its widest scope, always; locked against editing.
 * - department: never assigned by hand. Whoever manages a department gets its grants, capped at department scope.
 * - standard: an ordinary role, assigned to accounts.
 */
final class DefaultRoles
{
    public const ADMIN = 'system_admin';

    public const DEPARTMENT_MANAGER = 'department_manager';

    public const EMPLOYEE = 'employee';

    /**
     * @return array<string, array{kind: string, ar: string, en: string, hint_ar: string, hint_en: string, grants: array<string, string>}>
     */
    public static function all(): array
    {
        $all = Scope::ALL;

        return [
            self::ADMIN => self::role('admin', 'مدير النظام', 'System administrator',
                'كل الصلاحيات دائماً، بما فيها الحسابات والأدوار.', 'Every permission, always, including accounts and roles.', []),

            'company_manager' => self::role('standard', 'مدير الشركة', 'Company manager',
                'يعرض كل الوحدات ويعتمد القرارات، ولا يعدّل السجلات.', 'Views every module and approves decisions; edits no records.', [
                    'employees.view' => $all, 'employees.documents.view' => $all,
                    'attendance.view' => $all, 'attendance.approve' => $all,
                    'leaves.view' => $all, 'leaves.approve_reviewers' => $all,
                    'payroll.view' => $all, 'payroll.approve' => $all,
                    'finance.view' => $all, 'finance.approve' => $all,
                    'tasks.view' => $all, 'tasks.manage' => $all,
                    'settings.view' => $all,
                ]),

            'hr_manager' => self::role('standard', 'مسؤول الموارد البشرية', 'HR manager',
                'الموظفون والعقود والمستندات والحضور والإجازات، ويُعدّ كشف الرواتب.', 'Employees, contracts, documents, attendance and leave; prepares payroll.', [
                    'employees.view' => $all, 'employees.manage' => $all, 'employees.view_sensitive' => $all,
                    'employees.documents.view' => $all, 'employees.documents.manage' => $all,
                    'attendance.view' => $all, 'attendance.manage' => $all, 'attendance.adjust' => $all, 'attendance.approve' => $all,
                    'leaves.view' => $all, 'leaves.manage' => $all, 'leaves.approve' => $all,
                    'payroll.view' => $all, 'payroll.manage' => $all,
                    'tasks.view' => Scope::OWN, 'tasks.manage' => Scope::OWN,
                ]),

            'finance_manager' => self::role('standard', 'المدير المالي', 'Finance manager',
                'المالية، ويراجع الرواتب ويعتمدها ويصرفها.', 'Finance; reviews, approves and pays payroll.', [
                    'payroll.view' => $all, 'payroll.approve' => $all, 'payroll.pay' => $all,
                    'finance.view' => $all, 'finance.manage' => $all,
                    'tasks.view' => Scope::OWN, 'tasks.manage' => Scope::OWN,
                ]),

            'project_manager' => self::role('standard', 'مدير المشاريع', 'Project manager',
                'يعرض الموظفين وينشئ لوحات مهام للفرق.', 'Views employees and opens team task boards.', [
                    'employees.view' => $all,
                    'tasks.view' => Scope::OWN, 'tasks.manage' => Scope::OWN,
                ]),

            'engineer' => self::role('standard', 'مهندس', 'Engineer',
                'مهامه واللوحات التي هو عضو فيها.', 'Their tasks and the boards they belong to.', [
                    'tasks.view' => Scope::OWN,
                ]),

            'purchasing_officer' => self::role('standard', 'مسؤول المشتريات', 'Purchasing officer',
                'مهامه؛ صلاحيات المشتريات تُضاف مع بناء قسمها.', 'Their tasks; purchasing permissions arrive with that module.', [
                    'tasks.view' => Scope::OWN,
                ]),

            self::DEPARTMENT_MANAGER => self::role('department', 'مدير قسم', 'Department manager',
                'لا يُسند يدوياً: كل من يدير قسماً يحصل على هذه الصلاحيات على قسمه فقط.', 'Never assigned by hand: whoever manages a department gets these, on their department only.', [
                    'attendance.view' => Scope::DEPARTMENT,
                    'leaves.view' => Scope::DEPARTMENT,
                    'tasks.view' => Scope::DEPARTMENT, 'tasks.manage' => Scope::DEPARTMENT,
                ]),

            self::EMPLOYEE => self::role('standard', 'موظف', 'Employee',
                'حضوره وإجازاته وطلباته ومهامه.', 'Their own attendance, leave, requests and tasks.', [
                    'tasks.view' => Scope::OWN,
                ]),
        ];
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    private static function role(string $kind, string $ar, string $en, string $hintAr, string $hintEn, array $grants): array
    {
        return compact('kind', 'ar', 'en', 'grants') + ['hint_ar' => $hintAr, 'hint_en' => $hintEn];
    }
}
