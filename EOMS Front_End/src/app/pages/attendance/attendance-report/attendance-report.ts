import { Component, computed, ElementRef, inject, input, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Reporter, ReportDraft } from '../../../core/reports/reporter';
import { Ui } from '../../../core/ui';
import { DayMark, minutesOf } from '../../../data/attendance';
import { daysBetween, parseDay, isoDay } from '../../../data/hr';
import { Employee } from '../../../data/employees';
import { Office, projects } from '../../../data/office';
import { BarChart } from '../../../shared/charts/bar-chart/bar-chart';
import { ChartDatum, ChartRow, ChartSeries, tone } from '../../../shared/charts/chart';
import { RankChart } from '../../../shared/charts/rank-chart/rank-chart';
import { markFill as dayTone, markKey, markTone } from '../marks';

export type ReportView = 'month' | 'late' | 'review' | 'overtime';

interface Line {
  day: string;
  employee: Employee;
  mark: DayMark;
  checkIn: string | null;
  checkOut: string | null;
  lateBy: number | null;
  overtime: number;
}

@Component({
  selector: 'app-attendance-report',
  imports: [RouterLink, BarChart, RankChart],
  templateUrl: './attendance-report.html',
  styleUrl: './attendance-report.scss',
})
export class AttendanceReport extends Ui {
  readonly view = input.required<ReportView>();
  readonly scope = input<Set<string> | null>(null);
  protected readonly office = inject(Office);
  protected readonly projects = projects;
  protected readonly exporting = signal(false);
  private readonly reporter = inject(Reporter);
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly to = computed(() => this.params()?.get('to') || this.office.lastWorkday());
  protected readonly from = computed(() => {
    const value = this.params()?.get('from');
    if (value) return value;
    const end = parseDay(this.to());
    return isoDay(new Date(end.getFullYear(), end.getMonth(), 1));
  });
  protected readonly department = computed(() => this.params()?.get('dept') ?? '');
  protected readonly manager = computed(() => this.params()?.get('mgr') ?? '');
  protected readonly project = computed(() => this.params()?.get('proj') ?? '');
  protected readonly person = computed(() => this.params()?.get('emp') ?? '');

  protected readonly days = computed(() => daysBetween(this.from(), this.to()).slice(0, 93));

  protected readonly managers = computed(() => {
    const ids = new Set(this.office.employees().map((item) => item.managerId).filter(Boolean));
    return this.office.employees().filter((item) => ids.has(item.id));
  });

  protected readonly people = computed(() => {
    const scope = this.scope();
    const department = this.department();
    const manager = this.manager();
    const project = this.project();
    const person = this.person();
    const days = this.days();
    return this.office.employees().filter((item) => {
      if (scope && !scope.has(item.id)) return false;
      if (department && item.department !== department) return false;
      if (manager && item.managerId !== manager) return false;
      if (person && item.id !== person) return false;
      if (project) {
        const assigned = item.projects.includes(project);
        const onSite = this.office.missions().some((task) => task.employeeId === item.id && task.projectId === project && days.includes(task.day));
        if (!assigned && !onSite) return false;
      }
      return true;
    });
  });

  protected readonly lines = computed<Line[]>(() => {
    const people = new Map(this.people().map((item) => [item.id, item]));
    return this.days().flatMap((day) =>
      this.office.day(day).rows.flatMap((row) => {
        const employee = people.get(row.employeeId);
        return employee
          ? [{ day, employee, mark: row.mark, checkIn: row.checkIn, checkOut: row.checkOut, lateBy: row.lateBy, overtime: row.overtime }]
          : [];
      }),
    );
  });

  protected readonly shiftMinutes = computed(() => minutesOf(this.office.policy().end) - minutesOf(this.office.policy().start));

  protected readonly summary = computed(() => {
    const shift = this.shiftMinutes();
    const byPerson = new Map<string, { employee: Employee; workdays: number; arrived: number; late: number; review: number; absent: number; leave: number; mission: number; worked: number; required: number; overtime: number }>();
    for (const line of this.lines()) {
      const entry = byPerson.get(line.employee.id) ?? {
        employee: line.employee,
        workdays: 0,
        arrived: 0,
        late: 0,
        review: 0,
        absent: 0,
        leave: 0,
        mission: 0,
        worked: 0,
        required: 0,
        overtime: 0,
      };
      if (line.mark !== 'off' && line.mark !== 'holiday') {
        entry.workdays += 1;
        if (line.mark !== 'leave') entry.required += shift;
      }
      if (line.mark === 'present' || line.mark === 'late' || line.mark === 'early' || line.mark === 'incomplete') entry.arrived += 1;
      if (line.mark === 'late') entry.late += 1;
      if (line.mark === 'review' || line.mark === 'incomplete') entry.review += 1;
      if (line.mark === 'absent') entry.absent += 1;
      if (line.mark === 'leave') entry.leave += 1;
      if (line.mark === 'mission') entry.mission += 1;
      if (line.checkIn && line.checkOut) entry.worked += Math.max(0, minutesOf(line.checkOut) - minutesOf(line.checkIn));
      entry.overtime += line.overtime;
      byPerson.set(line.employee.id, entry);
    }
    return [...byPerson.values()];
  });

  protected readonly late = computed(() => this.lines().filter((line) => line.mark === 'late').sort((a, b) => (b.lateBy ?? 0) - (a.lateBy ?? 0)));
  protected readonly review = computed(() => this.lines().filter((line) => line.mark === 'review' || line.mark === 'incomplete' || line.mark === 'absent'));
  protected readonly overtime = computed(() => this.lines().filter((line) => line.overtime > 0));

  protected readonly totals = computed(() => {
    const rows = this.summary();
    const sum = (pick: (row: (typeof rows)[number]) => number) => rows.reduce((total, row) => total + pick(row), 0);
    const expected = sum((row) => row.workdays - row.leave - row.mission);
    return {
      rate: expected > 0 ? Math.round((sum((row) => row.arrived) / expected) * 100) : 0,
      late: sum((row) => row.late),
      review: sum((row) => row.review),
      overtime: sum((row) => row.overtime),
    };
  });

  protected mark(mark: DayMark): string {
    return this.t(markKey[mark]);
  }

  protected tone(mark: DayMark): string {
    return markTone(mark);
  }

  protected deptName(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : id;
  }

  protected filter(name: 'from' | 'to' | 'dept' | 'mgr' | 'proj' | 'emp', event: Event): void {
    const value = (event.target as HTMLInputElement | HTMLSelectElement).value.trim();
    void this.router.navigate([], { queryParams: { [name]: value || null }, queryParamsHandling: 'merge' });
  }

  protected readonly mixSeries = computed<ChartSeries[]>(() =>
    (['present', 'late', 'early', 'incomplete', 'review', 'leave', 'mission'] as DayMark[]).map((mark) => ({ key: mark, label: this.mark(mark), color: dayTone[mark] })),
  );

  /** One stacked bar per workday in the period, by attendance state. */
  protected readonly dailyMix = computed<ChartDatum[]>(() => {
    const people = new Set(this.people().map((item) => item.id));
    return this.days()
      .map((day) => ({ day, report: this.office.day(day) }))
      .filter((entry) => entry.report.workday)
      .map((entry) => {
        const values: Record<string, number> = {};
        for (const row of entry.report.rows) {
          if (!people.has(row.employeeId)) continue;
          values[row.mark] = (values[row.mark] ?? 0) + 1;
        }
        return { label: this.language.formatDayShort(entry.day), values };
      });
  });

  protected readonly overtimeDaily = computed<ChartDatum[]>(() => {
    const byDay = new Map<string, number>();
    for (const line of this.overtime()) byDay.set(line.day, (byDay.get(line.day) ?? 0) + line.overtime);
    return this.days()
      .filter((day) => this.office.day(day).workday || byDay.has(day))
      .map((day) => ({ label: this.language.formatDayShort(day), values: { overtime: Math.round(((byDay.get(day) ?? 0) / 60) * 10) / 10 } }));
  });

  protected readonly overtimeSeries = computed<ChartSeries[]>(() => [{ key: 'overtime', label: this.t('overtimeHours'), color: tone.teal }]);

  /** Who carries the most of the current view, for the ranking chart beside the table. */
  protected readonly ranking = computed<ChartRow[]>(() => {
    const view = this.view();
    const totals = new Map<string, { label: string; value: number }>();
    const add = (line: Line, value: number) => {
      const entry = totals.get(line.employee.id) ?? { label: this.text(line.employee.name), value: 0 };
      entry.value += value;
      totals.set(line.employee.id, entry);
    };
    if (view === 'late') this.late().forEach((line) => add(line, 1));
    else if (view === 'review') this.review().forEach((line) => add(line, 1));
    else if (view === 'overtime') this.overtime().forEach((line) => add(line, Math.round((line.overtime / 60) * 10) / 10));
    else this.summary().forEach((row) => totals.set(row.employee.id, { label: this.text(row.employee.name), value: row.workdays - row.leave - row.mission > 0 ? Math.round((row.arrived / (row.workdays - row.leave - row.mission)) * 100) : 0 }));
    return [...totals.values()].sort((a, b) => (view === 'month' ? a.value - b.value : b.value - a.value));
  });

  protected rankTitle(): string {
    const view = this.view();
    if (view === 'late') return this.t('lateRank');
    if (view === 'review') return this.t('reviewRank');
    if (view === 'overtime') return this.t('overtimeRank');
    return this.t('attendanceRate');
  }

  protected rankColor(): string {
    const view = this.view();
    return view === 'late' ? tone.wait : view === 'review' ? tone.late : view === 'overtime' ? tone.teal : tone.blue;
  }

  private draft(): ReportDraft {
    const view = this.view();
    const viewLabel: Record<ReportView, string> = { month: this.t('viewMonth'), late: this.t('viewLate'), review: this.t('viewReview'), overtime: this.t('viewOvertime') };
    const totals = this.totals();
    const scope = [
      this.department() ? this.deptName(this.department()) : this.t('allDepartments'),
      this.manager() ? this.text(this.office.personName(this.manager())) : '',
      this.project() ? this.text(projects.find((item) => item.id === this.project())?.name ?? { ar: '', en: '' }) : '',
      this.person() ? this.text(this.office.personName(this.person())) : '',
    ]
      .filter(Boolean)
      .join(' · ');
    const policy = this.office.policy();
    const figures = [
      { label: this.t('attendanceRate'), value: this.percent(totals.rate) },
      { label: this.t('lateDays'), value: this.num(totals.late) },
      { label: this.t('reviewDays'), value: this.num(totals.review) },
      { label: this.t('overtimeTotal'), value: this.hours(totals.overtime) },
      { label: this.t('employeesLabel'), value: this.num(this.summary().length) },
    ];
    const lineColumns = [
      { header: this.t('date') },
      { header: this.t('staffNo') },
      { header: this.t('employeeCol') },
      { header: this.t('department') },
      { header: this.t('state') },
      { header: this.t('arrival'), numeric: true },
      { header: this.t('departure'), numeric: true },
      { header: `${this.t('lateBy')} (${this.t('minutesUnit')})`, numeric: true },
      { header: this.t('overtimeLabel'), numeric: true },
    ];
    const lineRows = (source: Line[]) =>
      source.map((line) => [
        line.day,
        line.employee.staffNo,
        this.text(line.employee.name),
        this.deptName(line.employee.department),
        this.mark(line.mark),
        line.checkIn ?? '',
        line.checkOut ?? '',
        line.lateBy ?? '',
        line.overtime ? this.hours(line.overtime) : '',
      ]);
    const summaryTable = {
      name: this.t('viewMonth'),
      columns: [
        { header: this.t('staffNo') },
        { header: this.t('employeeCol') },
        { header: this.t('department') },
        { header: this.t('workdaysCol'), numeric: true },
        { header: this.t('presentDays'), numeric: true },
        { header: this.t('lateDays'), numeric: true },
        { header: this.t('reviewDays'), numeric: true },
        { header: this.t('absentDays'), numeric: true },
        { header: this.t('leaveCount'), numeric: true },
        { header: this.t('missionDays'), numeric: true },
        { header: this.t('workedHours'), numeric: true },
        { header: this.t('requiredHours'), numeric: true },
        { header: this.t('overtimeHours'), numeric: true },
      ],
      rows: this.summary().map((row) => [
        row.employee.staffNo,
        this.text(row.employee.name),
        this.deptName(row.employee.department),
        row.workdays,
        row.arrived,
        row.late,
        row.review,
        row.absent,
        row.leave,
        row.mission,
        this.hours(row.worked),
        this.hours(row.required),
        this.hours(row.overtime),
      ]),
    };
    const tables =
      view === 'month'
        ? [summaryTable, { name: this.t('viewLate'), columns: lineColumns, rows: lineRows(this.late()) }, { name: this.t('viewReview'), columns: lineColumns, rows: lineRows(this.review()) }]
        : [{ name: viewLabel[view], columns: lineColumns, rows: lineRows(view === 'late' ? this.late() : view === 'review' ? this.review() : this.overtime()) }, summaryTable];
    return {
      file: `attendance-${view}-${this.from()}-${this.to()}`,
      title: `${this.t('attendanceReportTitle')} · ${viewLabel[view]}`,
      subtitle: `${this.t('policyLine')} ${policy.start}–${policy.end} · ${this.t('graceLabel')} ${policy.grace} ${this.t('minutesUnit')} · ${this.t('noDeduction')}`,
      meta: [
        [this.t('reportPeriod'), `${this.date(this.from())} – ${this.date(this.to())}`],
        [this.t('reportScope'), scope],
      ],
      figures,
      tables,
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
