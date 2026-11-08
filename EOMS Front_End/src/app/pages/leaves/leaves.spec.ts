import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { LeavesPage } from './leaves';
import { Leaves, LeaveOptions, LeaveQuote, LeaveRecord } from '../../data/leaves';
import { Language } from '../../core/language';
import { Reporter } from '../../core/reports/reporter';

const settings: LeaveOptions = {
  types: [{id: 1, name: 'سنوية', available_units: 0, pay_percent: 100, cycle_start: '2026-01-01', cycle_end: '2026-12-31'}],
  today: '2026-11-08', can_submit: true, can_review: false, can_configure: false,
  half_days: {first_start: '09:00', first_end: '12:30', second_start: '12:30', second_end: '16:00'},
};
const preview: LeaveQuote = {days: [], total_units: 1000000, covered_units: 0, unpaid_units: 1000000, pay_percent: 100, quote_token: 'quote'};

describe('Leave request screen', () => {
  async function setup(options: LeaveOptions = settings) {
    const api = {
      options: vi.fn().mockResolvedValue({data: options}),
      list: vi.fn().mockResolvedValue({data: [], meta: {current_page: 1, last_page: 1}}),
      preview: vi.fn().mockResolvedValue({data: preview}),
      submit: vi.fn().mockResolvedValue({data: {id: 1}}),
      calendar: vi.fn().mockResolvedValue({data: {days: []}}),
    };
    TestBed.configureTestingModule({imports: [LeavesPage], providers: [provideRouter([]), {provide: Leaves, useValue: api}]});
    TestBed.inject(Language).set('ar');
    const fixture = TestBed.createComponent(LeavesPage);
    fixture.detectChanges();
    await fixture.whenStable();
    for (let i = 0; i < 8; i++) await Promise.resolve();
    fixture.detectChanges();
    await Promise.resolve();
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    const fill = async (selector: string, value: string) => {
      const control = root.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector)!;
      control.value = value;
      control.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      await fixture.whenStable();
      fixture.detectChanges();
    };
    const button = (text: string) => [...root.querySelectorAll('button')].find(b => b.textContent?.trim() === text)!;
    return {fixture, root, api, fill, button};
  }
  afterEach(() => TestBed.resetTestingModule());

  it('requires explicit unpaid consent before submitting and sends it to the server', async () => {
    const {fixture, root, api, fill, button} = await setup();
    await fill('textarea[name="reason"]', 'Family leave');
    expect(button('معاينة الحساب').disabled).toBe(false);
    root.querySelector('textarea[name="reason"]')!.closest('form')!
      .dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    await fixture.whenStable();
    for (let i = 0; i < 8; i++) await Promise.resolve();
    fixture.detectChanges();
    expect(button('تأكيد الطلب وحجز الرصيد').disabled).toBe(true);
    const consent = root.querySelector<HTMLInputElement>('input[type="checkbox"]')!;
    consent.click();
    await fixture.whenStable(); fixture.detectChanges();
    expect(button('تأكيد الطلب وحجز الرصيد').disabled).toBe(false);
    button('تأكيد الطلب وحجز الرصيد').click();
    await fixture.whenStable();
    expect(api.submit).toHaveBeenCalledOnce();
    expect(api.submit.mock.calls[0][2]).toBe(true);
  });

  it('invalidates the quote and removes confirmation when dates change', async () => {
    const {fixture, root, fill, button} = await setup();
    await fill('textarea[name="reason"]', 'Family leave');
    root.querySelector('textarea[name="reason"]')!.closest('form')!
      .dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    await fixture.whenStable();
    for (let i = 0; i < 8; i++) await Promise.resolve();
    fixture.detectChanges();
    expect(root.textContent).toContain('تأكيد الطلب وحجز الرصيد');
    await fill('input[name="start_date"]', '2026-11-10');
    expect(root.textContent).not.toContain('تأكيد الطلب وحجز الرصيد');
    expect(button('معاينة الحساب')).toBeTruthy();
  });

  it('does not expose the review list to an employee', async () => {
    const {root} = await setup();
    expect(root.textContent).not.toContain('مراجعة الطلبات');
    expect(root.textContent).toContain('طلباتي');
  });

  it('sends the same filters the server accepts for the list', async () => {
    const {fixture, root, api} = await setup();
    const page = fixture.componentInstance as unknown as {filters: {employee: string; department: string}};
    const select = (name: string, value: string) => {
      const field = root.querySelector<HTMLSelectElement>(`select[name="${name}"]`)!;
      field.value = value;
      field.dispatchEvent(new Event('change'));
    };
    select('status', 'pending');
    page.filters.employee = '4';
    select('filterType', '1');
    page.filters.department = '2';
    select('changePending', '1');
    const from = root.querySelector<HTMLInputElement>('input[name="filterFrom"]')!;
    const to = root.querySelector<HTMLInputElement>('input[name="filterTo"]')!;
    from.value = '2026-11-01';
    from.dispatchEvent(new Event('input'));
    to.value = '2026-11-08';
    to.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    root.querySelector('select[name="status"]')!.closest('form')!
      .dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    await fixture.whenStable();
    await Promise.resolve();
    const expected = {scope: 'mine', page: 1, status: 'pending', employee_id: 4, leave_type_id: 1, department_id: 2, from: '2026-11-01', to: '2026-11-08', change_pending: true};
    expect(api.list).toHaveBeenLastCalledWith(expected);
    expect(root.textContent).not.toContain('CSV');
  });

  it('submits for another employee and can approve in the same request', async () => {
    const {fixture, root, api, fill, button} = await setup({...settings, can_review: true, can_configure: true});
    const page = fixture.componentInstance as unknown as {pickEmployee(id: string): void};
    page.pickEmployee('4');
    await fixture.whenStable();
    for (let i = 0; i < 8; i++) await Promise.resolve();
    fixture.detectChanges();
    await fill('textarea[name="reason"]', 'Family leave');
    root.querySelector('textarea[name="reason"]')!.closest('form')!
      .dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    await fixture.whenStable();
    for (let i = 0; i < 8; i++) await Promise.resolve();
    fixture.detectChanges();
    expect(api.preview).toHaveBeenCalledWith(expect.objectContaining({reason: 'Family leave'}), undefined, 4);
    root.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click();
    root.querySelector<HTMLInputElement>('input[name="approveNow"]')!.click();
    fixture.detectChanges();
    button('تأكيد الطلب وحجز الرصيد').click();
    await fixture.whenStable();
    await Promise.resolve();
    expect(api.submit.mock.calls[0][2]).toBe(true);
    expect(api.submit.mock.calls[0][5]).toBe(4);
    expect(api.submit.mock.calls[0][6]).toBe(true);
  });

  it('exports every page of the current filter into the Excel workbook', async () => {
    const {fixture, root, api} = await setup();
    const row: LeaveRecord = {
      id: 9, version: 1, leave_type_id: 1, employee_name: 'سامر', status: 'pending',
      input: {leave_type_id: 1, start_date: '2026-11-10', end_date: '2026-11-10', unit: 'day', reason: 'سفر'},
      days: [{date: '2026-11-10', start_time: '09:00', end_time: '16:00', day_units: 1_000_000, covered_units: 1_000_000, unpaid_units: 0, pay_percent: 100, active: true}],
      policy: {pay_percent: 100}, is_owner: true, can_review: false, review_note: null, change_request: null,
    };
    api.list.mockResolvedValueOnce({data: [row], meta: {current_page: 1, last_page: 2}});
    api.list.mockResolvedValueOnce({data: [{...row, id: 10}], meta: {current_page: 2, last_page: 2}});
    const excel = vi.spyOn(TestBed.inject(Reporter), 'excel').mockResolvedValue();
    [...root.querySelectorAll('button')].find(item => item.textContent?.includes('تصدير Excel'))!.click();
    await fixture.whenStable();
    for (let i = 0; i < 8; i++) await Promise.resolve();
    expect(excel).toHaveBeenCalledOnce();
    const draft = excel.mock.calls[0][0];
    expect(draft.file).toBe('leaves');
    expect(draft.tables[0].rows.map(line => line[0])).toEqual([9, 10]);
    expect(draft.tables[0].rows[0]).toContain('سنوية');
    expect(draft.tables[0].rows[0]).toContain('1');
  });
});
