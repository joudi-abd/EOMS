import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Api } from '../core/api/api';
import { Auth } from '../core/api/auth';
import { ApiJobChange } from '../core/api/types';
import { Office } from './office';
import { changeFrom, jobEventLabel, Records } from './records';

export const historyRow: ApiJobChange = {
  id: 9, employee_id: 1, event_type: 'contract_renewal_created', reason: 'Renewal agreed', effective_date: '2026-11-07',
  old_department_id: null, new_department_id: null, old_job_title_id: null, new_job_title_id: null, old_manager_id: null, new_manager_id: null,
  contract: { id: 2, number: 'CT-2', previous_id: 1, previous_number: 'CT-1', start_date: '2027-01-01', end_date: '2027-12-31', scheduled_for: null },
  recorded_by: { id: 7, name: 'HR Tester' }, recorded_at: '2026-11-07T09:00:00Z',
};

describe('Job history mapping', () => {
  it('retains renewal identity, previous contract, dates and actor', () => {
    const row = changeFrom(historyRow);
    expect(row.event).toBe('contract_renewal_created');
    expect(row.contract?.previous_number).toBe('CT-1');
    expect(row.recordedBy).toBe('HR Tester');
    expect(row.recordedAt).toBe('2026-11-07T09:00:00Z');
    expect(jobEventLabel[row.event]).toBe('historyContractRenewal');
  });

  it('keeps legacy assignment rows readable without the new optional fields', () => {
    const row = changeFrom({ ...historyRow, event_type: 'hired', contract: undefined, recorded_at: undefined, recorded_by: undefined });
    expect(row.contract).toBeNull();
    expect(row.recordedBy).toBe('');
    expect(jobEventLabel[row.event]).toBe('hiredEvent');
  });

  it('uses distinct labels for contract termination and employment termination', () => {
    expect(jobEventLabel.contract_terminated).not.toBe(jobEventLabel.employment_terminated);
    expect(jobEventLabel.contract_end_scheduled).not.toBe(jobEventLabel.contract_terminated);
  });
});

describe('Contract mutation refresh', () => {
  afterEach(() => TestBed.resetTestingModule());

  function setup() {
    const post = vi.fn().mockResolvedValue({ data: {} });
    const all = vi.fn(async (url: string) => url.endsWith('/job-history') ? [historyRow] : []);
    const done = vi.fn();
    TestBed.configureTestingModule({ providers: [
      { provide: Api, useValue: { post, all } },
      { provide: Auth, useValue: { userId: signal(null) } },
      { provide: Office, useValue: { done, fail: vi.fn(() => ({ ok: false, error: 'Failed' })) } },
    ] });
    const records = TestBed.inject(Records);
    TestBed.tick();
    return { records, post, all, done };
  }

  it('refreshes history together with contracts after activation', async () => {
    const { records, all } = setup();
    await records.activate('1', '2');
    expect(all).toHaveBeenCalledWith('/employees/1/contracts');
    expect(all).toHaveBeenCalledWith('/employees/1/job-history');
    expect(records.history()['1'][0].contract?.number).toBe('CT-2');
  });

  it('refreshes the timeline after creating a renewal draft', async () => {
    const { records, post, all } = setup();
    await records.createContract('1', { number: 'CT-2', type: 'fixed_term', start: '2027-01-01', end: '2027-12-31', salary: '', currency: '' }, '1');
    expect(post).toHaveBeenCalledWith('/employees/1/contracts/1/renew', expect.objectContaining({ contract_number: 'CT-2' }));
    expect(all).toHaveBeenCalledWith('/employees/1/job-history');
  });

  it('does not claim success or fabricate history when the mutation fails', async () => {
    const { records, post, all, done } = setup();
    post.mockRejectedValueOnce(new Error('Server rejected transition'));
    const result = await records.activate('1', '2');
    expect(result.ok).toBe(false);
    expect(all).not.toHaveBeenCalled();
    expect(done).not.toHaveBeenCalled();
  });
});
