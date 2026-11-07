import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Api } from '../core/api/api';
import { Auth } from '../core/api/auth';
import { ApiEmployee, ApiUser } from '../core/api/types';
import { Office } from './office';
import { onRoll } from './attendance';
import { employeeFrom } from './employees';

const ended: ApiEmployee = { id: 7, employee_no: 'U-00000007', first_name: 'Ali', last_name: 'Test', full_name: 'Ali Test',
  department_id: null, job_title_id: null, manager_id: null, status: 'terminated', hire_date: '2026-01-01', termination_date: '2026-11-02', termination_reason: 'Original ending',
  user_id: 2, has_account: true, email: 'employee@example.test', current_service_start: null, service_periods: [{ id: 1, start_date: '2026-01-01', end_date: '2026-11-02' }] };

async function setup(role = 'hr_manager') {
  const user = signal<ApiUser>({ id: 1, name: 'HR', email: 'hr@example.test', phone: null, status: 'active', roles: [role], created_at: null, last_login_at: null });
  let returned = false;
  const post = vi.fn(async () => { returned = true; return { data: {} }; });
  const get = vi.fn(async (path: string) => ({ data: path === '/employees/7' ? { ...ended, status: 'active', termination_date: null, termination_reason: null, current_service_start: '2026-11-07', service_periods: [...ended.service_periods!, { id: 2, start_date: '2026-11-07', end_date: null }] } : [] }));
  const all = vi.fn(async (path: string) => path === '/employees' ? [{ ...ended, status: returned ? 'active' : 'terminated' }] : []);
  TestBed.configureTestingModule({ providers: [
    { provide: Api, useValue: { post, get, all } },
    { provide: Auth, useValue: { user, userId: () => 1 } },
  ] });
  const office = TestBed.inject(Office);
  TestBed.tick();
  await office.load();
  return { office, post, get, user };
}

describe('Employee return to service', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('posts explicit consent and refreshes the same employee without enabling login', async () => {
    const { office, post, get } = await setup();
    const result = await office.rehire('7', '2026-11-07', 'Approved return', office.self()!.id);
    expect(result.ok).toBe(true);
    expect(post).toHaveBeenCalledExactlyOnceWith('/employees/7/rehire', { effective_date: '2026-11-07', reason: 'Approved return', confirm_new_service_period: true });
    expect(get).toHaveBeenCalledWith('/employees/7');
    expect(office.person('7')?.status).toBe('active');
    expect(office.person('7')?.currentServiceStart).toBe('2026-11-07');
    expect(office.person('7')?.hired).toBe('2026-01-01');
    expect(office.person('7')?.servicePeriods).toHaveLength(2);
  });

  it('does not submit the return twice after the file is active', async () => {
    const { office, post } = await setup();
    await office.rehire('7', '2026-11-07', 'Approved return', office.self()!.id);
    const second = await office.rehire('7', '2026-11-07', 'Approved return', office.self()!.id);
    expect(second.ok).toBe(false);
    expect(post).toHaveBeenCalledTimes(1);
  });

  it('keeps the employee ended when the server rejects the operation', async () => {
    const { office, post } = await setup();
    post.mockRejectedValueOnce(new HttpErrorResponse({ status: 422, error: { message: 'Return date rejected' } }));
    const result = await office.rehire('7', '2026-11-02', 'Invalid date', office.self()!.id);
    expect(result.ok).toBe(false);
    expect(office.person('7')?.status).toBe('ended');
  });

  it('does not let a viewing role rehire an employee', async () => {
    const { office, post } = await setup('project_manager');
    expect((await office.rehire('7', '2026-11-07', 'Not permitted', office.self()!.id)).ok).toBe(false);
    expect(post).not.toHaveBeenCalled();
  });
  it('keeps the break outside the local roll while retaining both service periods', () => {
    const person = employeeFrom({ ...ended, status: 'active', termination_date: null, service_periods: [
      { id: 1, start_date: '2026-01-01', end_date: '2026-11-02' },
      { id: 2, start_date: '2026-11-07', end_date: null },
    ] });
    expect(onRoll(person, '2025-12-31')).toBe(false);
    expect(onRoll(person, '2026-11-02')).toBe(true);
    expect(onRoll(person, '2026-11-03')).toBe(false);
    expect(onRoll(person, '2026-11-06')).toBe(false);
    expect(onRoll(person, '2026-11-07')).toBe(true);
  });

});
