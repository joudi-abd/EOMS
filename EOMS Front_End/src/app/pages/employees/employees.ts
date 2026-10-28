import { Component, computed, ElementRef, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { Reporter, ReportDraft } from '../../core/reports/reporter';
import { Ui } from '../../core/ui';
import { EmployeeStatus } from '../../data/employees';
import { Office } from '../../data/office';
import { ChartRow, ChartSlice, tone } from '../../shared/charts/chart';
import { DonutChart } from '../../shared/charts/donut-chart/donut-chart';
import { RankChart } from '../../shared/charts/rank-chart/rank-chart';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { EmployeeFile } from './employee-file/employee-file';
import { fingerKey } from './employee-info/employee-info';
import { EmployeeTable } from './employee-table/employee-table';

const statusFill: Record<EmployeeStatus, string> = { active: tone.ok, away: tone.wait, leaving: tone.sky, ended: tone.quiet };

@Component({
  selector: 'app-employees',
  imports: [Notice, RouterLink, EmployeeTable, EmployeeFile, DonutChart, RankChart],
  templateUrl: './employees.html',
  styleUrl: './employees.scss',
})
export class Employees extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly chrome = inject(Chrome);
  protected readonly exporting = signal(false);
  private readonly reporter = inject(Reporter);
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly file = computed(() => this.params()?.get('file') ?? '');
  protected readonly query = computed(() => this.params()?.get('q') ?? '');
  protected readonly department = computed(() => this.params()?.get('dept') ?? '');
  protected readonly status = computed(() => this.params()?.get('staff') ?? '');
  protected readonly titleId = computed(() => this.params()?.get('title') ?? '');

  protected readonly rows = computed(() => {
    const query = this.query().trim().toLowerCase();
    const department = this.department();
    const status = this.status();
    const titleId = this.titleId();
    return this.office.employees().filter((employee) => {
      if (department && employee.department !== department) return false;
      if (status && employee.status !== status) return false;
      if (titleId && employee.titleId !== titleId) return false;
      if (!query) return true;
      return [employee.name.ar, employee.name.en, employee.title.ar, employee.title.en, employee.staffNo, employee.phone, employee.email]
        .join(' ')
        .toLowerCase()
        .includes(query);
    });
  });

  protected readonly stats = computed(() => {
    const rows = this.rows();
    return {
      total: rows.length,
      active: rows.filter((item) => item.status === 'active').length,
      away: rows.filter((item) => item.status === 'away').length,
      leaving: rows.filter((item) => item.status === 'leaving').length,
      fingers: rows.filter((item) => item.fingerprint === 'enrolled').length,
    };
  });

  protected readonly statusMix = computed<ChartSlice[]>(() =>
    (['active', 'away', 'leaving', 'ended'] as EmployeeStatus[])
      .map((status) => ({ label: this.t(status), value: this.rows().filter((item) => item.status === status).length, color: statusFill[status] }))
      .filter((slice) => slice.value > 0),
  );

  protected readonly byDepartment = computed<ChartRow[]>(() =>
    this.office
      .departmentList()
      .map((item) => ({ label: this.text(item.name), value: this.rows().filter((row) => row.department === item.id).length }))
      .filter((row) => row.value > 0)
      .sort((a, b) => b.value - a.value),
  );

  protected openNew(): void {
    void this.router.navigate([], { queryParams: { file: 'new' }, queryParamsHandling: 'merge' });
  }

  protected filter(name: 'q' | 'dept' | 'staff' | 'title', event: Event): void {
    const value = (event.target as HTMLInputElement | HTMLSelectElement).value.trim();
    void this.router.navigate([], { queryParams: { [name]: value || null }, queryParamsHandling: 'merge' });
  }

  protected clearFilters(): void {
    void this.router.navigate([], { queryParams: { q: null, dept: null, staff: null, title: null }, queryParamsHandling: 'merge' });
  }

  private draft(): ReportDraft {
    const departments = this.office.departmentList();
    const stats = this.stats();
    return {
      file: 'employees',
      title: this.t('employeesReportTitle'),
      subtitle: this.t('sensitiveNote'),
      meta: [
        [this.t('department'), this.department() ? this.text(departments.find((item) => item.id === this.department())?.name ?? { ar: '', en: '' }) : this.t('allDepartments')],
        [this.t('state'), this.status() ? this.t(this.status() as EmployeeStatus) : this.t('allStatuses')],
      ],
      figures: [
        { label: this.t('employeesLabel'), value: this.num(stats.total) },
        { label: this.t('active'), value: this.num(stats.active) },
        { label: this.t('away'), value: this.num(stats.away) },
        { label: this.t('leaving'), value: this.num(stats.leaving) },
        { label: this.t('fingerOk'), value: this.num(stats.fingers) },
      ],
      tables: [
        {
          name: this.t('employees'),
          columns: [
            { header: this.t('staffNo') },
            { header: this.t('personName') },
            { header: this.t('jobTitle') },
            { header: this.t('department') },
            { header: this.t('manager') },
            { header: this.t('state') },
            { header: this.t('tabFinger') },
            { header: this.t('hired'), numeric: true },
          ],
          rows: this.rows().map((item) => [
            item.staffNo,
            this.text(item.name),
            this.text(item.title),
            this.text(departments.find((dept) => dept.id === item.department)?.name ?? { ar: '', en: '' }),
            item.managerId ? this.text(this.office.personName(item.managerId)) : '',
            this.t(item.status),
            this.t(fingerKey[item.fingerprint]),
            item.hired,
          ]),
        },
      ],
      landscape: true,
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
