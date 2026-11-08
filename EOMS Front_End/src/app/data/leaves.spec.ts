import { TestBed } from '@angular/core/testing';
import { Api } from '../core/api/api';
import { Leaves, LeaveInput, LeaveQuote, LeaveRecord } from './leaves';

const input: LeaveInput = {leave_type_id: 1, start_date: '2026-11-09', end_date: '2026-11-09', unit: 'day', reason: 'Test'};
const quote: LeaveQuote = {days: [], total_units: 1000000, covered_units: 0, unpaid_units: 1000000, pay_percent: 100, quote_token: 'signed-preview'};

describe('Leave API integration', () => {
  const post = vi.fn();
  const put = vi.fn();
  const get = vi.fn();
  beforeEach(() => {
    post.mockReset().mockResolvedValue({data: {}});
    put.mockReset().mockResolvedValue({data: {}});
    get.mockReset().mockResolvedValue({data: []});
    TestBed.configureTestingModule({providers: [{provide: Api, useValue: {post, put, get}}]});
  });
  afterEach(() => TestBed.resetTestingModule());
  it('submits consent, server quote and stable retry key without employee impersonation', async () => {
    const service = TestBed.inject(Leaves);
    await service.submit(input, quote, true, 'same-key');
    await service.submit(input, quote, true, 'same-key');
    expect(post).toHaveBeenNthCalledWith(1, '/leaves', {...input, unpaid_consent: true, quote_token: 'signed-preview', submission_key: 'same-key'});
    expect(post.mock.calls[0]).toEqual(post.mock.calls[1]);
    expect(post.mock.calls[0][1]).not.toHaveProperty('employee_id');
  });
  it('uses amendment preview and version rather than creating a second request', async () => {
    const service = TestBed.inject(Leaves);
    await service.preview(input, 7);
    const row = {id: 7, version: 3} as LeaveRecord;
    await service.submit(input, quote, false, 'key', row);
    expect(post).toHaveBeenCalledWith('/leaves/7/preview', input);
    expect(put).toHaveBeenCalledWith('/leaves/7', expect.objectContaining({version: 3, unpaid_consent: false}));
  });
  it('sends version and cancellation boundary for reviewable shortening', async () => {
    await TestBed.inject(Leaves).action({id: 7, version: 4} as LeaveRecord, 'shorten', 'Returned early', '2026-11-10');
    expect(post).toHaveBeenCalledWith('/leaves/7/shorten', {version: 4, reason: 'Returned early', from_date: '2026-11-10'});
  });
  it('distinguishes personal and review lists and preserves pagination', async () => {
    await TestBed.inject(Leaves).list({scope: 'review', page: 2});
    expect(get).toHaveBeenCalledWith('/leaves', {scope: 'review', page: 2});
  });
  it('asks the calendar for the period', async () => {
    await TestBed.inject(Leaves).calendar('2026-11-01', '2026-11-14', 3, true);
    expect(get).toHaveBeenCalledWith('/leaves/calendar', {from: '2026-11-01', to: '2026-11-14', department_id: 3, include_pending: 1});
  });
});
