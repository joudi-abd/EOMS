import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';

export interface LeaveInput {
  leave_type_id: number;
  start_date: string;
  end_date: string;
  unit: 'day' | 'half' | 'hours';
  half?: 'first' | 'second';
  start_time?: string;
  end_time?: string;
  reason: string;
}
export interface LeaveDay {
  id?: number;
  date: string;
  start_time: string;
  end_time: string;
  day_units: number;
  covered_units: number;
  unpaid_units: number;
  pay_percent: number;
  active?: boolean;
}
export interface LeaveQuote {
  days: LeaveDay[];
  total_units: number;
  covered_units: number;
  unpaid_units: number;
  pay_percent: number;
  quote_token: string;
}
export interface LeaveRecord {
  id: number;
  version: number;
  leave_type_id: number;
  employee_name: string;
  status: string;
  input: LeaveInput;
  days: LeaveDay[];
  policy: { pay_percent: number };
  is_owner: boolean;
  can_review: boolean;
  can_act_for_owner?: boolean;
  review_note: string | null;
  change_request: { kind: string; from_date: string; reason: string } | null;
  /** Who decides: HR, or the company manager for an HR member's own request. */
  approval_level?: 'staff' | 'reviewers';
  /** A pending request lapses after this day. */
  expires_on?: string;
  created_at?: string;
}
export interface HalfDays { first_start: string; first_end: string; second_start: string; second_end: string }
export interface LeaveBalance {
  granted_units: number;
  used_units: number;
  reserved_units: number;
  available_units: number;
}
export interface LeaveTypeOption {
  id: number;
  name: string;
  available_units: number | null;
  pay_percent: number;
  cycle_start: string;
  cycle_end: string;
  balance?: LeaveBalance | null;
  awaiting_opening?: boolean;
}
export interface LeaveOptions {
  types: LeaveTypeOption[];
  today: string;
  can_submit: boolean;
  can_review: boolean;
  can_configure: boolean;
  half_days: HalfDays;
}
export interface LeaveListQuery {
  scope: 'mine' | 'review' | 'all';
  page: number;
  status?: string;
  employee_id?: number;
  leave_type_id?: number;
  department_id?: number;
  from?: string;
  to?: string;
  change_pending?: boolean;
}
@Injectable({ providedIn: 'root' })
export class Leaves {
  private readonly api = inject(Api);
  options(employeeId?: number) {
    return this.api.get<{data: LeaveOptions}>('/leaves/options', employeeId ? {employee_id: employeeId} : {});
  }
  list(query: LeaveListQuery) {
    return this.api.get<{data: LeaveRecord[]; meta: {current_page: number; last_page: number}}>('/leaves', this.params(query));
  }
  preview(input: LeaveInput, id?: number, employeeId?: number) {
    const body = employeeId ? {...input, employee_id: employeeId} : input;
    return this.api.post<{data: LeaveQuote}>(id ? `/leaves/${id}/preview` : '/leaves/preview', body);
  }
  submit(input: LeaveInput, quote: LeaveQuote, consent: boolean, key: string, editing?: LeaveRecord, employeeId?: number, approveNow = false) {
    const body = {...input, quote_token: quote.quote_token, unpaid_consent: consent,
      submission_key: key, ...(editing ? {version: editing.version} : {}),
      ...(employeeId && !editing ? {employee_id: employeeId} : {}),
      ...(approveNow && !editing ? {approve_now: true} : {})};
    return editing ? this.api.put<{data: LeaveRecord}>(`/leaves/${editing.id}`, body)
      : this.api.post<{data: LeaveRecord}>('/leaves', body);
  }
  action(row: LeaveRecord, action: string, reason: string, from?: string) {
    return this.api.post<{data: LeaveRecord}>(`/leaves/${row.id}/${action}`,
      {version: row.version, reason, ...(from ? {from_date: from} : {})});
  }
  saveHalves(halves: HalfDays) {
    const clock = (value: string) => value.slice(0, 5);
    return this.api.put('/leaves/half-days', {
      first_start: clock(halves.first_start),
      first_end: clock(halves.first_end),
      second_start: clock(halves.second_start),
      second_end: clock(halves.second_end),
    });
  }
  calendar(from: string, to: string, departmentId?: number, includePending = false) {
    const params: Record<string, string | number> = {from, to, include_pending: includePending ? 1 : 0};
    if (departmentId) params['department_id'] = departmentId;
    return this.api.get<{data: LeaveCalendar}>('/leaves/calendar', params);
  }
  private params(query: LeaveListQuery): Record<string, string | number> {
    const params: Record<string, string | number> = {scope: query.scope, page: query.page};
    if (query.status) params['status'] = query.status;
    if (query.employee_id) params['employee_id'] = query.employee_id;
    if (query.leave_type_id) params['leave_type_id'] = query.leave_type_id;
    if (query.department_id) params['department_id'] = query.department_id;
    if (query.from) params['from'] = query.from;
    if (query.to) params['to'] = query.to;
    if (query.change_pending !== undefined) params['change_pending'] = query.change_pending ? 1 : 0;
    return params;
  }
}

export interface LeaveCalendarDay {
  date: string;
  employee_id: number;
  employee_name: string | null;
  department_id: number | null;
  request_id: number;
  status: string;
  leave_type: {id: number | null; name: string | null};
  start_time: string;
  end_time: string;
  full_day: boolean;
}
export interface LeaveCalendar {
  days: LeaveCalendarDay[];
  holidays: {id: number; date: string; name: string}[];
}
