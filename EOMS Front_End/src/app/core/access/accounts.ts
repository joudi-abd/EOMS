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
  | 'employee.rehire'
  | 'document.view'
  | 'document.manage'
  | 'attendance.link'
  | 'attendance.correct'
  | 'attendance.office'
  | 'attendance.mission'
  | 'hr.settings'
  | 'hr.shift'
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
  employeeId?: string | null;
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

/** The system administrator and the company manager stand above the other roles. */
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

/**
 * The backend permissions (`usr_permissions.name`, Spatie, guard `web`), as `RolePermissionSeeder` creates them.
 * `/auth/me` returns role names only, so the interface rebuilds a user's permissions from the roles it holds.
 */
export const permissionIds = [
  'employees.view',
  'employees.manage',
  'employees.view_sensitive',
  'employees.view_financial',
  'employees.documents.view',
  'employees.documents.manage',
  'attendance.view',
  'attendance.manage',
  'attendance.adjust',
  'attendance.approve',
  'leaves.view',
  'leaves.approve',
  'payroll.view',
  'payroll.manage',
  'payroll.approve',
  'finance.view',
  'finance.manage',
  'projects.view',
  'projects.manage',
  'purchases.view',
  'purchases.manage',
  'purchases.approve',
  'assets.view',
  'assets.manage',
  'documents.view',
  'documents.manage',
  'meetings.view',
  'meetings.manage',
  'reports.view',
  'audit.view',
  'settings.view',
  'settings.manage',
  'users.manage',
  'roles.assign',
] as const;

export type Permission = (typeof permissionIds)[number];

/** Mirrors the seeder: the administrator holds all; the company manager every `.view` and `.approve`; the rest are listed. */
export const rolePermissions: Record<Role, readonly Permission[]> = {
  system: permissionIds,
  company: permissionIds.filter((name) => name.endsWith('.view') || name.endsWith('.approve')),
  hr: [
    'employees.view',
    'employees.manage',
    'employees.view_sensitive',
    'employees.documents.view',
    'employees.documents.manage',
    'attendance.view',
    'attendance.manage',
    'attendance.adjust',
    'attendance.approve',
    'leaves.view',
    'leaves.approve',
    'documents.view',
    'documents.manage',
    'reports.view',
  ],
  finance: ['payroll.view', 'payroll.manage', 'finance.view', 'finance.manage', 'purchases.view', 'purchases.approve', 'reports.view'],
  project: ['projects.view', 'projects.manage', 'meetings.view', 'meetings.manage', 'employees.view', 'documents.view', 'documents.manage', 'reports.view'],
  engineer: ['projects.view', 'meetings.view', 'documents.view'],
  procurement: ['purchases.view', 'purchases.manage', 'assets.view'],
  employee: [],
};

export function permissionsOf(roles: readonly Role[]): ReadonlySet<Permission> {
  return new Set(roles.flatMap((role) => rolePermissions[role]));
}

/** An area opens with any one of these permissions; `open` areas belong to every account that holds a role. */
export const areaNeeds: Record<ModuleId, readonly Permission[] | 'open'> = {
  access: ['users.manage'],
  employees: ['employees.view'],
  attendance: ['attendance.view'],
  leave: ['leaves.view'],
  requests: ['leaves.view'],
  payroll: ['payroll.view'],
  hrSettings: ['attendance.view'],
  projects: ['projects.view'],
  // The backend has no client permission yet; clients follow the projects and finance they belong to.
  clients: ['projects.view', 'finance.view'],
  finance: ['finance.view'],
  procurement: ['purchases.view'],
  documents: ['documents.view'],
  assets: ['assets.view'],
  meetings: ['meetings.view'],
  alerts: 'open',
};

/**
 * An action needs any one of these permissions. Actions the backend has no route for yet
 * (leave, payroll steps, evaluation, missions) follow the permission of the module they belong to.
 */
export const actionNeeds: Record<Action, readonly Permission[]> = {
  'leave.decide': ['leaves.approve'],
  'leave.request': [],
  'purchase.add': ['purchases.manage'],
  'purchase.decide': ['purchases.approve'],
  'employee.add': ['employees.manage'],
  'employee.edit': ['employees.manage'],
  'employee.evaluate': ['employees.manage'],
  'employee.end': ['employees.manage'],
  'employee.rehire': ['employees.manage'],
  'document.view': ['employees.documents.view'],
  'document.manage': ['employees.documents.manage'],
  'attendance.link': ['attendance.manage'],
  'attendance.correct': ['attendance.adjust'],
  'attendance.office': ['attendance.view'],
  'attendance.mission': ['attendance.adjust'],
  'hr.settings': ['employees.manage'],
  // New shift versions are posted to `/attendance/policies`.
  'hr.shift': ['attendance.manage'],
  'account.add': ['users.manage'],
  'account.toggle': ['users.manage'],
  'account.update': ['users.manage'],
  'payroll.prepare': ['payroll.manage'],
  'payroll.review': ['payroll.manage'],
  'payroll.approve': ['payroll.approve'],
  'payroll.pay': ['payroll.manage'],
};

export function holds(roles: readonly Role[], needs: readonly Permission[]): boolean {
  const held = permissionsOf(roles);
  return needs.some((name) => held.has(name));
}

export function grants(roles: readonly Role[], action: Action, hasFile: boolean): boolean {
  if (hasFile && selfActions.includes(action)) {
    return true;
  }
  return holds(roles, actionNeeds[action]);
}
