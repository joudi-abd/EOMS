import { Component, computed, effect, ElementRef, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { Reporter, ReportDraft } from '../../core/reports/reporter';
import { Ui } from '../../core/ui';
import { Office } from '../../data/office';
import { ChartRow, ChartSlice, tone } from '../../shared/charts/chart';
import { DonutChart } from '../../shared/charts/donut-chart/donut-chart';
import { RankChart } from '../../shared/charts/rank-chart/rank-chart';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { LeaveFile } from './leave-file/leave-file';
import { LeaveTable } from './leave-table/leave-table';

@Component({
  selector: 'app-leave',
  imports: [Notice, RouterLink, LeaveTable, LeaveFile, DonutChart, RankChart],
  templateUrl: './leave.html',
  styleUrl: './leave.scss',
})
export class Leave extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly chrome = inject(Chrome);
  protected readonly exporting = signal(false);
  private readonly reporter = inject(Reporter);
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly request = computed(() => this.params()?.get('req') ?? '');
  protected readonly query = computed(() => this.params()?.get('q') ?? '');
  protected readonly status = computed(() => this.params()?.get('status') ?? '');
  protected readonly kind = computed(() => this.params()?.get('kind') ?? '');
  protected readonly year = computed(() => this.office.lastWorkday().slice(0, 4));

  protected readonly used = computed(() => {
    const actor = this.session.account().id;
    return this.office.leaveTypes().map((type) => ({ type, amount: this.office.usedLeave(actor, type.id, this.year()) }));
  });

  constructor() {
    super();
    effect(() => {
      const id = this.request();
      if (!id || id === 'new' || this.office.leaves().some((item) => item.id === id)) {
        return;
      }
      void this.router.navigate([], { queryParams: { req: null }, queryParamsHandling: 'merge' });
    });
  }

  protected readonly rows = computed(() => {
    const query = this.query().trim().toLowerCase();
    const status = this.status();
    const kind = this.kind();
    const actor = this.session.account().id;
    const officeWide = this.session.canAct('leave.decide');
    return this.office.leaves().filter((row) => {
      if (!officeWide && row.employeeId !== actor && this.office.person(row.employeeId)?.managerId !== actor) return false;
      if (status && row.status !== status) return false;
      if (kind && row.kind !== kind) return false;
      if (!query) return true;
      return [row.employee.ar, row.employee.en, row.type.ar, row.type.en].join(' ').toLowerCase().includes(query);
    });
  });

  protected openNew(): void {
    void this.router.navigate([], { queryParams: { req: 'new' }, queryParamsHandling: 'merge' });
  }

  protected filter(name: 'q' | 'status' | 'kind', event: Event): void {
    const value = (event.target as HTMLInputElement | HTMLSelectElement).value.trim();
    void this.router.navigate([], { queryParams: { [name]: value || null }, queryParamsHandling: 'merge' });
  }

  protected clearFilters(): void {
    void this.router.navigate([], { queryParams: { q: null, status: null, kind: null }, queryParamsHandling: 'merge' });
  }

  protected readonly statusMix = computed<ChartSlice[]>(() => {
    const rows = this.rows();
    return [
      { label: this.t('pending_manager'), value: rows.filter((row) => row.status === 'pending_manager').length, color: tone.wait },
      { label: this.t('pending_hr'), value: rows.filter((row) => row.status === 'pending_hr').length, color: tone.sky },
      { label: this.t('approved'), value: rows.filter((row) => row.status === 'approved').length, color: tone.ok },
      { label: this.t('rejected'), value: rows.filter((row) => row.status === 'rejected').length, color: tone.late },
    ].filter((slice) => slice.value > 0);
  });

  protected readonly byType = computed<ChartRow[]>(() =>
    this.office
      .leaveTypes()
      .map((type) => ({ label: this.text(type.name), value: this.rows().filter((row) => row.typeId === type.id).length }))
      .filter((row) => row.value > 0)
      .sort((a, b) => b.value - a.value),
  );

  private draft(): ReportDraft {
    return {
      file: 'leave',
      title: this.t('leaveReportTitle'),
      subtitle: this.t('leavePath'),
      meta: [[this.t('reportScope'), this.session.canAct('leave.decide') ? this.t('officeScope') : this.t('teamScope')]],
      figures: this.statusMix().map((slice) => ({ label: slice.label, value: this.num(slice.value) })),
      tables: [
        {
          name: this.t('leave'),
          columns: [
            { header: this.t('employeeCol') },
            { header: this.t('kind') },
            { header: this.t('leaveFrom'), numeric: true },
            { header: this.t('leaveUntil'), numeric: true },
            { header: this.t('span'), numeric: true },
            { header: this.t('leaveReason') },
            { header: this.t('state') },
          ],
          rows: this.rows().map((row) => [
            this.text(row.employee),
            this.text(row.type),
            row.from,
            row.until,
            row.kind === 'permit' ? `${row.start}–${row.end}` : row.days,
            row.reason,
            this.t(row.status),
          ]),
        },
      ],
    };
  }

  protected async exportExcel(): Promise<void> {
    this.exporting.set(true);
    try {
      await this.reporter.excel(this.draft());
    } finally {
      this.exporting.set(false);
    }
  }

  protected exportPdf(): void {
    this.reporter.pdf(this.draft(), this.host.nativeElement);
  }
}
