import { Component, computed, ElementRef, inject } from '@angular/core';
import { Session } from '../../core/access/session';
import { Reporter } from '../../core/reports/reporter';
import { Ui } from '../../core/ui';
import { officeToday } from '../../data/attendance';
import { Office } from '../../data/office';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { AccountsMix } from './accounts-mix/accounts-mix';
import { Metrics } from './metrics/metrics';
import { People } from './people/people';

@Component({
  selector: 'app-dashboard',
  imports: [
    Notice,
    Metrics,
    People,
    AccountsMix,
  ],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.scss',
})
export class Dashboard extends Ui {
  protected readonly office = inject(Office);
  protected readonly chrome = inject(Chrome);
  protected readonly session = inject(Session);
  private readonly reporter = inject(Reporter);
  private readonly host = inject(ElementRef<HTMLElement>);

  protected readonly greeting = computed(() => (new Date().getHours() < 12 ? this.t('goodMorning') : this.t('goodEvening')));
  protected readonly today = computed(() => this.language.formatWeekday(officeToday()));
  protected readonly firstName = computed(() => this.text(this.session.account().name).split(' ')[0]);

  protected readonly hasSection = computed(() => this.session.can('employees') || this.session.can('access'));

  protected exportPdf(): void {
    this.reporter.pdf(
      {
        file: 'dashboard',
        title: this.t('dashboardReport'),
        subtitle: this.session.roleLine(),
        meta: [[this.t('reportScope'), this.session.isDirector() ? this.t('officeScope') : this.session.roleLine()]],
        figures: [
          ...(this.session.can('employees') ? [{ label: this.t('approvedHeadcount'), value: this.num(this.office.headcount()) }] : []),
          ...(this.session.can('employees') ? [{ label: this.t('listDepartments'), value: this.num(this.office.departmentList().length) }] : []),
          ...(this.session.can('access') ? [{ label: this.t('accountsActive'), value: this.num(this.office.accounts().filter((item) => item.enabled).length) }] : []),
        ],
        tables: [],
      },
      this.host.nativeElement,
    );
  }
}
