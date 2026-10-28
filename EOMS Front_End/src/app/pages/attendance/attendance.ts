import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import { device } from '../../data/attendance';
import { Office } from '../../data/office';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { AttendanceDay } from './attendance-day/attendance-day';
import { AttendanceReport, ReportView } from './attendance-report/attendance-report';
import { AttendanceTable } from './attendance-table/attendance-table';
import { directionKey, markWeight } from './marks';
import { PunchLog } from './punch-log/punch-log';

@Component({
  selector: 'app-attendance',
  imports: [Notice, RouterLink, AttendanceTable, AttendanceDay, AttendanceReport, PunchLog],
  templateUrl: './attendance.html',
  styleUrl: './attendance.scss',
})
export class Attendance extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly chrome = inject(Chrome);
  protected readonly device = device;
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly views: { id: 'day' | ReportView; label: CopyKey }[] = [
    { id: 'day', label: 'viewDay' },
    { id: 'month', label: 'viewMonth' },
    { id: 'late', label: 'viewLate' },
    { id: 'review', label: 'viewReview' },
    { id: 'overtime', label: 'viewOvertime' },
  ];

  protected readonly view = computed(() => {
    const value = this.params()?.get('view');
    return this.views.some((item) => item.id === value) ? (value as 'day' | ReportView) : 'day';
  });
  protected readonly who = computed(() => this.params()?.get('who') ?? '');
  protected readonly query = computed(() => this.params()?.get('q') ?? '');
  protected readonly department = computed(() => this.params()?.get('dept') ?? '');
  protected readonly mark = computed(() => this.params()?.get('mark') ?? '');
  protected readonly day = computed(() => this.params()?.get('day') || this.office.lastWorkday());
  protected readonly report = computed(() => this.office.day(this.day()));
  protected readonly officeWide = computed(() => this.session.canAct('attendance.office'));
  protected readonly reportView = computed<ReportView>(() => {
    const view = this.view();
    return view === 'day' ? 'month' : view;
  });

  /** Human resources see the office. A manager sees their team, and everyone sees their own record. */
  protected readonly scope = computed(() => {
    const actor = this.session.account().id;
    if (this.session.canAct('attendance.office')) return null;
    return new Set([actor, ...this.office.team(actor).map((item) => item.id)]);
  });

  protected readonly rows = computed(() => {
    const query = this.query().trim().toLowerCase();
    const department = this.department();
    const mark = this.mark();
    const scope = this.scope();
    return this.report()
      .rows.flatMap((row) => {
        const employee = this.office.person(row.employeeId);
        if (!employee) return [];
        if (scope && !scope.has(employee.id)) return [];
        if (department && employee.department !== department) return [];
        if (mark && row.mark !== mark) return [];
        if (query && !`${employee.name.ar} ${employee.name.en} ${employee.staffNo}`.toLowerCase().includes(query)) return [];
        return [{ ...row, employee }];
      })
      .sort((a, b) => markWeight[a.mark] - markWeight[b.mark]);
  });

  protected readonly log = computed(() => {
    const scope = this.scope();
    return this.report()
      .log.filter((row) => !scope || (row.employeeId !== null && scope.has(row.employeeId)))
      .map((row) => ({
        id: row.id,
        time: row.at.slice(11, 16),
        pin: row.pin,
        person: row.employeeId ? this.text(this.office.personName(row.employeeId)) : this.t('unmatched'),
        direction: this.t(directionKey[row.status]),
        duplicate: row.duplicate,
        unmatched: row.employeeId === null,
      }));
  });

  protected lastRecord(): string {
    const at = this.report().lastAt;
    if (!at) return '—';
    return `${this.date(at)} ${at.slice(11, 16)}`;
  }

  protected link(event: Event, pin: string): void {
    event.preventDefault();
    const employeeId = String(new FormData(event.target as HTMLFormElement).get('employee') ?? '');
    if (employeeId) this.office.linkPin(pin, employeeId, this.session.account().id);
  }

  protected filter(name: 'q' | 'dept' | 'mark' | 'day', event: Event): void {
    const value = (event.target as HTMLInputElement | HTMLSelectElement).value.trim();
    void this.router.navigate([], { queryParams: { [name]: value || null }, queryParamsHandling: 'merge' });
  }

  protected clearFilters(): void {
    void this.router.navigate([], { queryParams: { q: null, dept: null, mark: null }, queryParamsHandling: 'merge' });
  }
}
