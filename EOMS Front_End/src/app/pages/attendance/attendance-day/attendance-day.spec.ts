import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { Session } from '../../../core/access/session';
import { ApiAttendanceBundle, ApiAttendanceDay } from '../../../core/api/types';
import { Language } from '../../../core/language';
import { AttendanceBook, lineOf, markOf, needsAttendanceReview, requiredMinutesOf } from '../../../data/attendance-book';
import { Office } from '../../../data/office';
import { AttendanceDay } from './attendance-day';

function day(overrides: Partial<ApiAttendanceDay> = {}): ApiAttendanceDay {
  return {
    id: 1, employee: {id: 7, employee_no: 'E-7', name: 'Ali', department_id: null},
    work_date: '2026-11-03',
    policy: {id: 1, timezone: 'Asia/Damascus', start_time: '09:00', end_time: '16:00',
      grace_minutes: 45, grace_mode: 'completed_blocks', late_block_minutes: 60, overtime_multiplier: 2, pairing_mode: 'first_last'},
    status: 'on_leave', first_in: null, last_out: null, worked_minutes: 0, required_minutes: 0, leave_minutes: 420,
    leave: {request_id: 8, request_day_id: 9, leave_type: {id: 1, name: 'سنوية'}, start_time: '09:00:00', end_time: '16:00:00',
      full_day: true, pay_percent: 100, day_units: 1000000, covered_units: 1000000, unpaid_units: 0, still_active: true},
    pending_leave_request_id: null, holiday: null, late_actual_minutes: null, late_minutes: null, late_hours: null,
    early_minutes: null, extra_minutes_unapproved: null, flags: ['punch_on_leave_day'], coverage_complete: false,
    pending_changes: false, version: 1, approved_at: null, review_status: 'draft',
    permissions: {can_request_correction: false, can_approve: true, can_reopen: false}, ...overrides,
  };
}

describe('Attendance leave integration screen', () => {
  afterEach(() => TestBed.resetTestingModule());

  async function setup(value = day()) {
    const detail = signal<ApiAttendanceBundle | null>({day: value, punches: [], corrections: []});
    const book = {detail, loadDetail: vi.fn().mockResolvedValue(undefined), approve: vi.fn().mockResolvedValue(true)};
    TestBed.configureTestingModule({imports: [AttendanceDay], providers: [provideRouter([]),
      {provide: ActivatedRoute, useValue: {queryParamMap: of(convertToParamMap({who: '7', day: '2026-11-03'}))}},
      {provide: AttendanceBook, useValue: book},
      {provide: Session, useValue: {has: () => true, account: () => ({id: '7', userId: 1})}},
      {provide: Office, useValue: {lastWorkday: () => '2026-11-03', person: () => null, departmentList: () => []}},
    ]});
    TestBed.inject(Language).set('ar');
    const fixture = TestBed.createComponent(AttendanceDay);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return {fixture, book, root: fixture.nativeElement as HTMLElement};
  }

  it.each([
    ['on_leave', 'leave'], ['holiday', 'holiday'], ['day_off', 'off'], ['outside_service', 'off'],
    ['absent_unconfirmed', 'review'], ['incomplete', 'incomplete'], ['absent', 'absent'],
  ] as const)('maps server status %s to %s', (status, expected) => {
    expect(markOf(day({status}))).toBe(expected);
  });

  it('displays approved leave and the punch conflict before approval', async () => {
    const {root} = await setup();
    const context = root.querySelector('[data-testid="leave-context"]')!;
    expect(context.textContent).toContain('سنوية · #8');
    expect(context.textContent).toContain('09:00–16:00');
    expect(context.textContent).toContain('7:00');
    expect(root.querySelector('[data-testid="attendance-conflicts"]')?.textContent).toContain('توجد بصمة في يوم إجازة كاملة');
    expect(root.querySelector('input[name="checkIn"]')).toBeNull();
    expect([...root.querySelectorAll('button')].some(button => button.textContent?.includes('اعتماد'))).toBe(true);
  });

  it('shows a partial leave interval and reduced required work time', async () => {
    const value = day();
    const {root} = await setup(day({status: 'present', worked_minutes: 300, required_minutes: 300, leave_minutes: 120,
      leave: {...value.leave!, start_time: '11:00:00', end_time: '13:00:00', full_day: false}, flags: ['partial_leave', 'punch_during_leave']}));
    expect(root.textContent).toContain('إجازة جزئية');
    expect(root.textContent).toContain('11:00–13:00');
    expect(root.textContent).toContain('5:00');
    expect(root.textContent).toContain('2:00');
    expect(root.textContent).toContain('توجد بصمة داخل فترة الإجازة');
  });

  it('does not hide pending changes when the day is already approved', async () => {
    const value = day();
    const {root} = await setup(day({review_status: 'approved', pending_changes: true,
      leave: {...value.leave!, still_active: false}, permissions: {can_request_correction: false, can_approve: false, can_reopen: true}}));
    expect(root.textContent).toContain('وصلت تغييرات بعد اعتماد هذا اليوم');
    expect(root.textContent).toContain('هذه الإجازة لم تعد سارية');
    expect(root.querySelector('input[name="note"]')).not.toBeNull();
  });

  it('renders a named official holiday distinctly from leave', async () => {
    const {root} = await setup(day({status: 'holiday', leave: null, leave_minutes: null, holiday: {id: 3, name: 'عطلة المكتب'}, flags: []}));
    expect(root.textContent).toContain('عطلة المكتب');
    expect(root.textContent).not.toContain('سنوية');
    expect(root.querySelector('[data-testid="attendance-conflicts"]')).toBeNull();
  });

  it('explains that pending leave has not exempted attendance', async () => {
    const {root} = await setup(day({status: 'absent_unconfirmed', leave: null, pending_leave_request_id: 18,
      required_minutes: 420, leave_minutes: null, flags: []}));
    expect(root.textContent).toContain('لم يُستثنَ من الحضور بعد');
    expect(root.textContent).toContain('#18');
  });

  it('uses server required minutes for reports, including zero for full leave', () => {
    expect(requiredMinutesOf(day())).toBe(0);
    expect(requiredMinutesOf(day({status: 'present', required_minutes: 300}))).toBe(300);
    expect(requiredMinutesOf(day({status: 'holiday', required_minutes: null}))).toBe(0);
    const oldResponse = day({status: 'present', required_minutes: undefined, leave_minutes: 120});
    expect(requiredMinutesOf(oldResponse)).toBe(300);
  });

  it('surfaces leave conflicts in the roll and review list until approved', () => {
    expect(lineOf(day()).needsReview).toBe(true);
    expect(needsAttendanceReview(day({review_status: 'approved'}))).toBe(false);
    expect(needsAttendanceReview(day({review_status: 'approved', pending_changes: true}))).toBe(true);
    expect(needsAttendanceReview(day({status: 'present', flags: ['partial_leave']}))).toBe(false);
  });
});
