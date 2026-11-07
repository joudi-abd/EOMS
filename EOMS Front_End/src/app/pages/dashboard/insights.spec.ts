import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Session } from '../../core/access/session';
import { Api } from '../../core/api/api';
import { ApiAttendanceDay } from '../../core/api/types';
import { Language } from '../../core/language';
import { defaultPolicy } from '../../data/hr';
import { Office } from '../../data/office';
import { Insights } from './insights';

function setup(officeWide: boolean, own = false) {
  const day = {
    employee: { id: 7 },
    work_date: '2026-11-07',
    status: 'present',
    first_in: '2026-11-07T06:00:00Z',
    late_minutes: 60,
    early_minutes: 0,
    worked_minutes: 420,
    extra_minutes_unapproved: 60,
    pending_changes: false,
  } as ApiAttendanceDay;
  const all = vi.fn(async () => [day]);
  const get = vi.fn(async () => ({ data: { days: [day] } }));
  const localDay = vi.fn(() => {
    throw new Error('Local attendance must never be used');
  });
  TestBed.configureTestingModule({
    providers: [
      { provide: Api, useValue: { all, get } },
      {
        provide: Session,
        useValue: { has: () => officeWide, hasFile: () => own, account: () => ({ id: '7' }) },
      },
      {
        provide: Office,
        useValue: {
          loaded: signal(false),
          lastWorkday: () => '2026-11-07',
          policy: () => defaultPolicy,
          holidays: () => [],
          day: localDay,
          person: () => ({ department: '2' }),
          departmentList: () => [],
        },
      },
      {
        provide: Language,
        useValue: { t: (key: string) => key, formatDayShort: (day: string) => day },
      },
    ],
  });
  return { insights: TestBed.inject(Insights), all, get, localDay };
}

describe('Dashboard attendance source', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('reads paginated server days instead of the local attendance calculator', async () => {
    const { insights, all, localDay } = setup(true);
    await insights.refreshAttendance();
    expect(all).toHaveBeenCalledWith('/attendance/days', { date: '2026-11-07', per_page: 100 });
    expect(localDay).not.toHaveBeenCalled();
    expect(insights.attendanceTrend().at(-1)?.values['late']).toBe(1);
    expect(insights.attendanceTrend().at(-1)?.values['rate']).toBe(100);
    expect(insights.todayMix().map((slice) => slice.value)).toEqual([1]);
  });

  it('fetches only the signed-in employee report for self service', async () => {
    const { insights, all, get } = setup(false, true);
    await insights.refreshAttendance();
    expect(all).not.toHaveBeenCalled();
    expect(get).toHaveBeenCalledWith('/employees/7/attendance-report', {
      from: '2026-11-01',
      to: '2026-11-07',
    });
    expect(insights.myMonth().worked).toBe(420);
    expect(insights.myMonth().overtime).toBe(60);
  });

  it('shows server failure instead of substituting local figures', async () => {
    const { insights, all } = setup(true);
    all.mockRejectedValueOnce(
      new HttpErrorResponse({ status: 503, error: { message: 'Attendance unavailable' } }),
    );
    await insights.refreshAttendance();
    expect(insights.attendanceProblem()).toBe('Attendance unavailable');
    expect(insights.todayMix()).toEqual([]);
  });
  it('excludes employees outside service from the expected attendance denominator', async () => {
    const { insights, all } = setup(true);
    all.mockResolvedValue([
      { employee: { id: 7 }, work_date: '2026-11-07', status: 'present', first_in: '2026-11-07T06:00:00Z', late_minutes: 0, early_minutes: 0, pending_changes: false } as ApiAttendanceDay,
      { employee: { id: 8 }, work_date: '2026-11-07', status: 'outside_service', first_in: null, late_minutes: null, pending_changes: false } as ApiAttendanceDay,
    ]);
    await insights.refreshAttendance();
    expect(insights.attendanceTrend().at(-1)?.values['rate']).toBe(100);
  });

});
