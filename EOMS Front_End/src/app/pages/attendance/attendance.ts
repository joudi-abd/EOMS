import { Component, computed, effect, inject, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import { ApiAttendancePunch, ApiQuarantine } from '../../core/api/types';
import { AttendanceBook, clockOf, dateOf, lineOf, markOf, RollLine, zonedIso } from '../../data/attendance-book';
import { Devices } from '../../data/devices';
import { Office } from '../../data/office';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { AttendanceDay } from './attendance-day/attendance-day';
import { AttendanceReport, ReportView } from './attendance-report/attendance-report';
import { AttendanceTable } from './attendance-table/attendance-table';
import { markWeight } from './marks';

@Component({
  selector: 'app-attendance',
  imports: [Notice, RouterLink, AttendanceTable, AttendanceDay, AttendanceReport],
  templateUrl: './attendance.html',
  styleUrl: './attendance.scss',
})
export class Attendance extends Ui {
  protected readonly office = inject(Office);
  protected readonly book = inject(AttendanceBook);
  protected readonly devices = inject(Devices);
  protected readonly session = inject(Session);
  protected readonly chrome = inject(Chrome);
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
  protected readonly officeWide = computed(() => this.session.has('attendance.view'));
  protected readonly reportView = computed<ReportView>(() => {
    const view = this.view();
    return view === 'day' ? 'month' : view;
  });

  /** Organisation-wide attendance is `attendance.view`. Everyone else sees only their own file. */
  protected readonly scope = computed(() => {
    if (this.session.has('attendance.view')) return null;
    const id = this.session.account().id;
    return new Set(/^\d+$/.test(id) ? [id] : []);
  });

  protected readonly rows = computed(() => {
    const query = this.query().trim().toLowerCase();
    const mark = this.mark();
    const scope = this.scope();
    return this.book
      .roll()
      .map((day) => {
        const person = this.office.person(String(day.employee.id));
        return lineOf(
          day,
          person ? { name: person.name, department: person.department, staffNo: person.staffNo } : undefined,
        );
      })
      .filter((row) => {
        if (scope && !scope.has(row.employeeId)) return false;
        if (mark && row.mark !== mark) return false;
        if (query && !`${row.name.ar} ${row.name.en} ${row.staffNo}`.toLowerCase().includes(query)) return false;
        return true;
      })
      .sort((a, b) => markWeight[a.mark] - markWeight[b.mark]);
  });

  protected readonly closed = computed(() => {
    const days = this.book.roll();
    return days.length > 0 && days.every((day) => markOf(day) === 'off');
  });

  protected readonly counts = computed(() => {
    const rows = this.rows();
    const count = (mark: RollLine['mark']) => rows.filter((row) => row.mark === mark).length;
    return {
      present: count('present'),
      review: count('review'),
      late: count('late'),
      early: count('early'),
      incomplete: count('incomplete'),
      absent: count('absent'),
      pending: rows.filter((row) => row.pending).length,
      overtime: rows.reduce((sum, row) => sum + row.overtime, 0),
    };
  });

  constructor() {
    super();
    effect(() => {
      const day = this.day();
      const department = this.department();
      const wide = this.officeWide();
      const self = this.session.account().id;
      const manage = this.session.has('attendance.manage');
      const open = this.view() === 'day' && !this.who();
      if (!open) return;
      untracked(() => {
        if (wide) void this.book.loadRoll(day, department);
        else if (/^\d+$/.test(self)) void this.book.loadOwn(self, day);
        if (manage) {
          void this.book.loadUnmatched();
          void this.book.loadHeld();
          void this.devices.load();
        }
      });
    });
  }

  protected lastRecord(): string {
    const zone = this.book.roll()[0]?.policy.timezone ?? 'Asia/Damascus';
    const at = this.rows()
      .map((row) => row.at)
      .filter((value): value is string => Boolean(value))
      .sort()
      .at(-1);
    if (!at) return '—';
    const clock = clockOf(at, zone);
    return clock ? `${this.date(dateOf(at, zone))} ${clock}` : '—';
  }

  protected punchWhen(iso: string | null, zone: string): string {
    const day = dateOf(iso, zone);
    const clock = clockOf(iso, zone);
    return day && clock ? `${this.date(day)} ${clock}` : '—';
  }

  protected zoneOf(deviceId: number): string {
    return this.devices.devices().find((item) => item.id === deviceId)?.timezone ?? this.book.roll()[0]?.policy.timezone ?? 'Asia/Damascus';
  }

  protected deviceName(deviceId: number): string {
    return this.devices.devices().find((item) => item.id === deviceId)?.name ?? `#${deviceId}`;
  }

  protected heldReason(reason: string): string {
    return reason === 'future_event_time' ? this.t('quarantineFuture') : reason;
  }

  protected heldStamp(row: ApiQuarantine): string {
    const zone = this.zoneOf(row.device_id);
    const day = dateOf(row.occurred_at, zone);
    const clock = clockOf(row.occurred_at, zone);
    return day && clock ? `${day}T${clock}` : '';
  }

  protected async reviewHeld(event: Event, row: ApiQuarantine): Promise<void> {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const action = (event as SubmitEvent).submitter?.getAttribute('value');
    if (action !== 'release' && action !== 'dismiss') return;
    const reason = String(data.get('reason') ?? '').trim();
    let corrected: string | null = null;
    if (action === 'release') {
      const stamp = String(data.get('at') ?? '');
      const [day, time] = stamp.split('T');
      if (!day || !time) return;
      corrected = zonedIso(day, time.slice(0, 5), this.zoneOf(row.device_id));
    }
    const saved = await this.book.reviewHeld(row.id, action, reason, corrected);
    if (saved) form.reset();
  }

  protected async link(event: Event, punch: ApiAttendancePunch): Promise<void> {
    event.preventDefault();
    const employeeId = String(new FormData(event.target as HTMLFormElement).get('employee') ?? '');
    const versions = this.office.policyVersions();
    const zone = this.book.roll()[0]?.policy.timezone ?? versions[versions.length - 1]?.timezone ?? 'Asia/Damascus';
    const validFrom = dateOf(punch.occurred_at, zone) || this.day();
    if (!employeeId) return;
    const saved = await this.book.bind(punch, employeeId, validFrom);
    if (saved && this.officeWide()) void this.book.loadRoll(this.day(), this.department());
  }

  protected filter(name: 'q' | 'dept' | 'mark' | 'day', event: Event): void {
    const value = (event.target as HTMLInputElement | HTMLSelectElement).value.trim();
    void this.router.navigate([], { queryParams: { [name]: value || null }, queryParamsHandling: 'merge' });
  }

  protected clearFilters(): void {
    void this.router.navigate([], { queryParams: { q: null, dept: null, mark: null }, queryParamsHandling: 'merge' });
  }
}
