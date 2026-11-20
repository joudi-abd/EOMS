import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem, ApiPage } from '../core/api/types';
import { cleanQuery, Money } from './finance';

/**
 * Employee advances and custody (`/finance/employee-advances`, `/finance/custodies`, phase 6). Reading only, plus
 * postponing an installment: issuing an advance and every custody movement is a voucher (`advance_issue`,
 * `custody_issue`, `custody_settlement`, `custody_return`) made on the vouchers screen.
 */

export type AdvanceStatus = 'active' | 'settled' | 'cancelled';
export type InstallmentStatus = 'pending' | 'deducted' | 'postponed';
export type CustodyStatus = 'open' | 'closed';
export type CustodyKind = 'issue' | 'settlement' | 'return';

export interface AdvanceInstallment {
  id: number;
  advance_id: number;
  due_month: string;
  amount: Money;
  status: InstallmentStatus;
  pay_run_id: number | null;
  postponed_reason: string | null;
}

export interface EmployeeAdvance {
  id: number;
  employee_id: number;
  party_id: number | null;
  voucher_id: number;
  amount: Money;
  currency_id: number;
  installments_count: number;
  start_month: string;
  status: AdvanceStatus;
  notes: string | null;
  created_at: string | null;
  currency?: { id: number; code: string; decimals: number } | null;
  party?: { id: number; name: string } | null;
  voucher?: { id: number; number: string | null; branch_id: number | null; voucher_date: string; status: string } | null;
  /** Pending installments' sum. */
  remaining: Money;
}

export interface EmployeeAdvanceDetail extends EmployeeAdvance {
  installments: AdvanceInstallment[];
  deducted_total: Money;
  next_due_month: string | null;
}

export interface AdvanceFilters {
  employee_id?: number;
  status?: AdvanceStatus | '';
  currency_id?: number;
  per_page?: number;
}

/** T15: what each employee still owes, and what falls due in the coming months. */
export interface AdvanceReport {
  balances: {
    employee_id: number;
    employee_name: string | null;
    party_id: number | null;
    currency_id: number;
    currency: string;
    advances: number;
    outstanding: Money;
    next_due_month: string | null;
    next_amount: Money;
  }[];
  upcoming: { due_month: string; currency_id: number; currency: string; installments: number; amount: Money }[];
}

export interface Custody {
  id: number;
  holder_party_id: number;
  holder_name: string | null;
  project_id: number | null;
  project_name: string | null;
  currency_id: number;
  currency: string;
  branch_id: number | null;
  status: CustodyStatus;
  opened_on: string | null;
  closed_on: string | null;
  issued_total: Money;
  settled_total: Money;
  returned_total: Money;
  balance: Money;
  age_days: number;
  overdue: boolean;
}

export interface CustodyLine {
  line_no: number;
  project_id: number | null;
  project_cost_category_id: number | null;
  expense_category_id: number | null;
  party_id: number | null;
  asset_id: number | null;
  debit: Money;
  memo: string | null;
}

export interface CustodyMovement {
  id: number;
  number: string | null;
  type: string;
  purpose: string | null;
  kind: CustodyKind;
  voucher_date: string;
  status: string;
  cash_box_id: number | null;
  description: string | null;
  amount: Money;
  lines: CustodyLine[];
}

export interface CustodyDetail extends Custody {
  vouchers: CustodyMovement[];
}

export interface CustodyFilters {
  holder_party_id?: number;
  project_id?: number;
  currency_id?: number;
  status?: CustodyStatus | '';
  overdue?: boolean;
  per_page?: number;
}

/** T16: open custodies with their age, and the open balance per currency. */
export interface CustodyReport {
  custodies: Custody[];
  totals: { currency: string; balance: Money; count: number }[];
  overdue_days: number;
}

@Injectable({ providedIn: 'root' })
export class FinAdvances {
  private readonly api = inject(Api);

  advances(filters: AdvanceFilters, page = 1): Promise<ApiPage<EmployeeAdvance>> {
    return this.api.get('/finance/employee-advances', { ...cleanQuery({ ...filters }), page });
  }

  async advance(id: number): Promise<EmployeeAdvanceDetail> {
    return (await this.api.get<ApiItem<EmployeeAdvanceDetail>>(`/finance/employee-advances/${id}`)).data;
  }

  async advanceReport(months = 3): Promise<AdvanceReport> {
    return (await this.api.get<ApiItem<AdvanceReport>>('/finance/employee-advances/report', { months })).data;
  }

  /** R18: the installment moves after the advance's last one; returns the advance as it now stands. */
  async postpone(installmentId: number, reason: string): Promise<EmployeeAdvanceDetail> {
    return (await this.api.post<ApiItem<EmployeeAdvanceDetail>>(`/finance/employee-advance-installments/${installmentId}/postpone`, { reason })).data;
  }

  custodies(filters: CustodyFilters, page = 1): Promise<ApiPage<Custody>> {
    return this.api.get('/finance/custodies', { ...cleanQuery({ ...filters }), page });
  }

  async custody(id: number): Promise<CustodyDetail> {
    return (await this.api.get<ApiItem<CustodyDetail>>(`/finance/custodies/${id}`)).data;
  }

  async custodyReport(): Promise<CustodyReport> {
    return (await this.api.get<ApiItem<CustodyReport>>('/finance/custodies/report')).data;
  }
}
