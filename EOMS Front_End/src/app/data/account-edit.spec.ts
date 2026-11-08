import { TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { Api } from '../core/api/api';
import { Auth } from '../core/api/auth';
import { ApiUser } from '../core/api/types';
import { Office } from './office';

async function setup(employeeId: number | null) {
  const admin: ApiUser = {id: 1, name: 'Admin', email: 'admin@example.com', phone: null, roles: ['system_admin'], status: 'active', employee_id: null, last_login_at: null, created_at: null};
  const target: ApiUser = {...admin, id: 2, name: 'Employee', roles: ['employee'], employee_id: employeeId};
  const user = signal(admin);
  const api = {
    all: vi.fn(async (url: string) => url === '/users' ? [admin, target] : []),
    get: vi.fn(async () => ({data: []})),
    put: vi.fn(async () => ({data: target})),
  };
  TestBed.configureTestingModule({providers: [
    {provide: Api, useValue: api}, {provide: Auth, useValue: {user, userId: computed(() => user().id)}},
  ]});
  const office = TestBed.inject(Office);
  await office.load();
  return {office, api};
}

describe('Linked employee account editing', () => {
  afterEach(() => TestBed.resetTestingModule());
  const entry = {name: 'Changed name', phone: '0999999999', email: 'login@example.com', password: 'NewPassword123', roles: ['employee'] as const};
  it('recognizes the employee link even when employee records are not loaded', async () => {
    const {office} = await setup(42);
    expect(office.employees()).toHaveLength(0);
    expect(office.accounts().find(account => account.userId === 2)?.employeeId).toBe('42');
  });
  it('updates login fields without submitting HR-owned name or phone', async () => {
    const {office, api} = await setup(42);
    expect((await office.updateAccount('42', {...entry, roles: [...entry.roles]}, 'u1')).ok).toBe(true);
    expect(api.put).toHaveBeenCalledWith('/users/2', {email: 'login@example.com', password: 'NewPassword123', roles: ['employee']});
  });
  it('still permits profile editing on a standalone account', async () => {
    const {office, api} = await setup(null);
    expect((await office.updateAccount('u2', {...entry, roles: [...entry.roles]}, 'u1')).ok).toBe(true);
    expect(api.put).toHaveBeenCalledWith('/users/2', expect.objectContaining({name: 'Changed name', phone: '0999999999'}));
  });
});
