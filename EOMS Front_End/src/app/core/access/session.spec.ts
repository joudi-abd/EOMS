import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Auth } from '../api/auth';
import { ApiUser } from '../api/types';
import { Office } from '../../data/office';
import { Session } from './session';

type Grants = NonNullable<ApiUser['permissions']>;

/** Signs in with the permissions the server reports on `/auth/me` — the interface never derives them from roles. */
function signIn(roles: string[], permissions: Grants = {}): Session {
  TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
  const user: ApiUser = {
    id: 1,
    name: 'Test User',
    email: 'test@example.com',
    phone: null,
    status: 'active',
    roles,
    permissions,
    last_login_at: null,
    created_at: null,
  };
  TestBed.inject(Auth).user.set(user);
  return TestBed.inject(Session);
}

describe('Session', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('reads permissions from the server, whatever the role is called', () => {
    const session = signIn(['site_supervisor'], { 'employees.view': 'all', 'tasks.view': 'own' });
    expect(session.can('employees')).toBe(true);
    expect(session.can('tasks')).toBe(true);
    expect(session.can('payroll')).toBe(false);
    expect(session.has('employees.view')).toBe(true);
  });

  it('treats a department reach as not company-wide', () => {
    const session = signIn(['employee'], { 'attendance.view': 'department', 'leaves.view': 'department', 'tasks.view': 'department' });
    expect(session.has('attendance.view')).toBe(false);
    expect(session.reach('attendance.view')).toBe('department');
    // Company-wide screens stay closed; tasks open at any reach.
    expect(session.can('hrSettings')).toBe(false);
    expect(session.can('tasks')).toBe(true);
  });

  it('opens actions from the permissions they need', () => {
    const session = signIn(['finance_manager'], { 'payroll.view': 'all', 'payroll.approve': 'all', 'payroll.pay': 'all' });
    expect(session.canAct('payroll.review')).toBe(true);
    expect(session.canAct('payroll.pay')).toBe(true);
    expect(session.canAct('payroll.prepare')).toBe(false);
    expect(session.can('finCash')).toBe(false);
  });

  it('opens the finance areas with company-wide finance.view', () => {
    const session = signIn(['finance_manager'], { 'finance.view': 'all', 'finance.manage': 'all' });
    expect(session.can('finCash')).toBe(true);
    expect(session.can('finSettings')).toBe(true);
    expect(session.can('employees')).toBe(false);
  });

  it('lets either leave-approval level decide leave', () => {
    expect(signIn(['company_manager'], { 'leaves.approve_reviewers': 'all' }).canAct('leave.decide')).toBe(true);
    TestBed.resetTestingModule();
    expect(signIn(['hr_manager'], { 'leaves.approve': 'all' }).canAct('leave.decide')).toBe(true);
  });

  it('opens the accounts area for account or role editors', () => {
    expect(signIn(['x'], { 'roles.manage': 'all' }).can('access')).toBe(true);
    TestBed.resetTestingModule();
    expect(signIn(['x'], { 'users.manage': 'all' }).can('access')).toBe(true);
  });

  it('keeps modules that are not built yet closed for everyone, the administrator included', () => {
    const session = signIn(['system_admin'], { 'users.manage': 'all', 'roles.manage': 'all', 'employees.view': 'all' });
    expect(session.isAdmin()).toBe(true);
    expect(session.can('projects')).toBe(false);
    expect(session.can('procurement')).toBe(false);
    expect(session.can('alerts')).toBe(true);
  });

  it('gives an account with no role nothing', () => {
    const session = signIn([], {});
    expect(session.modules().length).toBe(0);
  });

  it('lets an employee reach own attendance using auth employee identity without loading the directory', async () => {
    const session = signIn(['employee'], { 'tasks.view': 'own' });
    TestBed.inject(Auth).user.update((user) => ({
      ...user!,
      employee_id: 7,
      employee: {
        id: 7,
        employee_no: 'U-00000007',
        first_name: 'Test',
        last_name: 'Employee',
        full_name: 'Test Employee',
        department_id: null,
        job_title_id: null,
        manager_id: null,
        status: 'active',
        hire_date: '2026-01-01',
      },
    }));
    await TestBed.inject(Office).load();
    expect(session.account().id).toBe('7');
    expect(session.hasFile()).toBe(true);
    expect(session.can('attendance')).toBe(true);
    expect(session.can('employees')).toBe(false);
  });
});
