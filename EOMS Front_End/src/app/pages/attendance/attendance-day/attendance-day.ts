import { Component, computed, effect, inject, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { Session } from '../../../core/access/session';
import { ApiAttendanceCorrection, ApiAttendanceDay } from '../../../core/api/types';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { DayMark } from '../../../data/attendance';
import { AttendanceBook, clockOf, lineOf } from '../../../data/attendance-book';
import { plain } from '../../../data/employees';
import { Office } from '../../../data/office';
import { markKey, markTone } from '../marks';

@Component({
  selector: 'app-attendance-day',
  templateUrl: './attendance-day.html',
  styleUrl: './attendance-day.scss',
})
export class AttendanceDay extends Ui {
  protected readonly office = inject(Office);
  protected readonly book = inject(AttendanceBook);
  protected readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly day = computed(() => this.params()?.get('day') || this.office.lastWorkday());
  protected readonly who = computed(() => this.params()?.get('who') ?? '');
  protected readonly allowed = computed(() => {
    if (!/^\d+$/.test(this.who())) return false;
    return this.session.has('attendance.view') || this.who() === this.session.account().id;
  });

  protected readonly bundle = computed(() => {
    const detail = this.book.detail();
    if (!detail || String(detail.day.employee.id) !== this.who() || detail.day.work_date !== this.day()) return null;
    return detail;
  });
  protected readonly row = computed(() => {
    const day = this.bundle()?.day;
    if (!day) return null;
    const person = this.office.person(String(day.employee.id));
    return lineOf(day, person ? { name: person.name, department: person.department, staffNo: person.staffNo } : undefined);
  });
  protected readonly name = computed(() => this.row()?.name ?? plain(''));
  protected readonly punches = computed(() => this.bundle()?.punches ?? []);
  protected readonly corrections = computed(() => this.bundle()?.corrections ?? []);
  protected readonly notices = computed(() => {
    const keys: Record<string, CopyKey> = {
      punch_on_leave_day: 'attendancePunchOnLeave',
      punch_during_leave: 'attendancePunchDuringLeave',
      work_on_holiday: 'attendanceWorkOnHoliday',
      work_on_day_off: 'attendanceWorkOnDayOff',
      correction_superseded_by_leave: 'attendanceCorrectionSuperseded',
      direction_conflict: 'attendanceDirectionConflict',
      punch_outside_service: 'attendancePunchOutsideService',
    };
    return [...new Set(this.bundle()?.day.flags ?? [])].filter(flag => keys[flag]).map(flag => keys[flag]);
  });

  constructor() {
    super();
    effect(() => {
      const id = this.who();
      const day = this.day();
      const allowed = this.allowed();
      if (!allowed) return;
      untracked(() => void this.book.loadDetail(id, day));
    });
  }

  protected mark(mark: DayMark): string {
    return this.t(markKey[mark]);
  }

  protected tone(mark: DayMark): string {
    return markTone(mark);
  }

  protected direction(value: string): string {
    if (value === 'in') return this.t('checkIn');
    if (value === 'out') return this.t('checkOut');
    return this.t('punchUnknown');
  }

  protected statusLabel(status: string): string {
    const key = status === 'pending' || status === 'approved' || status === 'rejected' ? status : 'pending';
    return this.t(key as CopyKey);
  }

  protected clock(iso: string | null, day: ApiAttendanceDay): string {
    return clockOf(iso, day.policy.timezone) ?? '—';
  }

  protected department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : id || '—';
  }

  protected canReview(correction: ApiAttendanceCorrection): boolean {
    return (
      this.session.has('attendance.approve') &&
      correction.status === 'pending' &&
      correction.requested_by !== this.session.account().userId
    );
  }

  protected back(): void {
    void this.router.navigate([], { queryParams: { who: null }, queryParamsHandling: 'merge' });
  }

  protected async save(event: Event): Promise<void> {
    event.preventDefault();
    const day = this.bundle()?.day;
    if (!day) return;
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const saved = await this.book.correct(day, {
      type: 'times',
      first: String(data.get('checkIn') ?? ''),
      last: String(data.get('checkOut') ?? ''),
      reason: String(data.get('note') ?? '').trim(),
    });
    if (saved) form.reset();
  }

  protected async absence(event: Event): Promise<void> {
    event.preventDefault();
    const day = this.bundle()?.day;
    if (!day) return;
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const saved = await this.book.correct(day, { type: 'absence', reason: String(data.get('note') ?? '').trim() });
    if (saved) form.reset();
  }

  protected async decide(event: Event, correction: ApiAttendanceCorrection): Promise<void> {
    event.preventDefault();
    const day = this.bundle()?.day;
    const decision = (event as SubmitEvent).submitter?.getAttribute('value');
    if (!day || (decision !== 'approve' && decision !== 'reject')) return;
    const data = new FormData(event.target as HTMLFormElement);
    await this.book.review(day, correction, decision, String(data.get('note') ?? '').trim());
  }

  protected approve(): void {
    const day = this.bundle()?.day;
    if (day) void this.book.approve(day);
  }

  protected async reopen(event: Event): Promise<void> {
    event.preventDefault();
    const day = this.bundle()?.day;
    if (!day) return;
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const saved = await this.book.reopen(day, String(data.get('note') ?? '').trim());
    if (saved) form.reset();
  }
}
