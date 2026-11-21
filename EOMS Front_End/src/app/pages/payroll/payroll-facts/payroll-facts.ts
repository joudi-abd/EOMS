import { Component, effect, inject, input, signal, untracked } from '@angular/core';
import { RouterLink } from '@angular/router';
import { serverMessage } from '../../../core/api/api';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { AttendanceBook } from '../../../data/attendance-book';
import { Office } from '../../../data/office';
import { PayrollDay, PayrollMonth, Server } from '../../../data/server';

const reviewFlags = new Set([
  'punch_on_leave_day',
  'punch_during_leave',
  'work_on_day_off',
  'work_on_holiday',
  'direction_conflict',
  'punch_outside_service',
  'correction_superseded_by_leave',
]);

const blockerKey: Record<string, CopyKey> = {
  not_approved: 'blockerNotApproved',
  pending_changes: 'blockerPending',
  correction_pending: 'blockerCorrection',
  leave_pending: 'blockerLeave',
  leave_change_pending: 'blockerLeaveChange',
};

/** The server's day status in the words the attendance screens already use. */
const dayStateKey: Record<string, CopyKey> = {
  pending: 'reviewMark',
  absent_unconfirmed: 'reviewMark',
  present: 'present',
  incomplete: 'incomplete',
  absent: 'absent',
  day_off: 'offDay',
  outside_service: 'bulkOutside',
  on_leave: 'onLeave',
  holiday: 'holidayMark',
};

const dayStateTone: Record<string, string> = {
  pending: 'wait',
  absent_unconfirmed: 'wait',
  incomplete: 'wait',
  present: 'ok',
  absent: 'late',
};

@Component({
  selector: 'app-payroll-facts',
  imports: [RouterLink],
  templateUrl: './payroll-facts.html',
  styleUrl: './payroll-facts.scss',
})
export class PayrollFacts extends Ui {
  readonly employeeId = input('');
  protected readonly office = inject(Office);
  private readonly server = inject(Server);
  private readonly attendance = inject(AttendanceBook);
  private readonly session = inject(Session);
  protected readonly chosen = signal('');
  protected readonly month = signal(new Date().toISOString().slice(0, 7));
  protected readonly facts = signal<PayrollMonth | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  protected get mayApproveAttendance(): boolean {
    return this.session.has('attendance.approve');
  }

  constructor() {
    super();
    effect(() => {
      const id = this.employeeId() || this.chosen();
      const month = this.month();
      if (!/^\d+$/.test(id) || !/^\d{4}-\d{2}$/.test(month)) return;
      untracked(() => void this.load(id, month));
    });
  }

  protected pick(event: Event): void {
    this.chosen.set((event.target as HTMLSelectElement).value);
  }

  protected setMonth(event: Event): void {
    this.month.set((event.target as HTMLInputElement).value);
  }

  protected units(value: number, perDay: number): string {
    return this.num(perDay ? value / perDay : value);
  }

  protected blocker(code: string): string {
    const key = blockerKey[code];
    return key ? this.t(key) : code;
  }

  protected dayState(status: string): string {
    const key = dayStateKey[status];
    return key ? this.t(key) : status;
  }

  protected dayTone(status: string): string {
    return dayStateTone[status] ?? '';
  }

  protected blocked(row: PayrollMonth): PayrollDay[] {
    return row.days.filter((day) => day.blockers.length > 0);
  }

  protected reasons(day: PayrollDay): string {
    return day.blockers.map((code) => this.blocker(code)).join(' · ');
  }

  protected canApproveDay(day: PayrollDay): boolean {
    if (!this.mayApproveAttendance || day.id == null || day.version == null) return false;
    if (!day.blockers.includes('not_approved')) return false;
    if (day.blockers.some((code) => code === 'correction_pending' || code === 'leave_pending' || code === 'leave_change_pending')) {
      return false;
    }
    return !(day.flags ?? []).some((flag) => reviewFlags.has(flag));
  }

  protected employeeForLink(): string {
    return this.employeeId() || this.chosen();
  }

  protected async approveDay(day: PayrollDay): Promise<void> {
    if (!this.canApproveDay(day) || this.busy() || day.id == null || day.version == null) return;
    this.busy.set(true);
    try {
      const ok = await this.attendance.approveDayId(day.id, day.version);
      if (!ok) return;
      const id = this.employeeForLink();
      if (id) await this.load(id, this.month());
    } finally {
      this.busy.set(false);
    }
  }

  private async load(id: string, month: string): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      const result = await this.server.payrollMonth(id, month);
      this.facts.set(result.data);
    } catch (error) {
      this.facts.set(null);
      this.error.set(serverMessage(error) || this.t('requestFailed'));
    } finally {
      this.busy.set(false);
    }
  }
}
