import { Component, computed, inject } from '@angular/core';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { Insights } from '../insights';

interface Metric {
  label: CopyKey;
  value: string;
  sub: string;
  tone?: 'ok' | 'wait' | 'late';
}

/** Four headline figures picked from what the signed-in account may see. */
@Component({
  selector: 'app-metrics',
  templateUrl: './metrics.html',
  styleUrl: './metrics.scss',
})
export class Metrics extends Ui {
  protected readonly session = inject(Session);
  protected readonly office = inject(Office);
  private readonly insights = inject(Insights);

  private readonly waiting = computed(() => {
    const actor = this.session.account().id;
    let count = this.office.leaves().filter((item) => this.office.canAdvanceLeave(item.id, actor)).length;
    count += this.office.requests().filter((item) => this.office.canDecideRequest(item.id, actor)).length;
    if (this.session.canAct('purchase.decide')) count += this.office.purchases().filter((item) => item.status === 'pending').length;
    count += this.office.runs().filter((item) => item.status !== 'draft' && this.office.runStep(item, actor) !== null).length;
    return count;
  });

  protected readonly cards = computed<Metric[]>(() => {
    const cards: Metric[] = [];
    const today = this.office.attendanceToday();
    if (this.session.can('employees')) {
      const open = this.office.employees().filter((item) => item.status !== 'ended').length;
      cards.push({ label: 'approvedHeadcount', value: this.num(this.office.headcount()), sub: `${this.num(open)} ${this.t('openFilesShort')}` });
    }
    if (this.session.canAct('attendance.office')) {
      const expected = Math.max(0, today.expected - today.mission);
      const rate = expected ? Math.round((today.arrived / expected) * 100) : 0;
      cards.push({
        label: 'presentToday',
        value: `${this.num(today.arrived)} ${this.t('ofWord')} ${this.num(expected)}`,
        sub: `${this.percent(rate)} · ${this.t('lateArrival')} ${this.num(today.late)}`,
        tone: expected === 0 ? undefined : rate >= 90 ? 'ok' : 'wait',
      });
    } else if (this.session.hasFile()) {
      const mine = this.insights.myMonth();
      const present = mine.slices.reduce((sum, slice) => sum + slice.value, 0);
      cards.push({ label: 'myDaysTitle', value: this.num(present), sub: `${this.t('workedHours')} ${this.hours(mine.worked)}` });
    }
    if (this.session.can('access')) {
      const enabled = this.office.accounts().filter((item) => item.enabled).length;
      cards.push({ label: 'accountsActive', value: this.num(enabled), sub: `${this.num(this.office.accounts().length)} ${this.t('accountsTotal')}` });
    }
    if (this.session.can('leave') && this.session.hasFile() && !this.session.canAct('attendance.office')) {
      const year = this.office.lastWorkday().slice(0, 4);
      const used = this.office.usedLeave(this.session.account().id, 'annual', year);
      cards.push({ label: 'usedTitle', value: this.num(used), sub: this.t('annualLeaveDays') });
    }
    const actor = this.session.account().id;
    const decides =
      this.session.canAct('leave.decide') ||
      this.session.manages() ||
      this.session.canAct('purchase.decide') ||
      this.session.canAct('payroll.review') ||
      this.session.canAct('payroll.approve');
    if (decides) {
      cards.push({ label: 'waiting', value: this.num(this.waiting()), sub: this.t('decisionsWaiting'), tone: this.waiting() > 0 ? 'wait' : 'ok' });
    } else if (this.session.hasFile()) {
      const open = (status: string) => status === 'pending_manager' || status === 'pending_hr';
      const mine =
        this.office.leaves().filter((item) => item.employeeId === actor && open(item.status)).length +
        this.office.requests().filter((item) => item.employeeId === actor && open(item.status)).length;
      cards.push({ label: 'myOpenRequests', value: this.num(mine), sub: this.t('myOpenCaption'), tone: mine > 0 ? 'wait' : 'ok' });
    }
    return cards.slice(0, 4);
  });
}
