import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { LeavesPage } from './leaves';
import { Leaves, LeaveOptions, LeaveQuote } from '../../data/leaves';
import { Language } from '../../core/language';

const settings: LeaveOptions = {
  types: [{id: 1, name: 'سنوية', available_units: 0, pay_percent: 100, cycle_start: '2026-01-01', cycle_end: '2026-12-31'}],
  today: '2026-11-08', can_submit: true, can_review: false, can_configure: false,
  half_days: {first_start: '09:00', first_end: '12:30', second_start: '12:30', second_end: '16:00'},
};
const preview: LeaveQuote = {days: [], total_units: 1000000, covered_units: 0, unpaid_units: 1000000, pay_percent: 100, quote_token: 'quote'};

describe('Leave request screen', () => {
  async function setup() {
    const api = {
      options: vi.fn().mockResolvedValue({data: settings}),
      list: vi.fn().mockResolvedValue({data: [], meta: {current_page: 1, last_page: 1}}),
      preview: vi.fn().mockResolvedValue({data: preview}),
      submit: vi.fn().mockResolvedValue({data: {id: 1}}),
    };
    TestBed.configureTestingModule({imports: [LeavesPage], providers: [provideRouter([]), {provide: Leaves, useValue: api}]});
    TestBed.inject(Language).set('ar');
    const fixture = TestBed.createComponent(LeavesPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    await fixture.whenStable();
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
    await fill('textarea[name="reason"]', 'Family leave');
    expect(button('معاينة الحساب').disabled).toBe(false);
    root.querySelector('form')!.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    await fixture.whenStable(); fixture.detectChanges();
    return {fixture, root, api, fill, button};
  }
  afterEach(() => TestBed.resetTestingModule());

  it('requires explicit unpaid consent before submitting and sends it to the server', async () => {
    const {fixture, root, api, button} = await setup();
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
    const {root, fill} = await setup();
    expect(root.textContent).toContain('تأكيد الطلب وحجز الرصيد');
    await fill('input[name="start_date"]', '2026-11-10');
    expect(root.textContent).not.toContain('تأكيد الطلب وحجز الرصيد');
  });

  it('does not expose the review list to an employee', async () => {
    const {root} = await setup();
    expect(root.textContent).not.toContain('مراجعة الطلبات');
    expect(root.textContent).toContain('طلباتي');
  });
});
