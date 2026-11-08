import { inject, Injectable, signal } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem, ApiPage } from '../core/api/types';

/** Shapes returned by `/api/v1/finance`. Money arrives as decimal strings (`"1250.00"`); reports send plain numbers. */

export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
export type BoxKind = 'cash' | 'sham_cash';
export type TransactionKind = 'receipt' | 'payment' | 'transfer' | 'exchange' | 'payroll';
export type TransactionStatus = 'pending' | 'posted' | 'rejected' | 'reversed';
export type InvoiceType = 'sale' | 'purchase';
export type InvoiceStatus = 'draft' | 'pending' | 'posted' | 'rejected' | 'cancelled';
export type PartyKind = 'customer' | 'supplier';
export type PeriodStatus = 'open' | 'close_requested' | 'closed';
export type ReportKind = 'trial-balance' | 'income-statement' | 'balance-sheet' | 'ledger' | 'party' | 'box';
export type Money = string | number;

export interface FinAccount {
  id: number;
  parent_id: number | null;
  code: string;
  name: string;
  type: AccountType;
  system_key: string | null;
  active: boolean;
  is_group: boolean;
  box_id: number | null;
}

export interface FinBox {
  id: number;
  name: string;
  kind: BoxKind;
  currency_code: string;
  account_id: number;
  active: boolean;
  balance: Money;
}

export interface FinTransaction {
  id: number;
  kind: TransactionKind;
  date: string;
  box_id: number;
  amount: Money;
  counter_box_id: number | null;
  counter_amount: Money | null;
  rate: Money | null;
  party_id: number | null;
  invoice_id: number | null;
  account_id: number | null;
  description: string;
  status: TransactionStatus;
  source_type: string | null;
  decision_note: string | null;
  created_by: number;
  approved_by: number | null;
}

export interface FinParty {
  id: number;
  kind: PartyKind;
  name: string;
  phone: string | null;
  notes: string | null;
  active: boolean;
}

export interface FinItem {
  id: number;
  name: string;
  unit: string;
  currency_code: string;
  revenue_account_id: number | null;
  quantity: Money;
  average_cost: Money;
  active: boolean;
  stock_value: number;
}

export interface FinStockMove {
  id: number;
  item_id: number;
  date: string;
  quantity: Money;
  unit_cost: Money;
  quantity_after: Money;
  average_cost_after: Money;
  source_type: string;
  reason: string | null;
}

export interface FinInvoiceLine {
  id?: number;
  item_id: number | null;
  account_id: number | null;
  description: string | null;
  quantity: Money;
  unit_price: Money;
  amount?: Money;
  cost_amount?: Money | null;
}

export interface FinInvoice {
  id: number;
  type: InvoiceType;
  number: string | null;
  party_id: number;
  party_name: string | null;
  date: string;
  due_date: string;
  currency_code: string;
  status: InvoiceStatus;
  total: Money;
  paid: Money;
  remaining: number;
  payment_status: 'paid' | 'partial' | 'unpaid' | null;
  overdue: boolean;
  notes: string | null;
  decision_note: string | null;
  version: number;
  lines?: FinInvoiceLine[];
  payments?: FinTransaction[];
}

export interface FinJournalLine {
  id: number;
  account_id: number;
  party_id: number | null;
  debit: Money;
  credit: Money;
  memo: string | null;
}

export interface FinEntry {
  id: number;
  number: string;
  date: string;
  currency_code: string;
  description: string;
  source_type: string | null;
  reverses_id: number | null;
  lines: FinJournalLine[];
}

export interface FinPeriod {
  id: number;
  month: string;
  status: PeriodStatus;
  requested_by: number | null;
  decided_by: number | null;
  closed_at: string | null;
  note: string | null;
}

export interface FinLimit {
  id: number;
  currency_code: string;
  amount: Money;
}

export interface ReportAccountRow {
  id: number;
  code: string;
  name: string;
  type: AccountType;
  debit: number;
  credit: number;
  balance?: number;
  amount?: number;
}

export interface ReportStatementLine {
  entry_id: number;
  number: string;
  date: string;
  description: string;
  debit: number;
  credit: number;
  memo: string | null;
  source_type: string | null;
  balance: number;
}

/** One union shape for every report; each report fills its own fields. */
export interface FinReport {
  currency?: string;
  from?: string;
  to?: string;
  as_of?: string;
  accounts?: ReportAccountRow[];
  debit?: number;
  credit?: number;
  revenue?: ReportAccountRow[];
  expenses?: ReportAccountRow[];
  total_revenue?: number;
  total_expenses?: number;
  net_profit?: number;
  assets?: ReportAccountRow[];
  liabilities?: ReportAccountRow[];
  equity?: ReportAccountRow[];
  profit_to_date?: number;
  total_assets?: number;
  total_liabilities_and_equity?: number;
  opening_balance?: number;
  closing_balance?: number;
  lines?: ReportStatementLine[];
  open_invoices?: { id: number; number: string; date: string; due_date: string; total: Money; paid: Money; remaining: number; overdue: boolean; days_overdue: number }[];
  overdue_total?: number;
}

export interface TransactionInput {
  date: string;
  box_id: number;
  amount: number;
  description: string;
  counter_box_id?: number;
  counter_amount?: number;
  party_id?: number | null;
  account_id?: number | null;
  invoice_id?: number | null;
}

export interface InvoiceInput {
  type?: InvoiceType;
  party_id: number;
  date: string;
  due_date: string | null;
  currency_code: string;
  notes: string | null;
  version?: number;
  lines: { item_id: number | null; account_id: number | null; description: string | null; quantity: number; unit_price: number }[];
}

export interface EntryInput {
  date: string;
  currency_code: string;
  description: string;
  lines: { account_id: number; debit: number; credit: number; party_id: number | null; memo: string | null }[];
}

type Query = Record<string, string | number>;

/** Drops empty filters so the server never sees `status=` or `box_id=0`. */
export function cleanQuery(filters: Record<string, string | number | null | undefined | boolean>): Query {
  const out: Query = {};
  for (const [key, value] of Object.entries(filters)) {
    if (value === null || value === undefined || value === '' || value === 0 || value === false) continue;
    out[key] = value === true ? 1 : value;
  }
  return out;
}

export function amount(value: Money | null | undefined): number {
  const parsed = typeof value === 'number' ? value : Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

export function today(): string {
  const now = new Date();
  return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10);
}

@Injectable({ providedIn: 'root' })
export class Finance {
  private readonly api = inject(Api);

  /** Lookups shared by every finance tab; loaded once and refreshed after a change. */
  readonly accounts = signal<FinAccount[]>([]);
  readonly boxes = signal<FinBox[]>([]);
  readonly parties = signal<FinParty[]>([]);
  readonly items = signal<FinItem[]>([]);
  readonly limits = signal<FinLimit[]>([]);

  async loadAccounts(): Promise<void> {
    this.accounts.set((await this.api.get<ApiItem<FinAccount[]>>('/finance/accounts')).data);
  }

  async loadBoxes(): Promise<void> {
    this.boxes.set((await this.api.get<ApiItem<FinBox[]>>('/finance/boxes')).data);
  }

  async loadParties(): Promise<void> {
    this.parties.set(await this.api.all<FinParty>('/finance/parties'));
  }

  async loadItems(): Promise<void> {
    this.items.set((await this.api.get<ApiItem<FinItem[]>>('/finance/items')).data);
  }

  async loadLimits(): Promise<void> {
    this.limits.set((await this.api.get<ApiItem<FinLimit[]>>('/finance/approval-limits')).data);
  }

  accountName(id: number | null | undefined): string {
    if (!id) return '—';
    const found = this.accounts().find((item) => item.id === id);
    return found ? `${found.code} · ${found.name}` : `#${id}`;
  }

  boxName(id: number | null | undefined): string {
    if (!id) return '—';
    return this.boxes().find((item) => item.id === id)?.name ?? `#${id}`;
  }

  partyName(id: number | null | undefined): string {
    if (!id) return '—';
    return this.parties().find((item) => item.id === id)?.name ?? `#${id}`;
  }

  /** Accounts that take postings: active and not a group. */
  postable(type?: AccountType): FinAccount[] {
    return this.accounts().filter((item) => item.active && !item.is_group && (!type || item.type === type));
  }

  // Accounts and journal

  createAccount(body: { code: string; name: string; type: AccountType; parent_id: number | null }): Promise<ApiItem<FinAccount>> {
    return this.api.post('/finance/accounts', body);
  }

  updateAccount(id: number, body: Partial<Pick<FinAccount, 'code' | 'name' | 'parent_id' | 'active'>>): Promise<ApiItem<FinAccount>> {
    return this.api.patch(`/finance/accounts/${id}`, body);
  }

  entries(filters: Query, page = 1): Promise<ApiPage<FinEntry>> {
    return this.api.get('/finance/entries', { ...filters, page });
  }

  postEntry(body: EntryInput): Promise<ApiItem<FinEntry>> {
    return this.api.post('/finance/entries', body);
  }

  reverseEntry(id: number, date: string, reason: string): Promise<ApiItem<FinEntry>> {
    return this.api.post(`/finance/entries/${id}/reverse`, { date, reason });
  }

  async periods(): Promise<FinPeriod[]> {
    return (await this.api.get<ApiItem<FinPeriod[]>>('/finance/periods')).data;
  }

  requestClose(month: string): Promise<ApiItem<FinPeriod>> {
    return this.api.post('/finance/periods/close-request', { month });
  }

  decideClose(month: string, decision: 'approve' | 'reject', note: string | null): Promise<ApiItem<FinPeriod>> {
    return this.api.post(`/finance/periods/${month}/decision`, { decision, note });
  }

  reopen(month: string, reason: string): Promise<ApiItem<FinPeriod>> {
    return this.api.post(`/finance/periods/${month}/reopen`, { reason });
  }

  setLimit(currency_code: string, value: number): Promise<ApiItem<FinLimit>> {
    return this.api.put('/finance/approval-limits', { currency_code, amount: value });
  }

  // Boxes and transactions

  createBox(body: { name: string; kind: BoxKind; currency_code: string; parent_account_id: number | null }): Promise<ApiItem<FinBox>> {
    return this.api.post('/finance/boxes', body);
  }

  updateBox(id: number, body: { name?: string; active?: boolean }): Promise<ApiItem<FinBox>> {
    return this.api.patch(`/finance/boxes/${id}`, body);
  }

  transactions(filters: Query, page = 1): Promise<ApiPage<FinTransaction>> {
    return this.api.get('/finance/transactions', { ...filters, page });
  }

  createTransaction(kind: Exclude<TransactionKind, 'payroll'>, body: TransactionInput): Promise<ApiItem<FinTransaction>> {
    return this.api.post(`/finance/transactions/${kind}`, body);
  }

  decideTransaction(id: number, decision: 'approve' | 'reject', note: string | null): Promise<ApiItem<FinTransaction>> {
    return this.api.post(`/finance/transactions/${id}/decision`, { decision, note });
  }

  reverseTransaction(id: number, date: string, reason: string): Promise<ApiItem<FinTransaction>> {
    return this.api.post(`/finance/transactions/${id}/reverse`, { date, reason });
  }

  // Parties, items, invoices

  createParty(body: { kind: PartyKind; name: string; phone: string | null; notes: string | null }): Promise<ApiItem<FinParty>> {
    return this.api.post('/finance/parties', body);
  }

  updateParty(id: number, body: Partial<Pick<FinParty, 'name' | 'phone' | 'notes' | 'active'>>): Promise<ApiItem<FinParty>> {
    return this.api.patch(`/finance/parties/${id}`, body);
  }

  createItem(body: { name: string; unit: string; currency_code: string; revenue_account_id: number | null }): Promise<ApiItem<FinItem>> {
    return this.api.post('/finance/items', body);
  }

  updateItem(id: number, body: Partial<Pick<FinItem, 'name' | 'unit' | 'revenue_account_id' | 'active'>>): Promise<ApiItem<FinItem>> {
    return this.api.patch(`/finance/items/${id}`, body);
  }

  stockMoves(id: number): Promise<ApiPage<FinStockMove>> {
    return this.api.get(`/finance/items/${id}/moves`);
  }

  adjustStock(id: number, body: { date: string; quantity: number; unit_cost: number | null; account_id: number; reason: string }): Promise<unknown> {
    return this.api.post(`/finance/items/${id}/adjust`, body);
  }

  invoices(filters: Query, page = 1): Promise<ApiPage<FinInvoice>> {
    return this.api.get('/finance/invoices', { ...filters, page });
  }

  async invoice(id: number): Promise<FinInvoice> {
    return (await this.api.get<ApiItem<FinInvoice>>(`/finance/invoices/${id}`)).data;
  }

  saveInvoice(body: InvoiceInput, id?: number): Promise<ApiItem<FinInvoice>> {
    return id ? this.api.put(`/finance/invoices/${id}`, body) : this.api.post('/finance/invoices', body);
  }

  submitInvoice(id: number): Promise<ApiItem<FinInvoice>> {
    return this.api.post(`/finance/invoices/${id}/submit`);
  }

  decideInvoice(id: number, decision: 'approve' | 'reject', note: string | null): Promise<ApiItem<FinInvoice>> {
    return this.api.post(`/finance/invoices/${id}/decision`, { decision, note });
  }

  cancelInvoice(id: number, date: string, reason: string): Promise<ApiItem<FinInvoice>> {
    return this.api.post(`/finance/invoices/${id}/cancel`, { date, reason });
  }

  // Reports

  async report(kind: ReportKind, params: Query): Promise<FinReport> {
    return (await this.api.get<ApiItem<FinReport>>(`/finance/reports/${kind}`, params)).data;
  }
}
