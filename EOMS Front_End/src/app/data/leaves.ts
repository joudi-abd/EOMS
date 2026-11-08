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
  review_note: string | null;
  change_request: { kind: string; from_date: string; reason: string } | null;
}
export interface HalfDays { first_start: string; first_end: string; second_start: string; second_end: string }
export interface LeaveOptions {
  types: { id: number; name: string; available_units: number | null; pay_percent: number; cycle_start: string; cycle_end: string }[];
  today: string;
  can_submit: boolean;
  can_review: boolean;
  can_configure: boolean;
  half_days: HalfDays;
}
@Injectable({ providedIn: 'root' })
export class Leaves {
  private readonly api = inject(Api);
  options() { return this.api.get<{data: LeaveOptions}>('/leaves/options'); }
  list(scope: 'mine' | 'review', page: number) {
    return this.api.get<{data: LeaveRecord[]; meta: {current_page: number; last_page: number}}>('/leaves', {scope, page});
  }
  preview(input: LeaveInput, id?: number) {
    return this.api.post<{data: LeaveQuote}>(id ? `/leaves/${id}/preview` : '/leaves/preview', input);
  }
  submit(input: LeaveInput, quote: LeaveQuote, consent: boolean, key: string, editing?: LeaveRecord) {
    const body = {...input, quote_token: quote.quote_token, unpaid_consent: consent,
      submission_key: key, ...(editing ? {version: editing.version} : {})};
    return editing ? this.api.put<{data: LeaveRecord}>(`/leaves/${editing.id}`, body)
      : this.api.post<{data: LeaveRecord}>('/leaves', body);
  }
  action(row: LeaveRecord, action: string, reason: string, from?: string) {
    return this.api.post<{data: LeaveRecord}>(`/leaves/${row.id}/${action}`,
      {version: row.version, reason, ...(from ? {from_date: from} : {})});
  }
  saveHalves(halves: HalfDays) { return this.api.put('/leaves/half-days', halves); }
}
