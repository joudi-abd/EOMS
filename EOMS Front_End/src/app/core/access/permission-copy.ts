import { Text } from '../../data/text';
import { Permission, permissionIds, Role } from './accounts';

const say = (ar: string, en: string): Text => ({ ar, en });

/** The backend groups permissions by the word before the first dot. */
export const permissionModules: Record<string, Text> = {
  employees: say('الموظفون', 'Employees'),
  attendance: say('الحضور', 'Attendance'),
  leaves: say('الإجازات', 'Leave'),
  payroll: say('الرواتب', 'Payroll'),
  finance: say('المالية', 'Finance'),
  projects: say('المشاريع', 'Projects'),
  purchases: say('المشتريات', 'Purchases'),
  assets: say('الأصول', 'Assets'),
  documents: say('المستندات', 'Documents'),
  meetings: say('الاجتماعات', 'Meetings'),
  reports: say('التقارير', 'Reports'),
  audit: say('سجل التدقيق', 'Audit log'),
  settings: say('الإعدادات', 'Settings'),
  users: say('الحسابات', 'Accounts'),
  roles: say('الأدوار', 'Roles'),
};

export const permissionLabels: Record<Permission, Text> = {
  'employees.view': say('عرض الموظفين', 'View employees'),
  'employees.manage': say('إدارة الموظفين والعقود', 'Manage employees and contracts'),
  'employees.view_sensitive': say('عرض البيانات الشخصية', 'View personal data'),
  'employees.view_financial': say('عرض الراتب والمحفظة', 'View salary and wallet'),
  'employees.documents.view': say('عرض مستندات الموظفين', 'View employee documents'),
  'employees.documents.manage': say('إدارة مستندات الموظفين', 'Manage employee documents'),
  'attendance.view': say('عرض الحضور', 'View attendance'),
  'attendance.manage': say('إدارة الأجهزة والسياسات', 'Manage devices and policies'),
  'attendance.adjust': say('تصحيح الحضور', 'Correct attendance'),
  'attendance.approve': say('اعتماد الحضور', 'Approve attendance'),
  'leaves.view': say('عرض الإجازات', 'View leave'),
  'leaves.approve': say('البتّ في الإجازات', 'Decide leave'),
  'payroll.view': say('عرض الرواتب', 'View payroll'),
  'payroll.manage': say('إعداد الرواتب وصرفها', 'Prepare and pay payroll'),
  'payroll.approve': say('اعتماد الرواتب', 'Approve payroll'),
  'finance.view': say('عرض المالية', 'View finance'),
  'finance.manage': say('إدارة المالية', 'Manage finance'),
  'projects.view': say('عرض المشاريع', 'View projects'),
  'projects.manage': say('إدارة المشاريع', 'Manage projects'),
  'purchases.view': say('عرض المشتريات', 'View purchases'),
  'purchases.manage': say('إضافة المشتريات', 'Add purchases'),
  'purchases.approve': say('اعتماد المشتريات', 'Approve purchases'),
  'assets.view': say('عرض الأصول', 'View assets'),
  'assets.manage': say('إدارة الأصول', 'Manage assets'),
  'documents.view': say('عرض المستندات', 'View documents'),
  'documents.manage': say('إدارة المستندات', 'Manage documents'),
  'meetings.view': say('عرض الاجتماعات', 'View meetings'),
  'meetings.manage': say('إدارة الاجتماعات', 'Manage meetings'),
  'reports.view': say('عرض التقارير', 'View reports'),
  'audit.view': say('عرض سجل التدقيق', 'View audit log'),
  'settings.view': say('عرض الإعدادات', 'View settings'),
  'settings.manage': say('تعديل الإعدادات', 'Change settings'),
  'users.manage': say('إدارة الحسابات', 'Manage accounts'),
  'roles.assign': say('تعيين الأدوار', 'Assign roles'),
};

/** One line under each role in the account form. */
export const roleSummary: Record<Role, Text> = {
  system: say('كل الصلاحيات، وإدارة الحسابات والأدوار.', 'Every permission, plus accounts and roles.'),
  company: say('يعرض كل الوحدات ويعتمد القرارات، ولا يعدّل السجلات.', 'Views every module and approves decisions; edits no records.'),
  hr: say('الموظفون والعقود والمستندات والحضور والإجازات.', 'Employees, contracts, documents, attendance and leave.'),
  finance: say('الرواتب والمالية، ويعتمد المشتريات.', 'Payroll and finance; approves purchases.'),
  project: say('المشاريع والاجتماعات والمستندات، ويعرض الموظفين.', 'Projects, meetings and documents; views employees.'),
  engineer: say('يعرض المشاريع والاجتماعات والمستندات.', 'Views projects, meetings and documents.'),
  procurement: say('يضيف المشتريات ويعرض الأصول.', 'Adds purchases and views assets.'),
  employee: say('حضوره وإجازاته وطلباته فقط.', 'Their own attendance, leave and requests.'),
};

export interface PermissionGroup {
  module: string;
  label: Text;
  items: { id: Permission; label: Text }[];
}

/** Held permissions grouped by module, in the backend's own order. */
export function permissionGroups(held: ReadonlySet<Permission>): PermissionGroup[] {
  const groups: PermissionGroup[] = [];
  for (const id of permissionIds) {
    if (!held.has(id)) continue;
    const module = id.slice(0, id.indexOf('.'));
    let group = groups.find((item) => item.module === module);
    if (!group) {
      group = { module, label: permissionModules[module], items: [] };
      groups.push(group);
    }
    group.items.push({ id, label: permissionLabels[id] });
  }
  return groups;
}
