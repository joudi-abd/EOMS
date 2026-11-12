import { TestBed } from '@angular/core/testing';
import { Api } from '../core/api/api';
import { PayRunSummary, Payroll, totalsList } from './payroll';

describe('Payroll store', () => {
  const get = vi.fn();
  const post = vi.fn();
  const del = vi.fn();
  const filePost = vi.fn();

  beforeEach(() => {
    get.mockReset();
    post.mockReset().mockResolvedValue({ data: {} });
    del.mockReset().mockResolvedValue({});
    filePost.mockReset().mockResolvedValue({ blob: new Blob(['x']), docId: '12345678' });
    TestBed.configureTestingModule({ providers: [{ provide: Api, useValue: { get, post, delete: del, blob: vi.fn(), filePost } }] });
  });

  const summary = (totals: PayRunSummary['totals']): PayRunSummary => ({
    id: 1, month: '2026-10', status: 'draft', prepared_by: 1, approved_by: null, return_note: null, version: 1,
    calculated_at: null, submitted_at: null, approved_at: null, employees: 0, not_ready: 0, blocking_issues: 0, paid: 0, payable: 0, totals,
  });

  it('lists totals per currency and accepts the empty array an empty run sends', () => {
    expect(totalsList(summary([]))).toEqual([]);
    expect(totalsList(summary({ USD: { earnings: 900, deductions: 37.5, net: 862.5 } }))).toEqual([{ currency: 'USD', earnings: 900, deductions: 37.5, net: 862.5 }]);
  });

  it('sends each workflow step to its own endpoint', async () => {
    const payroll = TestBed.inject(Payroll);
    await payroll.create('2026-10');
    await payroll.returnToDraft(4, 'أيام غير معتمدة');
    await payroll.reviewDeduction(9, 'modify', 20, 'تأخير بعذر');
    await payroll.removeItem(12);
    expect(post).toHaveBeenCalledWith('/payroll/runs', { month: '2026-10' });
    expect(post).toHaveBeenCalledWith('/payroll/runs/4/return', { note: 'أيام غير معتمدة' });
    expect(post).toHaveBeenCalledWith('/payroll/items/9/review', { decision: 'modify', amount: 20, reason: 'تأخير بعذر' });
    expect(del).toHaveBeenCalledWith('/payroll/items/12');
  });

  it('records a payment with the box and method', async () => {
    const payroll = TestBed.inject(Payroll);
    await payroll.pay(3, { currency: 'SYP', method: 'cash', box_id: 2, paid_at: '2026-11-09', reference: null, note: null });
    expect(post).toHaveBeenCalledWith('/payroll/lines/3/payment', expect.objectContaining({ box_id: 2, method: 'cash' }));
  });

  it('issues a Sham Cash file for one currency', async () => {
    const payroll = TestBed.inject(Payroll);
    const file = await payroll.shamCash(4, { currency: 'SYP', date: '2026-11-11', skip_missing: true });
    expect(filePost).toHaveBeenCalledWith('/payroll/runs/4/shamcash', { currency: 'SYP', date: '2026-11-11', skip_missing: true });
    expect(file.docId).toBe('12345678');
  });
});
