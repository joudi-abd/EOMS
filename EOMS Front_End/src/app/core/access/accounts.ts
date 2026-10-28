import { CopyKey, ModuleId } from '../copy';
import { Text } from '../../data/text';

export const roleIds = ['company', 'system', 'hr', 'finance', 'project', 'engineer', 'procurement', 'employee'] as const;

export type Role = (typeof roleIds)[number];

export type Action =
  | 'leave.decide'
  | 'leave.request'
  | 'purchase.add'
  | 'purchase.decide'
  | 'employee.add'
  | 'employee.edit'
  | 'employee.evaluate'
  | 'employee.end'
  | 'attendance.link'
  | 'attendance.correct'
  | 'attendance.office'
  | 'attendance.mission'
  | 'hr.settings'
  | 'account.add'
  | 'account.toggle'
  | 'account.update'
  | 'payroll.prepare'
  | 'payroll.review'
  | 'payroll.approve'
  | 'payroll.pay';

/** Granted to any account that holds an open employee file, whatever its role. */
export const selfActions: readonly Action[] = ['leave.request'];
export const selfAreas: readonly ModuleId[] = ['attendance', 'leave', 'requests'];

/** `id` is the linked employee file's id when the account has one, so a person keeps one id across the app. */
export interface Account {
  id: string;
  userId: number;
  name: Text;
  roles: Role[];
  enabled: boolean;
  email: string;
  phone: string;
  lastLogin: string;
}

/** Backend role names (`usr_roles.name`) against the roles this interface knows. */
export const roleName: Record<Role, string> = {
  company: 'company_manager',
  system: 'system_admin',
  hr: 'hr_manager',
  finance: 'finance_manager',
  project: 'project_manager',
  engineer: 'engineer',
  procurement: 'purchasing_officer',
  employee: 'employee',
};

export function roleFrom(name: string): Role | null {
  return roleIds.find((role) => roleName[role] === name) ?? null;
}

/** The system administrator holds every backend permission; the company manager views and approves everything. */
export function isTop(roles: readonly Role[]): boolean {
  return roles.includes('system') || roles.includes('company');
}

export type AuditKind = 'added' | 'enabled' | 'disabled' | 'updated';

export interface AuditEntry {
  id: string;
  at: string;
  actorId: string;
  kind: AuditKind;
  targetName: Text;
}

export const roleLabel: Record<Role, CopyKey> = {
  company: 'companyRole',
  system: 'systemRole',
  hr: 'hrRole',
  finance: 'financeRole',
  project: 'projectRole',
  engineer: 'engineerRole',
  procurement: 'procurementRole',
  employee: 'employeeRole',
};

export const roleAreas: Record<Exclude<Role, 'company'>, readonly ModuleId[]> = {
  system: ['access', 'alerts'],
  hr: ['employees', 'attendance', 'leave', 'requests', 'payroll', 'hrSettings', 'meetings', 'alerts'],
  finance: ['finance', 'payroll', 'clients', 'procurement', 'documents', 'alerts'],
  project: ['projects', 'clients', 'documents', 'meetings', 'alerts'],
  engineer: ['projects', 'documents', 'meetings'],
  procurement: ['procurement', 'alerts'],
  employee: ['attendance', 'leave', 'requests', 'alerts'],
};

export const roleActions: Record<Exclude<Role, 'company'>, readonly Action[]> = {
  system: ['account.add', 'account.toggle', 'account.update'],
  hr: [
    'leave.decide',
    'employee.add',
    'employee.edit',
    'employee.evaluate',
    'employee.end',
    'attendance.link',
    'attendance.correct',
    'attendance.office',
    'attendance.mission',
    'hr.settings',
    'payroll.prepare',
  ],
  finance: ['purchase.add', 'purchase.decide', 'payroll.review', 'payroll.pay'],
  project: [],
  engineer: [],
  procurement: ['purchase.add'],
  employee: [],
};

/** The company manager's backend role lacks `employees.manage`, `settings.manage`, and `users.manage`. */
const companyWithheld: readonly Action[] = ['employee.add', 'employee.edit', 'hr.settings', 'account.add', 'account.toggle', 'account.update'];

export function grants(roles: readonly Role[], action: Action, hasFile: boolean): boolean {
  if (roles.includes('system')) {
    return true;
  }
  if (roles.includes('company')) {
    return !companyWithheld.includes(action);
  }
  if (hasFile && selfActions.includes(action)) {
    return true;
  }
  return roles.some((role) => role !== 'company' && roleActions[role].includes(action));
}
