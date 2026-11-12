import { ModuleId } from '../copy';
import { Text } from '../../data/text';

/**
 * A backend role name (`usr_roles.name`). Roles are records edited on the roles screen; their labels and grants
 * come from `/access/roles` (see data/roles.ts). The interface never decides what a role grants.
 */
export type Role = string;

/** The shipped administrator role: holds every permission and is the only role that hands itself out. */
export const adminRole = 'system_admin';

/** The shipped company-manager role. With the administrator, it stands above the others in the account screens. */
export const companyRole = 'company_manager';

/** How far a granted permission reaches. */
export type Scope = 'own' | 'department' | 'all';

export const scopeOrder: readonly Scope[] = ['own', 'department', 'all'];

/** Effective permissions of the signed-in account, as `/auth/me` reports them: permission → widest scope. */
export type Grants = Readonly<Partial<Record<string, Scope>>>;

export function covers(held: Scope | undefined, needed: Scope): boolean {
  return held !== undefined && scopeOrder.indexOf(held) >= scopeOrder.indexOf(needed);
}

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

/** The administrator and the company manager stand above the other roles in the account screens. */
export function isTop(roles: readonly Role[]): boolean {
  return roles.includes(adminRole) || roles.includes(companyRole);
}

export type AuditKind = 'added' | 'enabled' | 'disabled' | 'updated';

export interface AuditEntry {
  id: string;
  at: string;
  actorId: string;
  kind: AuditKind;
  targetName: Text;
}

/**
 * The permission names the interface checks, from the backend catalogue (`PermissionCatalog`). Typed so a check
 * cannot name a permission that does not exist; labels and scopes come from `/access/catalog`.
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
  'leaves.manage',
  'leaves.approve',
  'leaves.approve_reviewers',
  'payroll.view',
  'payroll.manage',
  'payroll.approve',
  'payroll.pay',
  'finance.view',
  'finance.manage',
  'finance.approve',
  'tasks.view',
  'tasks.manage',
  'settings.view',
  'settings.manage',
  'users.manage',
  'roles.manage',
] as const;

export type Permission = (typeof permissionIds)[number];

/** An area opens with any one of these permissions; `open` areas belong to every account that holds a role. */
export const areaNeeds: Record<ModuleId, readonly Permission[] | 'open'> = {
  access: ['users.manage', 'roles.manage'],
  employees: ['employees.view'],
  attendance: ['attendance.view'],
  leave: ['leaves.view'],
  requests: ['leaves.view'],
  payroll: ['payroll.view'],
  hrSettings: ['attendance.view'],
  // Modules not built yet open for nobody; they get their permissions when they are built.
  projects: [],
  clients: [],
  finCash: ['finance.view'],
  finInvoices: ['finance.view'],
  finItems: ['finance.view'],
  finLedger: ['finance.view'],
  finReports: ['finance.view'],
  finSettings: ['finance.view'],
  procurement: [],
  documents: [],
  assets: [],
  meetings: [],
  alerts: 'open',
  tasks: ['tasks.view'],
};

/** Areas that open with a narrower reach than company-wide (an area not listed needs company-wide). */
export const areaReach: Partial<Record<ModuleId, Scope>> = {
  tasks: 'own',
};

/**
 * An action needs any one of these permissions. Actions the backend has no route for yet
 * (leave, payroll steps, evaluation, missions) follow the permission of the module they belong to.
 */
export const actionNeeds: Record<Action, readonly Permission[]> = {
  'leave.decide': ['leaves.approve', 'leaves.approve_reviewers'],
  'leave.request': [],
  'purchase.add': [],
  'purchase.decide': [],
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
  'payroll.review': ['payroll.approve'],
  'payroll.approve': ['payroll.approve'],
  'payroll.pay': ['payroll.pay'],
};

/** Any one of `needs`, held at `scope` or wider. Company-wide by default: department and own reach are opted into. */
export function holds(granted: Grants, needs: readonly Permission[], scope: Scope = 'all'): boolean {
  return needs.some((name) => covers(granted[name], scope));
}

export function grants(granted: Grants, action: Action, hasFile: boolean): boolean {
  if (hasFile && selfActions.includes(action)) {
    return true;
  }
  return holds(granted, actionNeeds[action]);
}
