import { TestBed } from '@angular/core/testing';
import { Api } from '../../../core/api/api';
import { Language } from '../../../core/language';
import { Leaves } from '../../../data/leaves';
import { Office } from '../../../data/office';
import { SettingsLeaves } from './settings-leaves';

const settings = {
  types: [{ id: 3, name: 'سنوية', active: false }],
  policies: [] as {
    id: number;
    leave_type_id: number;
    effective_from: string;
    annual_days: string | null;
    pay_percent: number;
    grant_method: string | null;
    year_basis: string | null;
    year_start: string | null;
    first_year_method: string | null;
    retroactive_days: number;
  }[],
  holidays: [],
  today: '2026-11-08',
};

describe('Leave settings', () => {
  afterEach(() => TestBed.resetTestingModule());

  async function setup() {
    const api = {
      get: vi.fn((path: string) => {
        if (path === '/leave-settings') return Promise.resolve({ data: settings });
        if (path === '/leave-settings/pending-openings') return Promise.resolve({ data: [] });
        return Promise.reject(new Error(path));
      }),
      post: vi.fn().mockResolvedValue({ data: {} }),
      all: vi.fn().mockResolvedValue([]),
    };
    const leaves = {
      options: vi.fn().mockResolvedValue({
        data: { half_days: { first_start: '09:00:00', first_end: '12:30:00', second_start: '12:30:00', second_end: '16:00:00' } },
      }),
      saveHalves: vi.fn().mockResolvedValue({ data: {} }),
    };
    TestBed.configureTestingModule({
      imports: [SettingsLeaves],
      providers: [
        { provide: Api, useValue: api },
        { provide: Leaves, useValue: leaves },
        { provide: Office, useValue: { employees: () => [], done: vi.fn() } },
      ],
    });
    TestBed.inject(Language).set('ar');
    const fixture = TestBed.createComponent(SettingsLeaves);
    fixture.detectChanges();
    await Promise.all([
      ...api.get.mock.results.map((result) => result.value),
      ...leaves.options.mock.results.map((result) => result.value),
    ]);
    await Promise.resolve();
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    const open = async (label: string) => {
      const button = [...root.querySelectorAll('button')].find((item) => item.textContent?.includes(label));
      if (!button) throw new Error(root.textContent ?? '');
      button.click();
      fixture.detectChanges();
      await fixture.whenStable();
    };
    const fill = (name: string, value: string) => {
      const field = root.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${name}"]`)!;
      field.value = value;
      field.dispatchEvent(new Event('change'));
      field.dispatchEvent(new Event('input'));
    };
    return { fixture, root, api, leaves, open, fill };
  }

  it('publishes an untracked policy with the fields the server prohibits left empty', async () => {
    const { fixture, root, api, open, fill } = await setup();
    await open('نسخ سياسات الإجازات');
    fill('effective_from', '2026-11-08');
    fill('pay_percent', '80');
    fill('retroactive_days', '2');
    root.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click();
    fixture.detectChanges();
    root.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await fixture.whenStable();
    expect(api.post).toHaveBeenCalledWith('/leave-settings/types/3/policies', {
      effective_from: '2026-11-08',
      pay_percent: 80,
      retroactive_days: 2,
      annual_days: null,
      grant_method: null,
      year_basis: null,
      year_start: null,
      first_year_method: null,
    });
  });

  it('publishes a tracked monthly policy and keeps six-decimal days as text', async () => {
    const { fixture, root, api, open, fill } = await setup();
    await open('نسخ سياسات الإجازات');
    fill('effective_from', '2026-11-09');
    fill('pay_percent', '100');
    fill('retroactive_days', '0');
    fill('annual_days', '14.500000');
    fill('grant_method', 'monthly');
    fixture.detectChanges();
    fill('year_start', '01-01');
    fill('first_year_method', 'remaining');
    root.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await fixture.whenStable();
    expect(api.post).toHaveBeenCalledWith('/leave-settings/types/3/policies', {
      effective_from: '2026-11-09',
      pay_percent: 100,
      retroactive_days: 0,
      annual_days: '14.5',
      grant_method: 'monthly',
      year_basis: 'fixed',
      year_start: '01-01',
      first_year_method: 'remaining',
    });
  });

  it('keeps the first numeric calendar when a later version is published', async () => {
    settings.policies = [{
      id: 9, leave_type_id: 3, effective_from: '2026-11-01', annual_days: '21', pay_percent: 100,
      grant_method: 'upfront', year_basis: 'fixed', year_start: '03-15', first_year_method: null, retroactive_days: 0,
    }];
    try {
      const { fixture, root, api, open, fill } = await setup();
      await open('نسخ سياسات الإجازات');
      expect(root.textContent).toContain('03-15');
      expect(root.querySelector('[name="year_basis"]')?.tagName).toBe('INPUT');
      fill('effective_from', '2026-11-08');
      fill('pay_percent', '100');
      fill('retroactive_days', '0');
      fill('annual_days', '18');
      root.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      await fixture.whenStable();
      expect(api.post.mock.calls.at(-1)?.[1]).toMatchObject({ year_basis: 'fixed', year_start: '03-15', annual_days: '18' });
    } finally {
      settings.policies = [];
    }
  });

  it('sends half-day periods as hours and minutes', async () => {
    const { fixture, root, leaves, open, fill } = await setup();
    await open('فترتا نصف اليوم');
    expect(root.querySelector<HTMLInputElement>('[name="first_start"]')!.value).toBe('09:00');
    fill('second_end', '17:00');
    root.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await fixture.whenStable();
    expect(leaves.saveHalves).toHaveBeenCalledWith({
      first_start: '09:00', first_end: '12:30', second_start: '12:30', second_end: '17:00',
    });
  });
});
