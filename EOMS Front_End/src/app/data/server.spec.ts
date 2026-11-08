import { TestBed } from '@angular/core/testing';
import { Api } from '../core/api/api';
import { Office } from './office';
import { Server } from './server';

describe('Documented endpoints outside the existing stores', () => {
  const get = vi.fn();
  const post = vi.fn();
  const put = vi.fn();
  const done = vi.fn();
  const fail = vi.fn();

  beforeEach(() => {
    get.mockReset();
    post.mockReset().mockResolvedValue({});
    put.mockReset().mockResolvedValue({});
    done.mockReset();
    fail.mockReset();
    TestBed.configureTestingModule({
      providers: [
        { provide: Api, useValue: { get, post, put } },
        { provide: Office, useValue: { done, fail } },
      ],
    });
  });

  afterEach(() => TestBed.resetTestingModule());

  it('keeps the unread count from the notification page', async () => {
    get.mockResolvedValueOnce({ data: [{ id: 'n1' }], meta: { last_page: 3 }, unread_count: 4 });
    const result = await TestBed.inject(Server).notifications(2);
    expect(get).toHaveBeenCalledWith('/notifications', { page: 2, per_page: 20 });
    expect(result.lastPage).toBe(3);
    expect(TestBed.inject(Server).unread()).toBe(4);
  });

  it('marks one alert and every alert read', async () => {
    const server = TestBed.inject(Server);
    await server.readNotice('abc');
    await server.readEveryNotice();
    expect(post).toHaveBeenCalledWith('/notifications/abc/read');
    expect(post).toHaveBeenCalledWith('/notifications/read-all');
    expect(server.unread()).toBe(0);
  });

  it('reads payroll facts for one employee and month', async () => {
    get.mockResolvedValueOnce({ data: { ready: false } });
    await TestBed.inject(Server).payrollMonth('7', '2026-11');
    expect(get).toHaveBeenCalledWith('/employees/7/payroll-inputs', { month: '2026-11' });
  });

  it('looks up and stores an exchange rate', async () => {
    get.mockResolvedValueOnce({ data: null });
    await TestBed.inject(Server).latestRate('USD', 'SYP');
    expect(get).toHaveBeenCalledWith('/settings/exchange-rates/latest', { from: 'USD', to: 'SYP' });
    post.mockResolvedValueOnce({ data: { rate: 15000, rate_date: '2026-11-08T00:00:00.000000Z' } });
    const saved = await TestBed.inject(Server).saveRate('USD', 'SYP', 15000, '2026-11-08');
    expect(saved).toEqual({ rate: 15000, rate_date: '2026-11-08T00:00:00.000000Z' });
    expect(post).toHaveBeenCalledWith('/settings/exchange-rates', { from: 'USD', to: 'SYP', rate: 15000, rate_date: '2026-11-08' });
    expect(done).toHaveBeenCalledWith('exchangeSaved');
  });

  it('reads and updates the office settings group', async () => {
    get.mockResolvedValueOnce({ data: { 'company.name': 'UFUQ' } });
    const result = await TestBed.inject(Server).companySettings();
    expect(get).toHaveBeenCalledWith('/settings/config/company');
    expect(result.data['company.name']).toBe('UFUQ');
    const saved = await TestBed.inject(Server).saveSetting('company.name', 'أفق');
    expect(saved).toBe(true);
    expect(put).toHaveBeenCalledWith('/settings/config/company.name', { value: 'أفق', group: 'company' });
  });

  it('reads the public company name and treats an empty list as no name', async () => {
    get.mockResolvedValueOnce({ data: { 'company.name': 'أفق' } });
    await expect(TestBed.inject(Server).publicCompany()).resolves.toBe('أفق');
    get.mockResolvedValueOnce({ data: [] });
    await expect(TestBed.inject(Server).publicCompany()).resolves.toBe('');
  });
});
