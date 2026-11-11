import { inject, Injectable, signal } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem } from '../core/api/types';
import { Office } from './office';

export interface NoticeItem {
  id: string;
  kind: string | null;
  message: string | null;
  read_at: string | null;
  created_at: string | null;
  /** The notification's context, e.g. `board_id` and `card_id` for task cards. */
  data?: Record<string, unknown> | null;
}

export interface PayrollDay {
  date: string;
  status: string;
  blockers: string[];
}

export interface PayrollMonth {
  employee_id: number;
  month: string;
  from: string;
  to: string;
  ready: boolean;
  blocked_days: number;
  summary: {
    required_minutes: number;
    worked_minutes: number;
    late_minutes: number;
    early_minutes: number;
    extra_minutes: number;
    leave_minutes: number;
    absent_days: number;
    unpaid_leave_units: number;
    leave: { name: string | null; pay_percent: number; covered_units: number; unpaid_units: number }[];
  };
  days: PayrollDay[];
  units_per_day: number;
  financial_effect_applied: boolean;
}

export interface ExchangeRateRow {
  rate: string | number;
  rate_date: string;
}

/** The documented endpoints that sit outside the attendance, leave and employee stores. */
@Injectable({ providedIn: 'root' })
export class Server {
  private readonly api = inject(Api);
  private readonly office = inject(Office);
  readonly unread = signal(0);

  async notifications(page: number): Promise<{ data: NoticeItem[]; lastPage: number }> {
    const result = await this.api.get<{ data: NoticeItem[]; meta: { last_page: number }; unread_count: number }>('/notifications', { page, per_page: 20 });
    this.unread.set(result.unread_count);
    return { data: result.data, lastPage: result.meta.last_page };
  }

  async readNotice(id: string): Promise<void> {
    await this.api.post(`/notifications/${id}/read`);
  }

  async readEveryNotice(): Promise<void> {
    await this.api.post('/notifications/read-all');
    this.unread.set(0);
  }

  payrollMonth(employeeId: string, month: string): Promise<ApiItem<PayrollMonth>> {
    return this.api.get<ApiItem<PayrollMonth>>(`/employees/${employeeId}/payroll-inputs`, { month });
  }

  latestRate(from: string, to: string): Promise<ApiItem<ExchangeRateRow | null>> {
    return this.api.get<ApiItem<ExchangeRateRow | null>>('/settings/exchange-rates/latest', { from, to });
  }

  async saveRate(from: string, to: string, rate: number, rateDate: string): Promise<ExchangeRateRow | null> {
    try {
      const result = await this.api.post<ApiItem<ExchangeRateRow>>('/settings/exchange-rates', { from, to, rate, rate_date: rateDate });
      this.office.done('exchangeSaved');
      return result.data;
    } catch (error) {
      this.office.fail(error);
      return null;
    }
  }

  /** Safe settings any signed-in account may read. An empty group arrives as a list, not a map. */
  async publicCompany(): Promise<string> {
    const result = await this.api.get<{ data: Record<string, string> | unknown[] }>('/settings/config/public');
    const data = result.data;
    if (!data || Array.isArray(data)) return '';
    return data['company.name'] ?? '';
  }

  companySettings(): Promise<ApiItem<Record<string, string>>> {
    return this.api.get<ApiItem<Record<string, string>>>('/settings/config/company');
  }

  async saveSetting(key: string, value: string): Promise<boolean> {
    try {
      await this.api.put(`/settings/config/${key}`, { value, group: 'company' });
      this.office.done('settingSaved');
      return true;
    } catch (error) {
      this.office.fail(error);
      return false;
    }
  }
}
