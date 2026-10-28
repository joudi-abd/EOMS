import { computed, inject, Injectable } from '@angular/core';
import { roleIds, roleLabel } from '../../core/access/accounts';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Language } from '../../core/language';
import { DayMark, isWorkday, minutesOf } from '../../data/attendance';
import { isoDay, monthBounds, parseDay, daysBetween } from '../../data/hr';
import { Office } from '../../data/office';
import { ChartDatum, ChartRow, ChartSlice, tone } from '../../shared/charts/chart';
import { markFill as markTone, markKey } from '../attendance/marks';

/** Figures behind the dashboard charts, already scoped to what the signed-in account may see. */
@Injectable({ providedIn: 'root' })
export class Insights {
  private readonly office = inject(Office);
  private readonly session = inject(Session);
  private readonly language = inject(Language);

  private t(key: CopyKey): string {
    return this.language.t(key);
  }

  /** The last twelve workdays up to the latest one, oldest first. */
  readonly recentDays = computed(() => {
    const days: string[] = [];
    const cursor = parseDay(this.office.lastWorkday());
    for (let step = 0; step < 40 && days.length < 12; step++) {
      const day = isoDay(cursor);
      if (isWorkday(day, this.office.policy(), this.office.holidays())) days.unshift(day);
      cursor.setDate(cursor.getDate() - 1);
    }
    return days;
  });

  readonly attendanceTrend = computed<ChartDatum[]>(() =>
    this.recentDays().map((day) => {
      const report = this.office.day(day);
      const expected = Math.max(1, report.expected - report.mission);
      return {
        label: this.language.formatDayShort(day),
        values: { rate: Math.round((report.arrived / expected) * 100), late: report.late, review: report.review },
      };
    }),
  );

  readonly todayMix = computed<ChartSlice[]>(() => {
    const report = this.office.attendanceToday();
    const marks: DayMark[] = ['present', 'late', 'early', 'incomplete', 'review', 'absent', 'leave', 'mission'];
    return marks
      .map((mark) => ({ label: this.t(markKey[mark]), value: report.rows.filter((row) => row.mark === mark).length, color: markTone[mark] }))
      .filter((slice) => slice.value > 0);
  });

  readonly headcount = computed<ChartRow[]>(() =>
    this.office
      .departments()
      .map((item) => ({ label: this.language.text(item.name), value: item.count }))
      .sort((a, b) => b.value - a.value),
  );

  readonly lateByDepartment = computed<ChartRow[]>(() => {
    const counts = new Map<string, number>();
    for (const day of this.recentDays()) {
      for (const row of this.office.day(day).rows) {
        if (row.mark !== 'late') continue;
        const dept = this.office.person(row.employeeId)?.department ?? '';
        counts.set(dept, (counts.get(dept) ?? 0) + 1);
      }
    }
    return this.office
      .departmentList()
      .map((item) => ({ label: this.language.text(item.name), value: counts.get(item.id) ?? 0 }))
      .filter((row) => row.value > 0)
      .sort((a, b) => b.value - a.value);
  });

  readonly leaveStatus = computed<ChartSlice[]>(() => {
    const rows = this.visibleLeaves();
    return [
      { label: this.t('pending_manager'), value: rows.filter((row) => row.status === 'pending_manager').length, color: tone.wait },
      { label: this.t('pending_hr'), value: rows.filter((row) => row.status === 'pending_hr').length, color: tone.sky },
      { label: this.t('approved'), value: rows.filter((row) => row.status === 'approved').length, color: tone.ok },
      { label: this.t('rejected'), value: rows.filter((row) => row.status === 'rejected').length, color: tone.late },
    ].filter((slice) => slice.value > 0);
  });

  readonly leaveByType = computed<ChartRow[]>(() => {
    const year = this.office.lastWorkday().slice(0, 4);
    const rows = this.visibleLeaves().filter((row) => row.status === 'approved' && row.from.startsWith(year));
    return this.office
      .leaveTypes()
      .map((type) => {
        const list = rows.filter((row) => row.typeId === type.id);
        const amount = list.reduce((sum, row) => sum + (row.kind === 'permit' ? (minutesOf(row.end) - minutesOf(row.start)) / 60 : row.days), 0);
        return { label: this.language.text(type.name), value: amount, unit: type.unit === 'hour' ? this.t('hoursShort') : this.t('days') };
      })
      .filter((row) => row.value > 0);
  });

  readonly requestsByKind = computed<ChartDatum[]>(() => {
    const actor = this.session.account().id;
    const all = this.session.canAct('leave.decide');
    const rows = this.office.requests().filter((row) => all || row.employeeId === actor || this.office.person(row.employeeId)?.managerId === actor);
    const kinds: { id: 'correction' | 'equipment' | 'resignation'; label: CopyKey }[] = [
      { id: 'correction', label: 'kindCorrection' },
      { id: 'equipment', label: 'kindEquipment' },
      { id: 'resignation', label: 'kindResignation' },
    ];
    return kinds.map((kind) => {
      const list = rows.filter((row) => row.kind === kind.id);
      return {
        label: this.t(kind.label),
        values: {
          waiting: list.filter((row) => row.status === 'pending_manager' || row.status === 'pending_hr').length,
          approved: list.filter((row) => row.status === 'approved').length,
          rejected: list.filter((row) => row.status === 'rejected').length,
        },
      };
    });
  });

  readonly payroll = computed(() => {
    const run = this.office.runs()[0];
    if (!run) return null;
    const paid = this.office.payments().filter((item) => item.runId === run.id).length;
    const [year, month] = run.period.split('-').map(Number);
    return {
      run,
      month: this.language.formatMonth(new Date(year, month - 1, 1)),
      slices: [
        { label: this.t('paidCount'), value: paid, color: tone.ok },
        { label: this.t('notPaid'), value: Math.max(0, run.employeeIds.length - paid), color: tone.quiet },
      ],
    };
  });

  readonly purchaseMix = computed<ChartSlice[]>(() => {
    const rows = this.office.purchases();
    return [
      { label: this.t('pending'), value: rows.filter((row) => row.status === 'pending').length, color: tone.wait },
      { label: this.t('approved'), value: rows.filter((row) => row.status === 'approved').length, color: tone.ok },
      { label: this.t('rejected'), value: rows.filter((row) => row.status === 'rejected').length, color: tone.late },
    ].filter((slice) => slice.value > 0);
  });

  readonly accountsByRole = computed<ChartRow[]>(() =>
    roleIds
      .map((role) => ({ label: this.t(roleLabel[role]), value: this.office.accounts().filter((item) => item.enabled && item.roles.includes(role)).length }))
      .filter((row) => row.value > 0)
      .sort((a, b) => b.value - a.value),
  );

  readonly accountState = computed<ChartSlice[]>(() => {
    const list = this.office.accounts();
    return [
      { label: this.t('enabled'), value: list.filter((item) => item.enabled).length, color: tone.ok },
      { label: this.t('disabled'), value: list.filter((item) => !item.enabled).length, color: tone.quiet },
    ].filter((slice) => slice.value > 0);
  });

  /** The signed-in person's own month so far. */
  readonly myMonth = computed(() => {
    const id = this.session.account().id;
    const last = this.office.lastWorkday();
    const { first } = monthBounds(last.slice(0, 7));
    const counts = new Map<DayMark, number>();
    const hours: ChartDatum[] = [];
    let worked = 0;
    let overtime = 0;
    for (const day of daysBetween(first, last)) {
      const row = this.office.day(day).rows.find((item) => item.employeeId === id);
      if (!row || row.mark === 'off' || row.mark === 'holiday') continue;
      counts.set(row.mark, (counts.get(row.mark) ?? 0) + 1);
      worked += row.minutes ?? 0;
      overtime += row.overtime;
      hours.push({ label: this.language.formatDayShort(day), values: { hours: Math.round(((row.minutes ?? 0) / 60) * 10) / 10 } });
    }
    const slices = [...counts.entries()].map(([mark, value]) => ({ label: this.t(markKey[mark]), value, color: markTone[mark] }));
    return { slices, hours: hours.slice(-12), worked, overtime, days: [...counts.values()].reduce((sum, value) => sum + value, 0) };
  });

  private visibleLeaves() {
    const actor = this.session.account().id;
    const all = this.session.canAct('leave.decide');
    return this.office.leaves().filter((row) => all || row.employeeId === actor || this.office.person(row.employeeId)?.managerId === actor);
  }
}
