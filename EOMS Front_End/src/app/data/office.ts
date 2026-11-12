import { computed, effect, inject, Injectable, signal, untracked, WritableSignal } from '@angular/core';
import { Account, Action, adminRole, AuditEntry, AuditKind, grants, holds, isTop, Permission, Role } from '../core/access/accounts';
import { Api, serverMessage, statusOf } from '../core/api/api';
import { Auth } from '../core/api/auth';
import { ApiDepartment, ApiEmployee, ApiItem, ApiJobTitle, ApiPolicy, ApiUser } from '../core/api/types';
import { CopyKey } from '../core/copy';
import { Language } from '../core/language';
import { Adjustment, buildDay, DayInput, DayReport, dayIn, isWorkday, officeToday, PinLink, Punch } from './attendance';
import { Department, departmentFrom, Employee, employeeFrom, plain } from './employees';
import {
  Custody,
  defaultLeaveTypes,
  defaultPolicy,
  Evaluation,
  FlowStage,
  FlowStatus,
  Holiday,
  HrRequest,
  isoDay,
  LeaveTypeDef,
  LeavingReason,
  Mission,
  monthBounds,
  NamedItem,
  Offboarding,
  PayMethod,
  PayRecord,
  parseDay,
  PayrollRun,
  RequestKind,
  Resolution,
  stamp,
  WorkPolicy,
} from './hr';
import { inclusiveUntil, Leave, validClock, validIsoDate } from './leave';
import { Text } from './text';

export type { Employee, EmployeeStatus } from './employees';
export type { Leave, LeaveKind } from './leave';
export type { Text };

export type Stage = 'design' | 'review' | 'execution' | 'handover';
export type Decision = 'pending' | 'approved' | 'rejected';

export interface Project {
  id: string;
  name: Text;
  stage: Stage;
  progress: number;
  due: string;
  late: boolean;
}

export interface Purchase {
  id: string;
  item: Text;
  supplier: Text;
  status: Decision;
}

/** The backend has no projects module yet, so field missions have no project to point at. */
export const projects: Project[] = [];

export type NoticeMessage = CopyKey;

/** `detail` carries the server's own wording and wins over `message`. Only local changes can be undone. */
export interface Notice {
  message: NoticeMessage | null;
  detail: string;
  undo: (() => void) | null;
  error: boolean;
}

export interface PolicyRules {
  graceMode: 'threshold_full' | 'excess_only' | 'completed_blocks';
  lateBlock: number;
  overtime: number;
  pairing: 'first_last' | 'directional';
  duplicateSeconds: number;
  finalizeAfter: number;
}

export interface Outcome {
  ok: boolean;
  id?: string;
  error?: string;
}

export type NamedList = 'departments' | 'titles' | 'leaveTypes';

/** Work kept in this browser tab because the backend has no endpoint for it yet. */
interface State {
  leaves: Leave[];
  purchases: Purchase[];
  extras: Record<string, Partial<Employee>>;
  punches: Punch[];
  links: PinLink[];
  adjustments: Adjustment[];
  audit: AuditEntry[];
  holidays: Holiday[];
  leaveTypes: LeaveTypeDef[];
  evaluations: Evaluation[];
  missions: Mission[];
  resolutions: Resolution[];
  requests: HrRequest[];
  custody: Custody[];
  offboardings: Offboarding[];
  runs: PayrollRun[];
  payments: PayRecord[];
}

export interface HireEntry {
  firstName: string;
  fatherName: string;
  lastName: string;
  nationalId: string;
  birthDate: string;
  phone: string;
  email: string;
  address: string;
  titleId: string;
  department: string;
  managerId: string;
  wallet: string;
  hired: string;
}

/** A change of department, title, or manager needs the date it takes effect and a reason; the server records it in the job history. */
export interface EmployeePatch {
  firstName: string;
  fatherName: string;
  lastName: string;
  birthDate: string;
  phone: string;
  email: string;
  address: string;
  titleId: string;
  department: string;
  managerId: string;
  wallet: string;
  effectiveDate: string;
  reason: string;
}

export interface AccountEntry {
  name: string;
  email: string;
  phone: string;
  password: string;
  roles: Role[];
}

function accountFrom(user: ApiUser, employeeId: string | undefined): Account {
  const linked = user.employee_id === undefined ? employeeId : user.employee_id === null ? undefined : String(user.employee_id);
  return {
    id: linked ?? `u${user.id}`,
    employeeId: linked ?? null,
    userId: user.id,
    name: plain(user.name),
    roles: [...user.roles],
    enabled: user.status === 'active',
    email: user.email,
    phone: user.phone ?? '',
    lastLogin: user.last_login_at ?? '',
  };
}

const idOrNull = (value: string): number | null => (value ? Number(value) : null);

const noticeLifetime = 5000;

@Injectable({ providedIn: 'root' })
export class Office {
  private readonly api = inject(Api);
  private readonly auth = inject(Auth);
  private readonly language = inject(Language);

  private readonly records = signal<Employee[]>([]);
  private readonly users = signal<ApiUser[]>([]);
  readonly departmentList = signal<Department[]>([]);
  readonly titles = signal<NamedItem[]>([]);
  readonly policy = signal<WorkPolicy>(defaultPolicy);
  /** Every shift version the server holds, newest first. */
  readonly policyVersions = signal<ApiPolicy[]>([]);
  readonly loading = signal(false);
  readonly loaded = signal(false);

  readonly leaves = signal<Leave[]>([]);
  readonly purchases = signal<Purchase[]>([]);
  private readonly extras = signal<Record<string, Partial<Employee>>>({});
  readonly punches = signal<Punch[]>([]);
  private readonly links = signal<PinLink[]>([]);
  readonly adjustments = signal<Adjustment[]>([]);
  readonly audit = signal<AuditEntry[]>([]);
  readonly holidays = signal<Holiday[]>([]);
  readonly leaveTypes = signal<LeaveTypeDef[]>(defaultLeaveTypes);
  readonly evaluations = signal<Evaluation[]>([]);
  readonly missions = signal<Mission[]>([]);
  readonly resolutions = signal<Resolution[]>([]);
  readonly requests = signal<HrRequest[]>([]);
  readonly custody = signal<Custody[]>([]);
  readonly offboardings = signal<Offboarding[]>([]);
  readonly runs = signal<PayrollRun[]>([]);
  readonly payments = signal<PayRecord[]>([]);
  readonly notice = signal<Notice | null>(null);

  private readonly stores: { [K in keyof State]: WritableSignal<State[K]> } = {
    leaves: this.leaves,
    purchases: this.purchases,
    extras: this.extras,
    punches: this.punches,
    links: this.links,
    adjustments: this.adjustments,
    audit: this.audit,
    holidays: this.holidays,
    leaveTypes: this.leaveTypes,
    evaluations: this.evaluations,
    missions: this.missions,
    resolutions: this.resolutions,
    requests: this.requests,
    custody: this.custody,
    offboardings: this.offboardings,
    runs: this.runs,
    payments: this.payments,
  };

  private readonly blank = this.snapshot();

  /** Server records, with the changes made in this tab to fields the backend does not hold yet. */
  readonly employees = computed<Employee[]>(() => {
    const extras = this.extras();
    return this.records().map((item) => (extras[item.id] ? { ...item, ...extras[item.id] } : item));
  });

  /** Every account the signed-in user may list, always including their own. */
  readonly accounts = computed<Account[]>(() => {
    const me = this.auth.user();
    const list = this.users();
    const all = me && !list.some((item) => item.id === me.id) ? [me, ...list] : list;
    const files = new Map(this.records().filter((item) => item.userId).map((item) => [item.userId, item.id]));
    if (me?.employee_id) files.set(String(me.id), String(me.employee_id));
    return all.map((user) => accountFrom(user, files.get(String(user.id))));
  });

  readonly self = computed<Account | null>(() => {
    const id = this.auth.userId();
    return id === null ? null : (this.accounts().find((item) => item.userId === id) ?? null);
  });

  readonly pins = computed<PinLink[]>(() => [
    ...this.records()
      .filter((item) => item.pin)
      .map((item) => ({ pin: item.pin, employeeId: item.id })),
    ...this.links(),
  ]);

  readonly headcount = computed(() => this.employees().filter((item) => item.status !== 'ended').length);

  readonly departments = computed(() =>
    this.departmentList().map((item) => ({
      id: item.id,
      name: item.name,
      count: this.employees().filter((person) => person.department === item.id && person.status !== 'ended').length,
    })),
  );

  private readonly dayInput = computed<DayInput>(() => ({
    employees: this.employees(),
    punches: this.punches(),
    pins: this.pins(),
    adjustments: this.adjustments(),
    leaves: this.leaves(),
    policy: this.policy(),
    holidays: this.holidays(),
    missions: this.missions(),
    resolutions: this.resolutions(),
  }));

  private readonly dayCache = computed(() => {
    this.dayInput();
    return new Map<string, DayReport>();
  });

  /** The latest workday, up to today, on which the device sent records; reports anchor on it. */
  readonly lastWorkday = computed(() => {
    const today = officeToday();
    const recorded = new Set(this.punches().map((item) => item.at.slice(0, 10)));
    const cursor = parseDay(today);
    let fallback = '';
    for (let step = 0; step < 31; step++) {
      const day = isoDay(cursor);
      if (isWorkday(day, this.policy(), this.holidays())) {
        fallback ||= day;
        if (recorded.has(day)) return day;
      }
      cursor.setDate(cursor.getDate() - 1);
    }
    return fallback || today;
  });

  readonly attendanceToday = computed(() => this.day(this.lastWorkday()));

  readonly pendingCount = computed(
    () =>
      this.leaves().filter((item) => item.status === 'pending_manager' || item.status === 'pending_hr').length +
      this.requests().filter((item) => item.status === 'pending_manager' || item.status === 'pending_hr').length +
      this.purchases().filter((item) => item.status === 'pending').length,
  );

  constructor() {
    effect(() => {
      const id = this.auth.userId();
      untracked(() => (id === null ? this.reset() : void this.load()));
    });
    effect((onCleanup) => {
      const current = this.notice();
      if (!current) return;
      const timer = setTimeout(() => {
        if (this.notice() === current) this.notice.set(null);
      }, noticeLifetime);
      onCleanup(() => clearTimeout(timer));
    });
  }

  // Server data

  async load(): Promise<void> {
    this.loading.set(true);
    const own = this.auth.user()?.employee;
    if (own) this.records.set([employeeFrom(own)]);
    // Ask only for what the account may read, so a forbidden list is never requested.
    const granted = this.auth.user()?.permissions ?? {};
    const may = (permission: Permission) => holds(granted, [permission]);
    await Promise.all([
      may('employees.view') ? this.loadDepartments() : undefined,
      may('employees.view') ? this.loadTitles() : undefined,
      may('employees.view') ? this.loadEmployees() : undefined,
      may('users.manage') ? this.loadUsers() : undefined,
      may('attendance.view') ? this.loadPolicy() : undefined,
    ]);
    this.loading.set(false);
    this.loaded.set(true);
  }

  async refreshEmployee(employeeId: string): Promise<void> {
    const result = await this.api.get<ApiItem<ApiEmployee>>(`/employees/${employeeId}`);
    this.records.update((rows) => rows.map((row) => row.id === employeeId ? employeeFrom(result.data) : row));
  }

  private async loadEmployees(): Promise<void> {
    const rows = await this.fetch(() => this.api.all<ApiEmployee>('/employees'));
    if (rows) this.records.set(rows.map(employeeFrom));
  }

  private async loadUsers(): Promise<void> {
    const rows = await this.fetch(() => this.api.all<ApiUser>('/users'));
    if (rows) this.users.set(rows);
  }

  private async loadDepartments(): Promise<void> {
    const rows = await this.fetch(() => this.api.all<ApiDepartment>('/departments'));
    if (rows) this.departmentList.set(rows.map(departmentFrom));
  }

  private async loadTitles(): Promise<void> {
    const rows = await this.fetch(() => this.api.all<ApiJobTitle>('/job-titles'));
    if (rows) this.titles.set(rows.map((item) => ({ id: String(item.id), name: plain(item.name) })));
  }

  /** The office zone is fixed by the first version, which the backend never replaces. */
  private officeZone(versions: ApiPolicy[]): string {
    return [...versions].sort((x, y) => x.effective_from.localeCompare(y.effective_from))[0]?.timezone ?? 'Asia/Damascus';
  }

  private async loadPolicy(): Promise<void> {
    const result = await this.fetch(() => this.api.get<ApiItem<ApiPolicy[]>>('/attendance/policies'));
    if (!result || !Array.isArray(result.data) || result.data.length === 0) return;
    const versions = [...result.data].sort((x, y) => y.effective_from.localeCompare(x.effective_from));
    const today = dayIn(this.officeZone(versions));
    const current = versions.find((item) => item.effective_from <= today) ?? versions[versions.length - 1];
    const clockOf = (value: string, fallback: string) => (validClock(value.slice(0, 5)) ? value.slice(0, 5) : fallback);
    this.policyVersions.set(versions);
    this.policy.set({
      start: clockOf(current.start_time, defaultPolicy.start),
      end: clockOf(current.end_time, defaultPolicy.end),
      grace: current.grace_minutes,
      workdays: [...current.working_days],
    });
  }

  /** A forbidden list stays empty quietly; any other failure is reported. Null means nothing arrived. */
  private async fetch<T>(run: () => Promise<T>): Promise<T | null> {
    try {
      return await run();
    } catch (error) {
      if (statusOf(error) !== 403) this.fail(error);
      return null;
    }
  }

  private reset(): void {
    this.records.set([]);
    this.users.set([]);
    this.departmentList.set([]);
    this.titles.set([]);
    this.policy.set(defaultPolicy);
    this.policyVersions.set([]);
    this.restore(this.blank);
    this.notice.set(null);
    this.loaded.set(false);
  }

  // Lookups

  day(day: string): DayReport {
    const cache = this.dayCache();
    let report = cache.get(day);
    if (!report) {
      report = buildDay(this.dayInput(), day);
      cache.set(day, report);
    }
    return report;
  }

  person(id: string): Employee | undefined {
    return this.employees().find((item) => item.id === id);
  }

  personName(id: string): Text {
    return this.person(id)?.name ?? this.accounts().find((item) => item.id === id)?.name ?? { ar: '—', en: '—' };
  }

  pinOf(employeeId: string): string {
    return this.pins().find((item) => item.employeeId === employeeId)?.pin ?? '';
  }

  team(managerId: string): Employee[] {
    return this.employees().filter((item) => item.managerId === managerId && item.status !== 'ended');
  }

  /** Only the signed-in account's permissions are known here (from `/auth/me`); other accounts act on their own screens. */
  actorCan(actorId: string, action: Action): boolean {
    const actor = this.accounts().find((item) => item.id === actorId && item.enabled);
    if (!actor || actor.userId !== this.auth.userId()) {
      return false;
    }
    const hasFile = this.employees().some((item) => item.id === actorId && item.status !== 'ended');
    return grants(this.auth.user()?.permissions ?? {}, action, hasFile);
  }

  /** The system administrator and the company manager stand above every other role. */
  isDirector(id: string): boolean {
    return isTop(this.accounts().find((item) => item.id === id && item.enabled)?.roles ?? []);
  }

  /** Nobody below the top roles acts on a top account or file. */
  mayManage(actorId: string, targetId: string): boolean {
    const target = this.accounts().find((item) => item.id === targetId);
    return !isTop(target?.roles ?? []) || this.isDirector(actorId);
  }

  /** People do not edit their own record, except the top roles. */
  mayEditFile(actorId: string, targetId: string, action: Action): boolean {
    return this.actorCan(actorId, action) && this.mayManage(actorId, targetId) && (actorId !== targetId || this.isDirector(actorId));
  }

  mayToggle(actorId: string, targetId: string): boolean {
    const target = this.accounts().find((item) => item.id === targetId);
    if (!target || targetId === actorId || !this.actorCan(actorId, 'account.toggle') || !this.mayManage(actorId, targetId)) {
      return false;
    }
    const next = this.accounts().map((item) => (item.id === targetId ? { ...item, enabled: !item.enabled } : item));
    return this.keepsAdmin(next);
  }

  mayEditAccount(actorId: string, targetId: string): boolean {
    return this.actorCan(actorId, 'account.update') && this.mayManage(actorId, targetId) && (targetId !== actorId || this.isDirector(actorId));
  }

  // Accounts

  async addAccount(entry: AccountEntry, actorId: string): Promise<Outcome> {
    if (!this.validRoles(entry.roles) || !this.actorCan(actorId, 'account.add') || (isTop(entry.roles) && !this.isDirector(actorId))) {
      return this.denied();
    }
    try {
      const result = await this.api.post<ApiItem<ApiUser>>('/users', {
        name: entry.name.trim(),
        email: entry.email.trim(),
        phone: entry.phone.trim() || null,
        password: entry.password,
        roles: entry.roles,
      });
      await this.loadUsers();
      this.record('added', actorId, plain(entry.name.trim()));
      this.done('addedAccount');
      return { ok: true, id: this.accounts().find((item) => item.userId === result.data.id)?.id ?? `u${result.data.id}` };
    } catch (error) {
      return this.fail(error);
    }
  }

  async setEnabled(id: string, enabled: boolean, actorId: string): Promise<Outcome> {
    const current = this.accounts().find((item) => item.id === id);
    if (!current || current.enabled === enabled || !this.mayToggle(actorId, id)) {
      return this.denied();
    }
    try {
      await this.api.patch(`/users/${current.userId}/status`, { status: enabled ? 'active' : 'disabled' });
      await this.loadUsers();
      this.record(enabled ? 'enabled' : 'disabled', actorId, current.name);
      this.done(enabled ? 'enabledAccount' : 'disabledAccount');
      return { ok: true, id };
    } catch (error) {
      return this.fail(error);
    }
  }

  async updateAccount(id: string, entry: AccountEntry, actorId: string): Promise<Outcome> {
    const current = this.accounts().find((item) => item.id === id);
    if (!current || !this.validRoles(entry.roles) || !this.mayEditAccount(actorId, id)) {
      return this.denied();
    }
    if (isTop(entry.roles) && !isTop(current.roles) && !this.isDirector(actorId)) {
      return this.denied();
    }
    const next = this.accounts().map((item) => (item.id === id ? { ...item, roles: entry.roles } : item));
    if (!this.keepsAdmin(next)) {
      return { ok: false, error: this.language.t('keepCompany') };
    }
    const body: Record<string, unknown> = { email: entry.email.trim() };
    // Roles travel only when they change: giving roles needs roles.manage, editing an account does not.
    if ([...entry.roles].sort().join() !== [...current.roles].sort().join()) body['roles'] = entry.roles;
    // Linked profile fields belong to the employee record, even when a full form is submitted.
    if (!current.employeeId) {
      body['name'] = entry.name.trim();
      body['phone'] = entry.phone.trim() || null;
    }
    if (entry.password) body['password'] = entry.password;
    try {
      await this.api.put(`/users/${current.userId}`, body);
      await this.loadUsers();
      this.record('updated', actorId, plain(entry.name.trim()));
      this.done('updatedAccount');
      return { ok: true, id };
    } catch (error) {
      return this.fail(error);
    }
  }

  // Device and attendance

  linkPin(pin: string, employeeId: string, actorId: string): void {
    if (!employeeId || !this.actorCan(actorId, 'attendance.link') || this.pins().some((item) => item.pin === pin)) {
      return;
    }
    this.commit('linkedPin', () => {
      this.links.update((list) => [...list, { pin, employeeId }]);
      return true;
    });
  }

  correctDay(entry: Omit<Adjustment, 'id'>): boolean {
    if (!this.mayEditFile(entry.by, entry.employeeId, 'attendance.correct')) {
      return false;
    }
    if (!validIsoDate(entry.day) || !validClock(entry.checkIn) || (entry.checkOut && !validClock(entry.checkOut)) || !entry.note.trim()) {
      return false;
    }
    if (entry.checkOut && entry.checkOut <= entry.checkIn) {
      return false;
    }
    return this.commit('correctedAttendance', () => {
      this.adjustments.update((list) => [
        { ...entry, id: `c-${crypto.randomUUID()}` },
        ...list.filter((item) => !(item.employeeId === entry.employeeId && item.day === entry.day)),
      ]);
      return true;
    });
  }

  addMission(entry: Omit<Mission, 'id'>): boolean {
    if (!validIsoDate(entry.day) || !entry.projectId || !this.mayRecordMission(entry.employeeId, entry.actorId)) {
      return false;
    }
    return this.commit('addedMission', () => {
      this.missions.update((list) => [
        { ...entry, id: `m-${crypto.randomUUID()}` },
        ...list.filter((item) => !(item.employeeId === entry.employeeId && item.day === entry.day)),
      ]);
      return true;
    });
  }

  mayRecordMission(employeeId: string, actorId: string): boolean {
    if (!this.mayManage(actorId, employeeId)) {
      return false;
    }
    if (employeeId === actorId) {
      return this.isDirector(actorId);
    }
    return this.actorCan(actorId, 'attendance.mission') || this.person(employeeId)?.managerId === actorId;
  }

  confirmAbsence(employeeId: string, day: string, note: string, actorId: string): boolean {
    if (!this.mayEditFile(actorId, employeeId, 'attendance.correct') || !note.trim()) {
      return false;
    }
    return this.commit('confirmedAbsence', () => {
      this.resolutions.update((list) => [
        { id: `r-${crypto.randomUUID()}`, employeeId, day, note: note.trim(), actorId },
        ...list.filter((item) => !(item.employeeId === employeeId && item.day === day)),
      ]);
      return true;
    });
  }

  reopenDay(employeeId: string, day: string, actorId: string): boolean {
    if (!this.mayEditFile(actorId, employeeId, 'attendance.correct') || !this.resolutions().some((item) => item.employeeId === employeeId && item.day === day)) {
      return false;
    }
    return this.commit('reopenedDay', () => {
      this.resolutions.update((list) => list.filter((item) => !(item.employeeId === employeeId && item.day === day)));
      return true;
    });
  }

  // Employees

  async hire(entry: HireEntry, actorId: string): Promise<Outcome> {
    if (!this.actorCan(actorId, 'employee.add')) {
      return this.denied();
    }
    try {
      const result = await this.api.post<ApiItem<ApiEmployee>>('/employees', {
        first_name: entry.firstName.trim(),
        father_name: entry.fatherName.trim(),
        last_name: entry.lastName.trim(),
        national_id: entry.nationalId.trim(),
        birth_date: entry.birthDate || null,
        email: entry.email.trim() || null,
        phone: entry.phone.trim() || null,
        address: entry.address.trim() || null,
        department_id: idOrNull(entry.department),
        job_title_id: idOrNull(entry.titleId),
        manager_id: idOrNull(entry.managerId),
        sham_cash_wallet: entry.wallet.trim() || null,
        hire_date: entry.hired,
      });
      await this.loadEmployees();
      this.record('added', actorId, plain(result.data.full_name));
      this.done('addedEmployee');
      return { ok: true, id: String(result.data.id) };
    } catch (error) {
      return this.fail(error);
    }
  }

  /** Sends only the fields that changed, so values the server withheld from this account are never overwritten. */
  async updateEmployee(id: string, patch: EmployeePatch, actorId: string): Promise<Outcome> {
    const current = this.person(id);
    if (!current || current.status === 'ended' || !this.mayEditFile(actorId, id, 'employee.edit') || patch.managerId === id) {
      return this.denied();
    }
    const body: Record<string, unknown> = {};
    const text = (key: string, next: string, previous: string, required = false) => {
      const value = next.trim();
      if (value !== previous) body[key] = value || (required ? previous : null);
    };
    const ref = (key: string, next: string, previous: string) => {
      if (next !== previous) body[key] = idOrNull(next);
    };
    text('first_name', patch.firstName, current.firstName, true);
    text('father_name', patch.fatherName, current.fatherName, true);
    text('last_name', patch.lastName, current.lastName, true);
    text('birth_date', patch.birthDate, current.birthDate);
    text('phone', patch.phone, current.phone);
    text('email', patch.email, current.email);
    text('address', patch.address, current.address);
    text('sham_cash_wallet', patch.wallet, current.wallet);
    ref('department_id', patch.department, current.department);
    ref('job_title_id', patch.titleId, current.titleId);
    ref('manager_id', patch.managerId, current.managerId);
    if (Object.keys(body).length === 0) {
      return { ok: false, error: this.language.t('noChanges') };
    }
    const reassigned = 'department_id' in body || 'job_title_id' in body || 'manager_id' in body;
    if (reassigned) {
      if (!validIsoDate(patch.effectiveDate) || !patch.reason.trim()) {
        return { ok: false, error: this.language.t('changeNeedsReason') };
      }
      body['effective_date'] = patch.effectiveDate;
      body['reason'] = patch.reason.trim();
    }
    try {
      await this.api.put(`/employees/${id}`, body);
      await this.loadEmployees();
      this.done('updatedEmployee');
      return { ok: true, id };
    } catch (error) {
      return this.fail(error);
    }
  }

  /** Opens a login account for an employee file; the server takes the name and phone from the file and gives the employee role. */
  async createLogin(employeeId: string, email: string, password: string, actorId: string): Promise<Outcome> {
    const person = this.person(employeeId);
    if (!person || person.status === 'ended' || person.hasAccount || !this.actorCan(actorId, 'account.add')) {
      return this.denied();
    }
    try {
      await this.api.post<ApiItem<ApiUser>>('/users', { employee_id: Number(employeeId), email: email.trim(), password });
      await Promise.all([this.loadEmployees(), this.loadUsers()]);
      this.record('added', actorId, person.name);
      this.done('addedAccount');
      return { ok: true, id: employeeId };
    } catch (error) {
      return this.fail(error);
    }
  }

  addEvaluation(id: string, note: string, actorId: string): boolean {
    const text = note.trim();
    const person = this.person(id);
    if (!text || !person || !this.mayEvaluate(actorId, id)) {
      return false;
    }
    return this.commit('addedEvaluation', () => {
      this.evaluations.update((list) => [{ id: `ev-${crypto.randomUUID()}`, employeeId: id, at: stamp(), actorId, note: text }, ...list]);
      return true;
    });
  }

  mayEvaluate(actorId: string, employeeId: string): boolean {
    if (actorId === employeeId || !this.mayManage(actorId, employeeId)) {
      return false;
    }
    return this.actorCan(actorId, 'employee.evaluate') || this.person(employeeId)?.managerId === actorId;
  }

  addCustody(employeeId: string, item: string, since: string, actorId: string, requestId = ''): boolean {
    const name = item.trim();
    if (!name || !validIsoDate(since) || !this.person(employeeId) || !this.mayEditFile(actorId, employeeId, 'employee.edit')) {
      return false;
    }
    return this.commit('addedCustody', () => {
      this.custody.update((list) => [
        { id: `cu-${crypto.randomUUID()}`, employeeId, item: name, since, returnedAt: '', returnedTo: '', requestId },
        ...list,
      ]);
      return true;
    });
  }

  returnCustody(custodyId: string, actorId: string): boolean {
    const current = this.custody().find((item) => item.id === custodyId);
    if (!current || current.returnedAt || !this.mayEditFile(actorId, current.employeeId, 'employee.end')) {
      return false;
    }
    return this.commit('returnedCustody', () => {
      this.custody.update((list) => list.map((item) => (item.id === custodyId ? { ...item, returnedAt: stamp(), returnedTo: actorId } : item)));
      return true;
    });
  }

  cancelOffboarding(employeeId: string, actorId: string): boolean {
    const process = this.offboardings().find((item) => item.employeeId === employeeId && item.status === 'open');
    if (!process || !this.mayEditFile(actorId, employeeId, 'employee.end')) {
      return false;
    }
    return this.commit('cancelledOffboarding', () => {
      this.offboardings.update((list) => list.filter((item) => item !== process));
      this.patchLocal(employeeId, { status: process.previousStatus });
      return true;
    });
  }

  async rehire(employeeId: string, date: string, reason: string, actorId: string): Promise<Outcome> {
    const person = this.person(employeeId);
    if (!person || person.status !== 'ended' || employeeId === actorId || !this.mayEditFile(actorId, employeeId, 'employee.rehire')) return this.denied();
    try {
      await this.api.post(`/employees/${employeeId}/rehire`, { effective_date: date, reason: reason.trim(), confirm_new_service_period: true });
      this.extras.update((all) => {
        const { [employeeId]: _dropped, ...rest } = all;
        return rest;
      });
      await this.refreshEmployee(employeeId);
      this.done('rehiredService');
      return { ok: true };
    } catch (error) {
      return this.fail(error);
    }
  }

  /** Ends the employment on the server, which refuses while a contract is active and disables the linked login. */
  async terminate(employeeId: string, date: string, reason: string, actorId: string): Promise<Outcome> {
    const person = this.person(employeeId);
    if (!person || person.status === 'ended' || employeeId === actorId || !this.mayEditFile(actorId, employeeId, 'employee.end')) {
      return this.denied();
    }
    try {
      await this.api.post(`/employees/${employeeId}/terminate`, { termination_date: date, reason: reason.trim() });
      const at = stamp();
      this.offboardings.update((list) =>
        list.map((item) => (item.employeeId === employeeId && item.status === 'open' ? { ...item, status: 'closed', closedBy: actorId, closedAt: at } : item)),
      );
      this.extras.update((all) => {
        const { [employeeId]: _dropped, ...rest } = all;
        return rest;
      });
      await Promise.all([this.loadEmployees(), this.loadUsers()]);
      this.done('endedService');
      return { ok: true, id: employeeId };
    } catch (error) {
      return this.fail(error);
    }
  }

  // Leave

  requestLeave(employeeId: string, typeId: string, from: string, days: number, reason = ''): string | null {
    const person = this.openEmployee(employeeId);
    const type = this.leaveTypes().find((item) => item.id === typeId && item.unit === 'day');
    if (!person || !type || !validIsoDate(from) || !Number.isInteger(days) || days < 1 || days > 60) {
      return null;
    }
    return this.fileLeave({ employeeId, employee: person.name, kind: 'leave', typeId, type: type.name, days, from, until: inclusiveUntil(from, days), start: '', end: '', reason: reason.trim() });
  }

  requestHours(employeeId: string, typeId: string, day: string, start: string, end: string, reason = ''): string | null {
    const person = this.openEmployee(employeeId);
    const type = this.leaveTypes().find((item) => item.id === typeId && item.unit === 'hour');
    if (!person || !type || !validIsoDate(day) || !validClock(start) || !validClock(end) || start >= end) {
      return null;
    }
    return this.fileLeave({ employeeId, employee: person.name, kind: 'permit', typeId, type: type.name, days: 0, from: day, until: day, start, end, reason: reason.trim() });
  }

  canAdvanceLeave(id: string, actorId: string): boolean {
    const row = this.leaves().find((item) => item.id === id);
    return Boolean(row && this.mayDecide(row.employeeId, row.status, actorId));
  }

  approveLeave(id: string, actorId: string): boolean {
    return this.decideFlow(this.leaves, id, actorId, true);
  }

  rejectLeave(id: string, actorId: string): boolean {
    return this.decideFlow(this.leaves, id, actorId, false);
  }

  usedLeave(employeeId: string, typeId: string, year: string): number {
    return this.leaves()
      .filter((item) => item.employeeId === employeeId && item.typeId === typeId && item.status === 'approved' && item.from.startsWith(year))
      .reduce((sum, item) => sum + (item.kind === 'permit' ? (Number(item.end.slice(0, 2)) * 60 + Number(item.end.slice(3)) - Number(item.start.slice(0, 2)) * 60 - Number(item.start.slice(3))) / 60 : item.days), 0);
  }

  // Requests

  fileRequest(entry: { employeeId: string; kind: RequestKind; day: string; checkIn: string; checkOut: string; item: string; details: string }): string | null {
    if (!this.openEmployee(entry.employeeId)) {
      return null;
    }
    if (entry.kind === 'correction' && (!validIsoDate(entry.day) || !validClock(entry.checkIn) || !validClock(entry.checkOut) || entry.checkOut <= entry.checkIn)) {
      return null;
    }
    if (entry.kind === 'equipment' && !entry.item.trim()) {
      return null;
    }
    if (entry.kind === 'resignation' && !validIsoDate(entry.day)) {
      return null;
    }
    if (entry.kind === 'resignation' && this.requests().some((item) => item.employeeId === entry.employeeId && item.kind === 'resignation' && (item.status === 'pending_manager' || item.status === 'pending_hr'))) {
      return null;
    }
    const id = `rq-${crypto.randomUUID()}`;
    const at = stamp();
    const director = this.isDirector(entry.employeeId);
    const row: HrRequest = {
      id,
      employeeId: entry.employeeId,
      kind: entry.kind,
      filedAt: at,
      day: entry.day,
      checkIn: entry.kind === 'correction' ? entry.checkIn : '',
      checkOut: entry.kind === 'correction' ? entry.checkOut : '',
      item: entry.kind === 'equipment' ? entry.item.trim() : '',
      details: entry.details.trim(),
      status: director ? 'approved' : this.firstStage(entry.employeeId),
      decisions: director ? [{ stage: 'self', actorId: entry.employeeId, decision: 'approved', at }] : [],
    };
    this.commit('filedRequest', () => {
      this.requests.update((list) => [row, ...list]);
      if (director) {
        this.applyRequest(row, entry.employeeId);
      }
      return true;
    });
    return id;
  }

  private applyRequest(row: HrRequest, actorId: string): void {
    const today = stamp().slice(0, 10);
    if (row.kind === 'correction') {
      this.adjustments.update((list) => [
        { id: `c-${crypto.randomUUID()}`, employeeId: row.employeeId, day: row.day, checkIn: row.checkIn, checkOut: row.checkOut, note: row.details || row.id, by: actorId },
        ...list.filter((item) => !(item.employeeId === row.employeeId && item.day === row.day)),
      ]);
    }
    if (row.kind === 'equipment') {
      this.custody.update((list) => [
        { id: `cu-${crypto.randomUUID()}`, employeeId: row.employeeId, item: row.item, since: today, returnedAt: '', returnedTo: '', requestId: row.id },
        ...list,
      ]);
    }
    if (row.kind === 'resignation') {
      const person = this.person(row.employeeId);
      if (person && (person.status === 'active' || person.status === 'away')) {
        this.beginLeaving(person, 'resignation', row.day, row.details, actorId, row.id);
      }
    }
  }

  canDecideRequest(id: string, actorId: string): boolean {
    const row = this.requests().find((item) => item.id === id);
    return Boolean(row && this.mayDecide(row.employeeId, row.status, actorId));
  }

  decideRequest(id: string, actorId: string, approve: boolean): boolean {
    return this.decideFlow(this.requests, id, actorId, approve, (row) => this.applyRequest(row, actorId));
  }

  // Payroll

  createRun(period: string, actorId: string): string | null {
    if (!/^\d{4}-\d{2}$/.test(period) || !this.actorCan(actorId, 'payroll.prepare') || this.runs().some((item) => item.period === period)) {
      return null;
    }
    const id = `run-${period}`;
    this.commit('createdRun', () => {
      this.runs.update((list) => [
        { id, period, status: 'draft', employeeIds: this.rollFor(period), createdBy: actorId, preparedBy: '', reviewedBy: '', approvedBy: '', returnNote: '', updatedAt: stamp() },
        ...list,
      ]);
      return true;
    });
    return id;
  }

  /** Separation of duties binds everyone except the top roles, who hold every stage. */
  runStep(run: PayrollRun, actorId: string): 'submit' | 'review' | 'approve' | null {
    const director = this.isDirector(actorId);
    if (run.status === 'draft' && this.actorCan(actorId, 'payroll.prepare')) {
      return 'submit';
    }
    if (run.status === 'review' && this.actorCan(actorId, 'payroll.review') && (director || actorId !== run.preparedBy)) {
      return 'review';
    }
    if (run.status === 'approval' && this.actorCan(actorId, 'payroll.approve') && (director || (actorId !== run.preparedBy && actorId !== run.reviewedBy))) {
      return 'approve';
    }
    return null;
  }

  advanceRun(runId: string, actorId: string): boolean {
    const run = this.runs().find((item) => item.id === runId);
    const step = run ? this.runStep(run, actorId) : null;
    if (!run || !step) {
      return false;
    }
    return this.commit(step === 'submit' ? 'submittedRun' : step === 'review' ? 'reviewedRun' : 'approvedRun', () => {
      const at = stamp();
      this.runs.update((list) =>
        list.map((item) => {
          if (item.id !== runId) return item;
          if (step === 'submit') return { ...item, status: 'review', preparedBy: actorId, employeeIds: this.rollFor(item.period), returnNote: '', updatedAt: at };
          if (step === 'review') return { ...item, status: 'approval', reviewedBy: actorId, updatedAt: at };
          return { ...item, status: 'approved', approvedBy: actorId, updatedAt: at };
        }),
      );
      return true;
    });
  }

  canReturnRun(run: PayrollRun, actorId: string): boolean {
    const step = this.runStep(run, actorId);
    return step === 'review' || step === 'approve';
  }

  returnRun(runId: string, actorId: string, note: string): boolean {
    const run = this.runs().find((item) => item.id === runId);
    if (!run || !this.canReturnRun(run, actorId) || !note.trim()) {
      return false;
    }
    return this.commit('returnedRun', () => {
      this.runs.update((list) =>
        list.map((item) => (item.id === runId ? { ...item, status: 'draft', preparedBy: '', reviewedBy: '', returnNote: note.trim(), updatedAt: stamp() } : item)),
      );
      return true;
    });
  }

  recordPayment(runId: string, employeeId: string, method: PayMethod, reference: string, paidOn: string, actorId: string): boolean {
    const run = this.runs().find((item) => item.id === runId);
    if (
      !run ||
      run.status !== 'approved' ||
      !run.employeeIds.includes(employeeId) ||
      !this.actorCan(actorId, 'payroll.pay') ||
      !reference.trim() ||
      !validIsoDate(paidOn) ||
      this.payments().some((item) => item.runId === runId && item.employeeId === employeeId)
    ) {
      return false;
    }
    return this.commit('payrollPaid', () => {
      this.payments.update((list) => [
        { id: `pay-${crypto.randomUUID()}`, runId, employeeId, method, reference: reference.trim(), paidOn, actorId },
        ...list,
      ]);
      return true;
    });
  }

  // HR settings

  /** The earliest day a new version may start: tomorrow, in the office zone. */
  nextPolicyDay(): string {
    const zone = this.officeZone(this.policyVersions());
    const [y, m, d] = dayIn(zone).split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + 1)).toISOString().slice(0, 10);
  }

  /** Shift hours are versioned: a save adds a version that rules from `effectiveFrom`, and earlier days keep theirs. */
  async setPolicy(policy: WorkPolicy, effectiveFrom: string, actorId: string, rules?: PolicyRules): Promise<Outcome> {
    const valid =
      validClock(policy.start) &&
      validClock(policy.end) &&
      policy.start < policy.end &&
      Number.isInteger(policy.grace) &&
      policy.grace >= 0 &&
      policy.grace <= 180 &&
      policy.workdays.length > 0;
    if (!valid) {
      return { ok: false, error: this.language.t('policyInvalid') };
    }
    if (!validIsoDate(effectiveFrom) || effectiveFrom < this.nextPolicyDay()) {
      return { ok: false, error: this.language.t('policyDateInvalid') };
    }
    if (!this.actorCan(actorId, 'hr.shift')) {
      return this.denied();
    }
    // Everything the form does not ask for carries over from the version in force.
    const base = this.policyVersions().find((item) => item.effective_from <= dayIn(this.officeZone(this.policyVersions())));
    if (!base) {
      return this.denied();
    }
    const chosen = rules ?? {
      graceMode: base.grace_mode as PolicyRules['graceMode'],
      lateBlock: base.late_block_minutes,
      overtime: Number(base.overtime_multiplier),
      pairing: base.pairing_mode as PolicyRules['pairing'],
      duplicateSeconds: base.duplicate_seconds,
      finalizeAfter: base.finalize_after_minutes,
    };
    const rulesOk =
      (chosen.graceMode === 'threshold_full' || chosen.graceMode === 'excess_only' || chosen.graceMode === 'completed_blocks') &&
      (chosen.pairing === 'first_last' || chosen.pairing === 'directional') &&
      Number.isInteger(chosen.lateBlock) &&
      chosen.lateBlock >= 1 &&
      chosen.lateBlock <= 180 &&
      chosen.overtime >= 1 &&
      chosen.overtime <= 10 &&
      Number.isInteger(chosen.duplicateSeconds) &&
      chosen.duplicateSeconds >= 0 &&
      chosen.duplicateSeconds <= 120 &&
      Number.isInteger(chosen.finalizeAfter) &&
      chosen.finalizeAfter >= 0 &&
      chosen.finalizeAfter <= 1440;
    if (!rulesOk) {
      return { ok: false, error: this.language.t('policyInvalid') };
    }
    try {
      await this.api.post('/attendance/policies', {
        effective_from: effectiveFrom,
        timezone: base.timezone,
        working_days: [...new Set(policy.workdays)].sort(),
        start_time: policy.start,
        end_time: policy.end,
        grace_minutes: policy.grace,
        grace_mode: chosen.graceMode,
        late_block_minutes: chosen.lateBlock,
        overtime_multiplier: chosen.overtime,
        pairing_mode: chosen.pairing,
        duplicate_seconds: chosen.duplicateSeconds,
        finalize_after_minutes: chosen.finalizeAfter,
      });
      await this.loadPolicy();
      this.done('savedPolicy');
      return { ok: true };
    } catch (error) {
      return this.fail(error);
    }
  }

  addHoliday(day: string, name: string, actorId: string): boolean {
    const title = name.trim();
    if (!validIsoDate(day) || !title || !this.actorCan(actorId, 'hr.settings') || this.holidays().some((item) => item.day === day)) {
      return false;
    }
    return this.commit('addedHoliday', () => {
      this.holidays.update((list) => [...list, { id: `hol-${crypto.randomUUID()}`, day, name: plain(title) }].sort((a, b) => a.day.localeCompare(b.day)));
      return true;
    });
  }

  removeHoliday(id: string, actorId: string): boolean {
    if (!this.actorCan(actorId, 'hr.settings') || !this.holidays().some((item) => item.id === id)) {
      return false;
    }
    return this.commit('removedHoliday', () => {
      this.holidays.update((list) => list.filter((item) => item.id !== id));
      return true;
    });
  }

  /** Departments and job titles are saved on the server; leave types stay in this tab. */
  async addNamed(list: NamedList, name: Text, actorId: string, extra: { unit?: 'day' | 'hour'; code?: string } = {}): Promise<Outcome> {
    const ar = name.ar.trim();
    const en = name.en.trim() || ar;
    if (!ar || !this.actorCan(actorId, 'hr.settings')) {
      return this.denied();
    }
    if (this.namedList(list).some((item) => item.name.ar === ar)) {
      return { ok: false, error: this.language.t('duplicateName') };
    }
    if (list === 'departments' || list === 'titles') {
      try {
        if (list === 'departments') {
          await this.api.post('/departments', { name: ar, code: (extra.code ?? '').trim() });
          await this.loadDepartments();
        } else {
          await this.api.post('/job-titles', { name: ar });
          await this.loadTitles();
        }
        this.done('addedSetting');
        return { ok: true };
      } catch (error) {
        return this.fail(error);
      }
    }
    const id = `${list}-${crypto.randomUUID()}`;
    const text = { ar, en };
    this.commit('addedSetting', () => {
      this.leaveTypes.update((items) => [...items, { id, name: text, unit: extra.unit === 'hour' ? 'hour' : 'day' }]);
      return true;
    });
    return { ok: true, id };
  }

  async renameNamed(list: NamedList, id: string, name: Text, actorId: string, code = ''): Promise<Outcome> {
    const ar = name.ar.trim();
    const en = name.en.trim() || ar;
    if (!ar || !this.actorCan(actorId, 'hr.settings') || !this.namedList(list).some((item) => item.id === id)) {
      return this.denied();
    }
    if (list === 'departments' || list === 'titles') {
      try {
        if (list === 'departments') {
          await this.api.put(`/departments/${id}`, code.trim() ? { name: ar, code: code.trim() } : { name: ar });
          await Promise.all([this.loadDepartments(), this.loadEmployees()]);
        } else {
          await this.api.put(`/job-titles/${id}`, { name: ar });
          await Promise.all([this.loadTitles(), this.loadEmployees()]);
        }
        this.done('renamedSetting');
        return { ok: true, id };
      } catch (error) {
        return this.fail(error);
      }
    }
    const text = { ar, en };
    this.commit('renamedSetting', () => {
      this.leaveTypes.update((items) => items.map((item) => (item.id === id ? { ...item, name: text } : item)));
      return true;
    });
    return { ok: true, id };
  }

  /** The server refuses to delete a department or title still in use. */
  async removeNamed(list: 'departments' | 'titles', id: string, actorId: string): Promise<Outcome> {
    if (!this.actorCan(actorId, 'hr.settings')) {
      return this.denied();
    }
    try {
      if (list === 'departments') {
        await this.api.delete(`/departments/${id}`);
        await this.loadDepartments();
      } else {
        await this.api.delete(`/job-titles/${id}`);
        await this.loadTitles();
      }
      this.done('removedSetting');
      return { ok: true };
    } catch (error) {
      return this.fail(error);
    }
  }

  namedList(list: NamedList): NamedItem[] {
    if (list === 'departments') return this.departmentList();
    if (list === 'titles') return this.titles();
    return this.leaveTypes();
  }

  // Purchases

  addPurchase(item: Text, supplier: Text, actorId: string): void {
    if (!this.actorCan(actorId, 'purchase.add') || !item.ar.trim() || !supplier.ar.trim()) {
      return;
    }
    this.commit('addedPurchase', () => {
      this.purchases.update((list) => [{ id: `p-${crypto.randomUUID()}`, item, supplier, status: 'pending' }, ...list]);
      return true;
    });
  }

  approvePurchase(id: string, actorId: string): void {
    this.settlePurchase(id, 'approved', 'approvedPurchase', actorId);
  }

  rejectPurchase(id: string, actorId: string): void {
    this.settlePurchase(id, 'rejected', 'rejectedLive', actorId);
  }

  // Internals

  private settlePurchase(id: string, status: Decision, message: CopyKey, actorId: string): void {
    if (!this.actorCan(actorId, 'purchase.decide') || !this.purchases().some((item) => item.id === id && item.status === 'pending')) {
      return;
    }
    this.commit(message, () => {
      this.purchases.update((list) => list.map((item) => (item.id === id ? { ...item, status } : item)));
      return true;
    });
  }

  private fileLeave(fields: Omit<Leave, 'id' | 'status' | 'decisions'>): string {
    const id = `lv-${crypto.randomUUID()}`;
    const director = this.isDirector(fields.employeeId);
    const row: Leave = {
      ...fields,
      id,
      status: director ? 'approved' : this.firstStage(fields.employeeId),
      decisions: director ? [{ stage: 'self', actorId: fields.employeeId, decision: 'approved', at: stamp() }] : [],
    };
    this.commit(fields.kind === 'permit' ? 'requestedPermit' : 'requestedLeave', () => {
      this.leaves.update((list) => [row, ...list]);
      return true;
    });
    return id;
  }

  /** A request goes to the direct manager first. With no reachable manager, human resources takes it. */
  private firstStage(employeeId: string): FlowStatus {
    return this.reachableManager(employeeId) ? 'pending_manager' : 'pending_hr';
  }

  /** A manager whose login account this user cannot see is taken as reachable. */
  private reachableManager(employeeId: string): string {
    const managerId = this.person(employeeId)?.managerId ?? '';
    const manager = managerId ? this.person(managerId) : undefined;
    const account = this.accounts().find((item) => item.id === managerId);
    return manager && manager.status !== 'ended' && (!account || account.enabled) ? managerId : '';
  }

  private mayDecide(employeeId: string, status: FlowStatus, actorId: string): boolean {
    if (employeeId === actorId) {
      return false;
    }
    if (status === 'pending_hr') {
      return this.actorCan(actorId, 'leave.decide');
    }
    if (status !== 'pending_manager') {
      return false;
    }
    const managerId = this.reachableManager(employeeId);
    if (!managerId) {
      return this.actorCan(actorId, 'leave.decide');
    }
    return managerId === actorId || this.isDirector(actorId);
  }

  private decideFlow<T extends { id: string; employeeId: string; status: FlowStatus; decisions: { stage: FlowStage; actorId: string; decision: 'approved' | 'rejected'; at: string }[] }>(
    source: WritableSignal<T[]>,
    id: string,
    actorId: string,
    approve: boolean,
    onApproved?: (row: T) => void,
  ): boolean {
    const row = source().find((item) => item.id === id);
    if (!row || !this.mayDecide(row.employeeId, row.status, actorId)) {
      return false;
    }
    const at = stamp();
    const stage: FlowStage = row.status === 'pending_manager' ? 'manager' : 'hr';
    const decisions = [...row.decisions, { stage, actorId, decision: approve ? ('approved' as const) : ('rejected' as const), at }];
    let status: FlowStatus = approve ? 'approved' : 'rejected';
    if (approve && stage === 'manager') {
      if (this.actorCan(actorId, 'leave.decide')) {
        decisions.push({ stage: 'hr', actorId, decision: 'approved', at });
      } else {
        status = 'pending_hr';
      }
    }
    const message: CopyKey = !approve ? 'rejectedLive' : status === 'pending_hr' ? 'passedToHr' : 'approvedLive';
    return this.commit(message, () => {
      const next = { ...row, status, decisions };
      source.update((list) => list.map((item) => (item.id === id ? next : item)));
      if (status === 'approved') {
        onApproved?.(next);
      }
      return true;
    });
  }

  private beginLeaving(person: Employee, reason: LeavingReason, lastDay: string, note: string, actorId: string, requestId: string): void {
    const previousStatus = person.status === 'away' ? 'away' : 'active';
    this.offboardings.update((list) => [
      ...list.filter((item) => !(item.employeeId === person.id && item.status === 'open')),
      { employeeId: person.id, previousStatus, reason, lastDay, note: note.trim(), startedBy: actorId, startedAt: stamp(), status: 'open', closedBy: '', closedAt: '', requestId },
    ]);
    this.patchLocal(person.id, { status: 'leaving' });
  }

  private patchLocal(id: string, patch: Partial<Employee>): void {
    this.extras.update((all) => ({ ...all, [id]: { ...all[id], ...patch } }));
  }

  private rollFor(period: string): string[] {
    const { first, last } = monthBounds(period);
    return this.employees()
      .filter((item) => item.hired <= last && (item.status !== 'ended' || item.endedOn >= first))
      .map((item) => item.id);
  }

  private openEmployee(id: string): Employee | undefined {
    return this.employees().find((item) => item.id === id && item.status !== 'ended');
  }

  done(message: NoticeMessage): void {
    this.notice.set({ message, detail: '', undo: null, error: false });
  }

  private denied(): Outcome {
    return { ok: false, error: this.language.t('notAllowed') };
  }

  fail(error: unknown): Outcome {
    const detail = serverMessage(error);
    const message: NoticeMessage = statusOf(error) === 0 ? 'serverDown' : 'requestFailed';
    this.notice.set({ message, detail, undo: null, error: true });
    return { ok: false, error: detail || this.language.t(message) };
  }

  private commit(message: NoticeMessage, change: () => boolean): boolean {
    const before = this.snapshot();
    if (!change()) {
      this.restore(before);
      return false;
    }
    this.notice.set({
      message,
      detail: '',
      error: false,
      undo: () => {
        this.restore(before);
        this.notice.set(null);
      },
    });
    return true;
  }

  private snapshot(): State {
    const state = {} as Record<keyof State, unknown>;
    for (const key of Object.keys(this.stores) as (keyof State)[]) {
      state[key] = this.stores[key]();
    }
    return state as State;
  }

  private restore(state: State): void {
    for (const key of Object.keys(this.stores) as (keyof State)[]) {
      (this.stores[key] as WritableSignal<unknown>).set(state[key]);
    }
  }

  private validRoles(roles: Role[]): boolean {
    return roles.length > 0 && new Set(roles).size === roles.length;
  }

  /** The backend refuses to lose its last active system administrator. */
  private keepsAdmin(list: Account[]): boolean {
    return list.some((item) => item.enabled && item.roles.includes(adminRole));
  }

  private record(kind: AuditKind, actorId: string, targetName: Text): void {
    this.audit.update((list) => [{ id: `audit-${crypto.randomUUID()}`, at: stamp(), actorId, kind, targetName }, ...list]);
  }
}
