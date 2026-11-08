import { TestBed } from '@angular/core/testing';
import { Api } from '../core/api/api';
import { amount, cleanQuery, Finance } from './finance';

describe('Finance store', () => {
  const get = vi.fn();
  const post = vi.fn();
  const put = vi.fn();
  const patch = vi.fn();

  beforeEach(() => {
    get.mockReset();
    post.mockReset().mockResolvedValue({ data: {} });
    put.mockReset().mockResolvedValue({ data: {} });
    patch.mockReset().mockResolvedValue({ data: {} });
    TestBed.configureTestingModule({
      providers: [{ provide: Api, useValue: { get, post, put, patch, all: vi.fn().mockResolvedValue([]) } }],
    });
  });

  it('drops empty filters and sends true as 1', () => {
    expect(cleanQuery({ status: '', box_id: 0, kind: 'receipt', overdue: true, party_id: null, from: undefined })).toEqual({ kind: 'receipt', overdue: 1 });
  });

  it('reads decimal strings as numbers', () => {
    expect(amount('1250.50')).toBe(1250.5);
    expect(amount(null)).toBe(0);
    expect(amount('x')).toBe(0);
  });

  it('posts each cash operation to its own kind', async () => {
    const finance = TestBed.inject(Finance);
    await finance.createTransaction('exchange', { date: '2026-11-09', box_id: 1, amount: 100, description: 'USD to SYP', counter_box_id: 2, counter_amount: 1325000 });
    expect(post).toHaveBeenCalledWith('/finance/transactions/exchange', expect.objectContaining({ counter_box_id: 2, counter_amount: 1325000 }));
  });

  it('creates a new invoice with POST and saves a draft with PUT', async () => {
    const finance = TestBed.inject(Finance);
    const body = { party_id: 3, date: '2026-11-09', due_date: null, currency_code: 'SYP', notes: null, lines: [] };
    await finance.saveInvoice({ ...body, type: 'sale' });
    await finance.saveInvoice({ ...body, version: 2 }, 7);
    expect(post).toHaveBeenCalledWith('/finance/invoices', expect.objectContaining({ type: 'sale' }));
    expect(put).toHaveBeenCalledWith('/finance/invoices/7', expect.objectContaining({ version: 2 }));
  });

  it('labels accounts, boxes and parties by id', async () => {
    const finance = TestBed.inject(Finance);
    get.mockResolvedValueOnce({ data: [{ id: 4, code: '1101', name: 'الصندوق الرئيسي', type: 'asset', parent_id: null, system_key: null, active: true, is_group: false, box_id: 1 }] });
    await finance.loadAccounts();
    expect(finance.accountName(4)).toBe('1101 · الصندوق الرئيسي');
    expect(finance.accountName(null)).toBe('—');
    expect(finance.boxName(9)).toBe('#9');
  });
});
