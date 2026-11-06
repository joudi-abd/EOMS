import { Injectable, inject, signal } from '@angular/core';
import { Session } from '../core/access/session';
import { Api } from '../core/api/api';
import {
  ApiAttendanceBundle,
  ApiAttendanceCorrection,
  ApiAttendanceDay,
  ApiAttendancePunch,
  ApiAttendanceReport,
  ApiItem,
  ApiPage,
  ApiQuarantine,
} from '../core/api/types';
import { CopyKey } from '../core/copy';
import { DayMark } from './attendance';
import { plain } from './employees';
import { Office } from './office';
import { Text } from './text';

export interface RollLine {
  dayId: number;
  version: number;
  employeeId: string;
  name: Text;
  department: string;
  staffNo: string;
  mark: DayMark;
  checkIn: string | null;
  checkOut: string | null;
  minutes: number | null;
  lateBy: number | null;
  earlyBy: number | null;
  overtime: number;
  pending: boolean;
  approved: boolean;
  duplicate: boolean;
  at: string | null;
}

/** The office day the server calculated, plus the punches and correction requests behind it. */
@Injectable({ providedIn: 'root' })
export class AttendanceBook {
  private readonly api = inject(Api);
  private readonly office = inject(Office);
  private readonly session = inject(Session);

  readonly roll = signal<ApiAttendanceDay[]>([]);
  readonly unmatched = signal<ApiAttendancePunch[]>([]);
  readonly held = signal<ApiQuarantine[]>([]);
  readonly detail = signal<ApiAttendanceBundle | null>(null);
  readonly reports = signal<Record<string, ApiAttendanceDay[]>>({});
  readonly loading = signal(false);

  private rollToken = 0;
  private detailToken = 0;
  private lastDept = '';

  async loadRoll(date: string, departmentId = ''): Promise<void> {
    const token = ++this.rollToken;
    this.lastDept = departmentId;
    this.loading.set(true);
    this.roll.set([]);
    try {
      const params: Record<string, string | number> = { date, per_page: 100 };
      if (departmentId) params['department_id'] = Number(departmentId);
      const rows = await this.api.all<ApiAttendanceDay>('/attendance/days', params);
      if (token !== this.rollToken) return;
      this.roll.set(rows);
    } catch (error) {
      if (token === this.rollToken) this.office.fail(error);
    } finally {
      if (token === this.rollToken) this.loading.set(false);
    }
  }

  /** An account without organisation-wide attendance still reads its own day. */
  async loadOwn(employeeId: string, date: string): Promise<void> {
    const token = ++this.rollToken;
    this.loading.set(true);
    this.roll.set([]);
    try {
      const result = await this.api.get<ApiItem<ApiAttendanceBundle>>(`/employees/${employeeId}/attendance`, { date });
      if (token !== this.rollToken) return;
      this.roll.set([result.data.day]);
      this.detail.set(result.data);
    } catch (error) {
      if (token === this.rollToken) this.office.fail(error);
    } finally {
      if (token === this.rollToken) this.loading.set(false);
    }
  }

  async loadDetail(employeeId: string, date: string): Promise<void> {
    const token = ++this.detailToken;
    try {
      const result = await this.api.get<ApiItem<ApiAttendanceBundle>>(`/employees/${employeeId}/attendance`, { date });
      if (token !== this.detailToken) return;
      this.detail.set(result.data);
    } catch (error) {
      if (token === this.detailToken) this.office.fail(error);
    }
  }

  async loadUnmatched(): Promise<void> {
    try {
      const page = await this.api.get<ApiPage<ApiAttendancePunch>>('/attendance/unmatched-punches', { per_page: 50 });
      this.unmatched.set(page.data);
    } catch (error) {
      this.office.fail(error);
    }
  }

  async loadHeld(): Promise<void> {
    try {
      const page = await this.api.get<ApiPage<ApiQuarantine>>('/attendance/quarantined-punches', { status: 'pending', per_page: 50 });
      this.held.set(page.data);
    } catch (error) {
      this.office.fail(error);
    }
  }

  async reviewHeld(id: number, action: 'release' | 'dismiss', reason: string, correctedAt: string | null): Promise<boolean> {
    const body: Record<string, unknown> = { action, reason };
    if (action === 'release') body['corrected_occurred_at'] = correctedAt;
    try {
      await this.api.post(`/attendance/quarantined-punches/${id}/review`, body);
      this.office.done(action === 'release' ? 'quarantineReleased' : 'quarantineDismissed');
      await this.loadHeld();
      return true;
    } catch (error) {
      this.office.fail(error);
      return false;
    }
  }

  /** One report call per employee. The server refuses a span longer than 31 days. */
  async loadReports(employeeIds: string[], from: string, to: string): Promise<void> {
    const missing = employeeIds.filter((id) => /^\d+$/.test(id) && !this.reports()[reportKey(id, from, to)]);
    if (missing.length === 0) return;
    this.loading.set(true);
    let failed: unknown = null;
    try {
      let cursor = 0;
      const next = async () => {
        while (cursor < missing.length) {
          const id = missing[cursor];
          cursor += 1;
          try {
            const result = await this.api.get<ApiItem<ApiAttendanceReport>>(`/employees/${id}/attendance-report`, { from, to });
            this.reports.update((current) => ({ ...current, [reportKey(id, from, to)]: result.data.days }));
          } catch (error) {
            failed = error;
          }
        }
      };
      await Promise.all(Array.from({ length: Math.min(4, missing.length) }, () => next()));
      if (failed) this.office.fail(failed);
    } finally {
      this.loading.set(false);
    }
  }

  reportDays(employeeId: string, from: string, to: string): ApiAttendanceDay[] {
    return this.reports()[reportKey(employeeId, from, to)] ?? [];
  }

  async bind(punch: ApiAttendancePunch, employeeId: string, validFrom: string): Promise<boolean> {
    try {
      await this.api.post(`/attendance/devices/${punch.device_id}/bindings`, {
        employee_id: Number(employeeId),
        device_user_id: punch.device_user_id,
        valid_from: validFrom,
      });
      this.office.done('linkedPin');
      await this.loadUnmatched();
      return true;
    } catch (error) {
      this.office.fail(error);
      return false;
    }
  }

  async correct(day: ApiAttendanceDay, entry: { type: 'times' | 'absence'; first?: string; last?: string; reason: string }): Promise<boolean> {
    const body: Record<string, unknown> = { version: day.version, type: entry.type, reason: entry.reason };
    if (entry.type === 'times') {
      body['first_in'] = zonedIso(day.work_date, entry.first ?? '', day.policy.timezone);
      body['last_out'] = zonedIso(day.work_date, entry.last ?? '', day.policy.timezone);
    }
    return this.mutate(day, 'correctedAttendance', () => this.api.post(`/attendance/days/${day.id}/corrections`, body));
  }

  async review(day: ApiAttendanceDay, correction: ApiAttendanceCorrection, decision: 'approve' | 'reject', reason: string): Promise<boolean> {
    return this.mutate(day, 'correctionReviewed', () =>
      this.api.post(`/attendance/corrections/${correction.id}/review`, { decision, reason }),
    );
  }

  async approve(day: ApiAttendanceDay): Promise<boolean> {
    return this.mutate(day, 'dayApproved', () => this.api.post(`/attendance/days/${day.id}/approve`, { version: day.version }));
  }

  async reopen(day: ApiAttendanceDay, reason: string): Promise<boolean> {
    return this.mutate(day, 'dayReopened', () => this.api.post(`/attendance/days/${day.id}/reopen`, { version: day.version, reason }));
  }

  private async mutate(day: ApiAttendanceDay, message: CopyKey, run: () => Promise<unknown>): Promise<boolean> {
    try {
      await run();
      this.office.done(message);
      this.reports.set({});
      await this.loadDetail(String(day.employee.id), day.work_date);
      if (this.session.has('attendance.view')) await this.loadRoll(day.work_date, this.lastDept);
      else await this.loadOwn(String(day.employee.id), day.work_date);
      return true;
    } catch (error) {
      this.office.fail(error);
      return false;
    }
  }
}

export function lineOf(day: ApiAttendanceDay, known?: { name: Text; department: string; staffNo: string }): RollLine {
  const zone = day.policy.timezone;
  return {
    dayId: day.id,
    version: day.version,
    employeeId: String(day.employee.id),
    name: known?.name ?? plain(day.employee.name),
    department: known?.department ?? (day.employee.department_id === null ? '' : String(day.employee.department_id)),
    staffNo: known?.staffNo ?? day.employee.employee_no,
    mark: markOf(day),
    checkIn: clockOf(day.first_in, zone),
    checkOut: clockOf(day.last_out, zone),
    minutes: day.worked_minutes,
    lateBy: day.late_minutes && day.late_minutes > 0 ? day.late_minutes : null,
    earlyBy: day.early_minutes && day.early_minutes > 0 ? day.early_minutes : null,
    overtime: day.extra_minutes_unapproved ?? 0,
    pending: day.pending_changes,
    approved: day.review_status === 'approved',
    duplicate: day.flags.includes('duplicate_punch_ignored'),
    at: day.last_out ?? day.first_in,
  };
}

export function markOf(day: ApiAttendanceDay): DayMark {
  if (day.status === 'present') {
    if ((day.late_minutes ?? 0) > 0) return 'late';
    if ((day.early_minutes ?? 0) > 0) return 'early';
    return 'present';
  }
  if (day.status === 'incomplete') return 'incomplete';
  if (day.status === 'absent') return 'absent';
  if (day.status === 'pending' || day.status === 'absent_unconfirmed') return 'review';
  return 'off';
}

export function clockOf(iso: string | null, zone: string): string | null {
  if (!iso) return null;
  try {
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone: zone,
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(new Date(iso));
    const hour = parts.find((part) => part.type === 'hour')?.value ?? '00';
    const minute = parts.find((part) => part.type === 'minute')?.value ?? '00';
    return `${hour === '24' ? '00' : hour}:${minute}`;
  } catch {
    return iso.slice(11, 16);
  }
}

export function dateOf(iso: string | null, zone: string): string {
  if (!iso) return '';
  try {
    return new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(iso));
  } catch {
    return iso.slice(0, 10);
  }
}

/** Local civil time on `day` in `zone`, as ISO 8601 with a numeric offset. */
export function zonedIso(day: string, time: string, zone: string): string {
  const [year, month, date] = day.split('-').map(Number);
  const [hour, minute] = time.split(':').map(Number);
  const local = Date.UTC(year, month - 1, date, hour, minute, 0);
  const offset = zoneOffset(local - zoneOffset(local, zone), zone);
  const sign = offset >= 0 ? '+' : '-';
  const abs = Math.abs(offset);
  const hours = String(Math.floor(abs / 3_600_000)).padStart(2, '0');
  const minutes = String(Math.floor((abs % 3_600_000) / 60_000)).padStart(2, '0');
  return `${day}T${time.length === 5 ? time : time.slice(0, 5)}:00${sign}${hours}:${minutes}`;
}

function zoneOffset(utcMs: number, zone: string): number {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: zone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).formatToParts(new Date(utcMs));
  const read = (type: string) => Number(parts.find((part) => part.type === type)?.value);
  const hour = read('hour') % 24;
  return Date.UTC(read('year'), read('month') - 1, read('day'), hour, read('minute'), read('second')) - utcMs;
}

function reportKey(employeeId: string, from: string, to: string): string {
  return `${employeeId}|${from}|${to}`;
}
