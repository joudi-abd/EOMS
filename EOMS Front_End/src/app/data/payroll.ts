import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem, ApiPage } from '../core/api/types';
import { Money } from './finance';

/** Shapes returned by `/api/v1/payroll` (PayRunController). Money arrives as decimal strings; run totals are numbers. */

export type RunStatus = 'draft' | 'submitted' | 'approved';
export type ItemCategory = 'earning' | 'deduction';
export type ItemOrigin = 'auto' | 'proposed' | 'adjustment' | 'manual';
export type ItemStatus = 'applied' | 'proposed' | 'approved' | 'modified' | 'waived' | 'rejected';
export type PayMethod = 'cash' | 'sham_cash';
export type ShamStatus = 'open' | 'confirmed' | 'cancelled';

export interface ShamExport {
  id: number;
  pay_run_id: number;
  currency_code: string;
  doc_id: string;
  pay_date: string;
  count: number;
  total: Money;
  status: ShamStatus;
  box_id: number | null;
}

export interface RunTotals {
  earnings: number;
  deductions: number;
  net: number;
}

export interface PayRunSummary {
  id: number;
  month: string;
  status: RunStatus;
  prepared_by: number;
  approved_by: number | null;
  return_note: string | null;
  version: number;
  calculated_at: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  employees: number;
  not_ready: number;
  blocking_issues: number;
  paid: number;
  payable: number;
  /** Keyed by currency code; currencies are never added together. An empty run sends `[]`. */
  totals: Record<string, RunTotals> | [];
}

export interface LineIssue {
  code: string;
  message: string;
  detail: unknown;
  blocking: boolean;
}

export interface PayRunLine {
  id: number;
  employee_id: number;
  contract_id: number | null;
  pay_type: string | null;
  currency_code: string | null;
  rate: Money | null;
  working_days: number;
  attendance_ready: boolean;
  blocked_days: number;
  issues: LineIssue[];
  earnings: Money;
  deductions: Money;
  net: Money;
  employee_name: string | null;
  employee_no: string | null;
  paid: boolean;
}

export interface PayRunDetail extends PayRunSummary {
  lines: PayRunLine[];
}

export interface PayRunItem {
  id: number;
  pay_run_line_id: number;
  kind: string;
  category: ItemCategory;
  origin: ItemOrigin;
  unit: string | null;
  quantity: Money | null;
  proposed_amount: Money;
  amount: Money;
  status: ItemStatus;
  reason: string | null;
  details: unknown;
  decided_by: number | null;
  decided_at: string | null;
}

export interface PayPayment {
  id: number;
  pay_run_line_id: number;
  contract_currency: string;
  net_amount: Money;
  paid_currency: string;
  exchange_rate: Money;
  rate_date: string;
  converted_amount: Money;
  paid_amount: Money;
  rounding_difference: Money;
  method: PayMethod;
  wallet: string | null;
  reference: string | null;
  paid_at: string;
  note: string | null;
  finance_transaction_id: number | null;
}

export interface PayLineDetail extends Omit<PayRunLine, 'paid'> {
  pay_run_id: number;
  sham_cash_wallet: string | null;
  items: PayRunItem[];
  payment: PayPayment | null;
}

export interface PaymentInput {
  currency: string;
  method: PayMethod;
  box_id: number;
  paid_at: string;
  reference: string | null;
  note: string | null;
}

/** Totals as a list, whatever shape the server sent. */
export function totalsList(run: PayRunSummary): ({ currency: string } & RunTotals)[] {
  return Array.isArray(run.totals) ? [] : Object.entries(run.totals).map(([currency, value]) => ({ currency, ...value }));
}

@Injectable({ providedIn: 'root' })
export class Payroll {
  private readonly api = inject(Api);

  runs(status?: RunStatus): Promise<ApiPage<PayRunSummary>> {
    return this.api.get('/payroll/runs', status ? { status } : {});
  }

  async run(id: number): Promise<PayRunDetail> {
    return (await this.api.get<ApiItem<PayRunDetail>>(`/payroll/runs/${id}`)).data;
  }

  async line(runId: number, lineId: number): Promise<PayLineDetail> {
    return (await this.api.get<ApiItem<PayLineDetail>>(`/payroll/runs/${runId}/lines/${lineId}`)).data;
  }

  async create(month: string): Promise<PayRunDetail> {
    return (await this.api.post<ApiItem<PayRunDetail>>('/payroll/runs', { month })).data;
  }

  async recalculate(id: number): Promise<PayRunDetail> {
    return (await this.api.post<ApiItem<PayRunDetail>>(`/payroll/runs/${id}/recalculate`)).data;
  }

  addItem(runId: number, body: { employee_id: number; category: ItemCategory; amount: number; reason: string }): Promise<unknown> {
    return this.api.post(`/payroll/runs/${runId}/items`, body);
  }

  removeItem(itemId: number): Promise<unknown> {
    return this.api.delete(`/payroll/items/${itemId}`);
  }

  decideAdjustment(itemId: number, decision: 'accept' | 'reject', reason: string): Promise<unknown> {
    return this.api.post(`/payroll/items/${itemId}/adjustment-decision`, { decision, reason });
  }

  reviewDeduction(itemId: number, decision: 'approve' | 'modify' | 'waive', amount: number | null, reason: string | null): Promise<unknown> {
    return this.api.post(`/payroll/items/${itemId}/review`, { decision, amount, reason });
  }

  submit(id: number): Promise<unknown> {
    return this.api.post(`/payroll/runs/${id}/submit`);
  }

  returnToDraft(id: number, note: string): Promise<unknown> {
    return this.api.post(`/payroll/runs/${id}/return`, { note });
  }

  approve(id: number): Promise<unknown> {
    return this.api.post(`/payroll/runs/${id}/approve`);
  }

  pay(lineId: number, body: PaymentInput): Promise<unknown> {
    return this.api.post(`/payroll/lines/${lineId}/payment`, body);
  }

  voidPayment(paymentId: number, date: string, reason: string): Promise<unknown> {
    return this.api.post(`/payroll/payments/${paymentId}/void`, { date, reason });
  }

  exportRun(id: number): Promise<Blob> {
    return this.api.blob(`/payroll/runs/${id}/export`);
  }

  shamCash(runId: number, body: { currency: 'SYP' | 'USD'; date: string; skip_missing: boolean }): Promise<{ blob: Blob; docId: string | null }> {
    return this.api.filePost(`/payroll/runs/${runId}/shamcash`, body);
  }

  async shamExports(runId: number): Promise<ShamExport[]> {
    return (await this.api.get<ApiItem<ShamExport[]>>(`/payroll/runs/${runId}/shamcash-exports`)).data;
  }

  shamFile(exportId: number): Promise<Blob> {
    return this.api.blob(`/payroll/shamcash-exports/${exportId}/file`);
  }

  confirmSham(exportId: number, boxId: number): Promise<unknown> {
    return this.api.post(`/payroll/shamcash-exports/${exportId}/confirm`, { box_id: boxId });
  }

  cancelSham(exportId: number): Promise<unknown> {
    return this.api.post(`/payroll/shamcash-exports/${exportId}/cancel`);
  }
}
