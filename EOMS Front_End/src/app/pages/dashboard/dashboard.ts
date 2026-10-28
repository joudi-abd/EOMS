import { Component, computed, ElementRef, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { Session } from '../../core/access/session';
import { Reporter } from '../../core/reports/reporter';
import { Ui } from '../../core/ui';
import { officeToday } from '../../data/attendance';
import { Office } from '../../data/office';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { AccountsMix } from './accounts-mix/accounts-mix';
import { AttendanceBlock } from './attendance/attendance';
import { HrFlow } from './hr-flow/hr-flow';
import { LeaveQueue } from './leave/leave-queue';
import { Metrics } from './metrics/metrics';
import { MyMonth } from './my-month/my-month';
import { People } from './people/people';
import { PurchaseTable } from './purchases/purchase-table';

@Component({
  selector: 'app-dashboard',
  imports: [
    Notice,
    Metrics,
    LeaveQueue,
    AttendanceBlock,
    MyMonth,
    People,
    HrFlow,
    PurchaseTable,
    AccountsMix,
  ],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.scss',
})
export class Dashboard extends Ui {
  protected readonly office = inject(Office);
  protected readonly chrome = inject(Chrome);
  protected readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly reporter = inject(Reporter);
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly leaveOpen = computed(() => this.params()?.get('leave') === 'open');
  protected readonly decidesLeave = computed(() => {
    const actor = this.session.account().id;
    return this.session.canAct('leave.decide') || this.office.leaves().some((item) => this.office.canAdvanceLeave(item.id, actor));
  });

  protected readonly greeting = computed(() => (new Date().getHours() < 12 ? this.t('goodMorning') : this.t('goodEvening')));
  protected readonly today = computed(() => this.language.formatWeekday(officeToday()));
  protected readonly firstName = computed(() => this.text(this.session.account().name).split(' ')[0]);

  protected readonly officeView = computed(() => this.session.canAct('attendance.office'));
  protected readonly hasSection = computed(
    () =>
      this.session.can('employees') ||
      this.session.can('attendance') ||
      this.session.can('leave') ||
      this.session.can('requests') ||
      this.session.can('payroll') ||
      this.session.can('procurement') ||
      this.session.can('access'),
  );

  protected exportPdf(): void {
    const today = this.office.attendanceToday();
    this.reporter.pdf(
      {
        file: 'dashboard',
        title: this.t('dashboardReport'),
        subtitle: this.session.roleLine(),
        meta: [[this.t('reportScope'), this.session.isDirector() ? this.t('officeScope') : this.session.roleLine()]],
        figures: [
          ...(this.session.can('employees') ? [{ label: this.t('approvedHeadcount'), value: this.num(this.office.headcount()) }] : []),
          ...(this.officeView() ? [{ label: this.t('presentToday'), value: `${this.num(today.arrived)} / ${this.num(Math.max(0, today.expected - today.mission))}` }] : []),
          ...(this.officeView() ? [{ label: this.t('lateArrival'), value: this.num(today.late) }] : []),
          ...(this.officeView() ? [{ label: this.t('reviewMark'), value: this.num(today.review) }] : []),
        ],
        tables: [],
      },
      this.host.nativeElement,
    );
  }

  protected toggleLeave(): void {
    void this.router.navigate([], { queryParams: { leave: this.leaveOpen() ? null : 'open' }, queryParamsHandling: 'merge' });
  }
}
