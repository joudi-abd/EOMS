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

  it('ignores roles this interface does not know', () => {
    const session = signIn(['auditor']);
    expect(session.account().roles).toEqual([]);
    expect(session.modules().length).toBe(0);
  });
});
