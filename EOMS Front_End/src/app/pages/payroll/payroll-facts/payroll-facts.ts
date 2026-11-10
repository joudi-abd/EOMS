import { Component, effect, inject, input, signal, untracked } from '@angular/core';
import { serverMessage } from '../../../core/api/api';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { PayrollDay, PayrollMonth, Server } from '../../../data/server';

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
  templateUrl: './payroll-facts.html',
  styleUrl: './payroll-facts.scss',
})
export class PayrollFacts extends Ui {
  readonly employeeId = input('');
  protected readonly office = inject(Office);
  private readonly server = inject(Server);
  protected readonly chosen = signal('');
  protected readonly month = signal(new Date().toISOString().slice(0, 7));
  protected readonly facts = signal<PayrollMonth | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal('');

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
