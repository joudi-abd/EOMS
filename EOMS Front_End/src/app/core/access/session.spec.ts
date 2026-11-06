import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Auth } from '../api/auth';
import { ApiUser } from '../api/types';
import { moduleIds } from '../copy';
import { Session } from './session';

function signIn(roles: string[]): Session {
  TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
  const user: ApiUser = { id: 1, name: 'Test User', email: 'test@example.com', phone: null, status: 'active', roles, last_login_at: null, created_at: null };
  TestBed.inject(Auth).user.set(user);
  return TestBed.inject(Session);
}

describe('Session', () => {
  it('gives the system administrator every module and action', () => {
    const session = signIn(['system_admin']);
    for (const id of moduleIds) {
      expect(session.can(id)).toBe(true);
    }
    expect(session.canAct('employee.add')).toBe(true);
    expect(session.canAct('account.add')).toBe(true);
  });

  it('keeps the company manager to viewing and approving', () => {
    const session = signIn(['company_manager']);
    expect(session.can('employees')).toBe(true);
    expect(session.can('access')).toBe(false);
    expect(session.canAct('leave.decide')).toBe(true);
    expect(session.canAct('employee.add')).toBe(false);
    expect(session.canAct('account.add')).toBe(false);
  });

  it('maps the HR manager role onto the HR areas', () => {
    const session = signIn(['hr_manager']);
    expect(session.can('employees')).toBe(true);
    expect(session.can('hrSettings')).toBe(true);
    expect(session.can('access')).toBe(false);
    expect(session.canAct('employee.add')).toBe(true);
    expect(session.roleLine()).toBe('مسؤول الموارد البشرية');
  });

  it('follows the backend seeder for the company manager', () => {
    const session = signIn(['company_manager']);
    expect(session.has('employees.documents.view')).toBe(true);
    expect(session.has('settings.view')).toBe(true);
    expect(session.has('employees.view_sensitive')).toBe(false);
    expect(session.has('employees.view_financial')).toBe(false);
    expect(session.canAct('payroll.approve')).toBe(true);
    expect(session.canAct('payroll.pay')).toBe(false);
    expect(session.canAct('purchase.decide')).toBe(true);
    expect(session.canAct('purchase.add')).toBe(false);
    expect(session.canAct('attendance.correct')).toBe(false);
    expect(session.canAct('hr.shift')).toBe(false);
    expect(session.canAct('document.view')).toBe(true);
  });

  it('keeps HR to what its backend role holds', () => {
    const session = signIn(['hr_manager']);
    expect(session.can('payroll')).toBe(false);
    expect(session.can('meetings')).toBe(false);
    expect(session.can('finance')).toBe(false);
    expect(session.can('documents')).toBe(true);
    expect(session.has('employees.view_financial')).toBe(false);
    expect(session.canAct('attendance.link')).toBe(true);
    expect(session.canAct('attendance.correct')).toBe(true);
    expect(session.canAct('hr.settings')).toBe(true);
    expect(session.canAct('hr.shift')).toBe(true);
    expect(session.canAct('payroll.prepare')).toBe(false);
  });

  it('lets finance prepare and pay payroll but not add purchases', () => {
    const session = signIn(['finance_manager']);
    expect(session.can('payroll')).toBe(true);
    expect(session.can('employees')).toBe(false);
    expect(session.canAct('payroll.prepare')).toBe(true);
    expect(session.canAct('payroll.pay')).toBe(true);
    expect(session.canAct('payroll.approve')).toBe(false);
    expect(session.canAct('purchase.decide')).toBe(true);
    expect(session.canAct('purchase.add')).toBe(false);
  });

  it('lets the project manager read employees without editing them', () => {
    const session = signIn(['project_manager']);
    expect(session.can('employees')).toBe(true);
    expect(session.can('projects')).toBe(true);
    expect(session.can('meetings')).toBe(true);
    expect(session.can('attendance')).toBe(false);
    expect(session.canAct('employee.add')).toBe(false);
    expect(session.canAct('document.view')).toBe(false);
  });

  it('lets the purchasing officer add purchases but not decide them', () => {
    const session = signIn(['purchasing_officer']);
    expect(session.can('procurement')).toBe(true);
    expect(session.can('assets')).toBe(true);
    expect(session.canAct('purchase.add')).toBe(true);
    expect(session.canAct('purchase.decide')).toBe(false);
  });

  it('gives the plain employee only the open areas', () => {
    const session = signIn(['employee']);
    expect(session.permissions().size).toBe(0);
    expect(session.can('alerts')).toBe(true);
    expect(session.can('employees')).toBe(false);
    expect(session.can('payroll')).toBe(false);
  });

  it('unions the permissions of two roles', () => {
    const session = signIn(['engineer', 'purchasing_officer']);
    expect(session.can('projects')).toBe(true);
    expect(session.can('procurement')).toBe(true);
  });

  it('ignores roles this interface does not know', () => {
    const session = signIn(['auditor']);
    expect(session.account().roles).toEqual([]);
    expect(session.modules().length).toBe(0);
  });
});
