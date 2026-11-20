import { inject, Injectable, signal } from '@angular/core';
import { Api, statusOf } from '../core/api/api';
import { ApiItem, ApiPage } from '../core/api/types';

/**
 * The shared core of the finance module (`/api/v1/finance`, rebuilt to FINANCE_SYSTEM_SPEC): money helpers, the
 * lookups every finance screen picks from (currencies, branches, boxes, accounts, parties, projects, categories), and
 * the voucher API that every money movement goes through. Area-specific calls live in `data/finance-<area>.ts`.
 *
 * Money arrives as decimal strings with up to four places (`"1250.5000"`) and is sent back as numbers or strings;
 * nothing here does arithmetic on floats beyond display and simple sums.
 */

export type Money = string | number;
type Query = Record<string, string | number>;

// ---------- Helpers ----------

/** Drops empty filters (`''`, `0`, `null`, `false`) and sends `true` as 1. */
export function cleanQuery(filters: Record<string, string | number | null | undefined | boolean>): Query {
  const out: Query = {};
  for (const [key, value] of Object.entries(filters)) {
    if (value === null || value === undefined || value === '' || value === 0 || value === false) continue;
    out[key] = value === true ? 1 : value;
  }
  return out;
}

/** A decimal string or number as a number (0 when empty or not a number). Display and sums only. */
export function amount(value: Money | null | undefined): number {
  const parsed = typeof value === 'number' ? value : Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

/** Today in the browser's time zone, `YYYY-MM-DD`. */
export function today(): string {
  const now = new Date();
  return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10);
}

/** The first day of this month, `YYYY-MM-DD`. */
export function monthStart(): string {
  return today().slice(0, 8) + '01';
}

/** This month, `YYYY-MM`. */
export function thisMonth(): string {
  return today().slice(0, 7);
}

const formats = new Map<number, Intl.NumberFormat>();

/** A money value with the currency's own decimals (USD 2, SYP 0). Latin digits in both languages (design rule). */
export function formatMoney(value: Money | null | undefined, decimals = 2): string {
  let format = formats.get(decimals);
  if (!format) {
    format = new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    formats.set(decimals, format);
  }
  return format.format(amount(value));
}

// ---------- Lookups ----------

export interface FinCurrency {
  id: number;
  code: string;
  name: string;
  symbol: string | null;
  decimals: number;
  rounding_step: Money | null;
  is_base: boolean;
  is_payroll_currency: boolean;
  is_active: boolean;
}

export interface FinBranch {
  id: number;
  name: string;
  code: string | null;
  is_head_office: boolean;
  is_active: boolean;
}

export type BoxType = 'cash_safe' | 'exchange_office' | 'bank' | 'e_wallet';
export const boxTypes: readonly BoxType[] = ['cash_safe', 'exchange_office', 'bank', 'e_wallet'];

/** One currency enabled on a box, with its balance and negative-balance setting. */
export interface BoxCurrency {
  currency_id: number;
  code: string;
  balance: Money;
  allow_negative: boolean;
  negative_limit: Money | null;
  /** What can still be paid out; null = no limit. */
  available: Money | null;
  negative: boolean;
  /** 80% of the negative limit is used. */
  near_limit: boolean;
}

export interface FinBox {
  id: number;
  name: string;
  type: BoxType;
  branch_id: number;
  account_id: number;
  account_code?: string | null;
  custodian_user_id: number | null;
  contact_info: string | null;
  is_active: boolean;
  currencies: BoxCurrency[];
}

export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
export const accountTypes: readonly AccountType[] = ['asset', 'liability', 'equity', 'revenue', 'expense'];

export interface FinAccount {
  id: number;
  parent_id: number | null;
  code: string;
  name: string;
  type: AccountType;
  is_postable: boolean;
  requires_party: boolean;
  requires_project: boolean;
  is_system: boolean;
  is_active: boolean;
  /** System roles this account plays (`cash_boxes_parent`, `fx_position`, …). */
  roles?: string[];
  cash_box_id?: number | null;
  children?: FinAccount[];
}

export type PartyRole = 'client' | 'supplier' | 'contractor' | 'employee' | 'partner' | 'other';
export const partyRoles: readonly PartyRole[] = ['client', 'supplier', 'contractor', 'employee', 'partner', 'other'];

export interface FinParty {
  id: number;
  name: string;
  roles: PartyRole[];
  phone: string | null;
  address: string | null;
  notes: string | null;
  employee_id: number | null;
  contractor_tracking_mode: 'agreement' | 'payments_only' | null;
  effective_tracking_mode?: 'agreement' | 'payments_only';
  trade: string | null;
  sham_cash_wallet: string | null;
  sham_cash_name: string | null;
  is_active: boolean;
}

export type ProjectStatus = 'planned' | 'active' | 'on_hold' | 'completed' | 'closed';
export type ContractType = 'lump_sum' | 'cost_plus' | 'percentage_of_receipts' | 'internal';

/** A project with a finance file (`/finance/projects` row). `project_id` is what vouchers carry. */
export interface FinProjectRef {
  id: number;
  project_id: number;
  code: string | null;
  name: string;
  status: ProjectStatus;
  branch_id: number | null;
  client_party_id: number | null;
  client?: { id: number; name: string } | null;
  contract_type: ContractType;
  contract_currency_id: number | null;
  contract_currency: string | null;
  contract_value: Money | null;
  fee_pct: Money | null;
  manager_user_id: number | null;
  start_date: string | null;
  end_date: string | null;
  billing_locked: boolean;
}

export interface FinExpenseCategory {
  id: number;
  parent_id: number | null;
  name: string;
  account_id: number | null;
  account_code?: string | null;
  account_name?: string | null;
  is_overhead_allocatable: boolean;
  is_active: boolean;
  is_leaf?: boolean;
  children?: FinExpenseCategory[];
}

export interface FinCostCategory {
  id: number;
  name: string;
  system_key: string | null;
  is_billable: boolean;
  is_active: boolean;
  sort: number;
}

// ---------- Vouchers ----------

export type VoucherType =
  | 'receipt'
  | 'payment'
  | 'transfer'
  | 'exchange'
  | 'expense'
  | 'project_billing'
  | 'custody_issue'
  | 'custody_settlement'
  | 'custody_return'
  | 'advance_issue'
  | 'asset_purchase'
  | 'asset_maintenance'
  | 'asset_disposal'
  | 'count_adjustment'
  | 'opening_balance'
  | 'allocation'
  | 'manual_journal'
  | 'payroll_accrual'
  | 'payroll_payment';

export const voucherTypes: readonly VoucherType[] = [
  'receipt',
  'payment',
  'transfer',
  'exchange',
  'expense',
  'manual_journal',
  'project_billing',
  'custody_issue',
  'custody_settlement',
  'custody_return',
  'advance_issue',
  'asset_purchase',
  'asset_maintenance',
  'asset_disposal',
  'count_adjustment',
  'opening_balance',
  'allocation',
  'payroll_accrual',
  'payroll_payment',
];

export type VoucherPurpose =
  | 'client_payment'
  | 'contractor_payment'
  | 'supplier_payment'
  | 'internal_expense'
  | 'partner_drawing'
  | 'partner_deposit'
  | 'employee_advance'
  | 'contractor_advance'
  | 'custody'
  | 'debt'
  | 'other';

/** Purposes each direction accepts (backend `PosterRegistry::PURPOSES`). */
export const receiptPurposes: readonly VoucherPurpose[] = ['client_payment', 'partner_deposit', 'debt', 'other'];
export const paymentPurposes: readonly VoucherPurpose[] = [
  'supplier_payment',
  'contractor_payment',
  'contractor_advance',
  'internal_expense',
  'employee_advance',
  'custody',
  'partner_drawing',
  'debt',
  'other',
];

export type VoucherStatus = 'draft' | 'submitted' | 'approved' | 'rejected' | 'reversed';
export const voucherStatuses: readonly VoucherStatus[] = ['draft', 'submitted', 'approved', 'rejected', 'reversed'];

/** Created only by other modules (cash count, payroll, setup, allocation); never typed on the voucher form. */
export const systemVoucherTypes: readonly VoucherType[] = ['count_adjustment', 'payroll_accrual', 'payroll_payment', 'allocation'];

export interface VoucherLine {
  id?: number;
  line_no?: number;
  account_id?: number | null;
  cash_box_id?: number | null;
  expense_category_id?: number | null;
  project_id?: number | null;
  project_cost_category_id?: number | null;
  party_id?: number | null;
  asset_id?: number | null;
  employee_id?: number | null;
  contractor_agreement_id?: number | null;
  custody_id?: number | null;
  currency_id?: number | null;
  currency?: string | null;
  debit?: Money | null;
  credit?: Money | null;
  memo?: string | null;
}

export interface EntryLine {
  id: number;
  line_no: number;
  account_id: number;
  currency_id: number;
  debit: Money;
  credit: Money;
  rate_to_base: Money;
  base_debit: Money;
  base_credit: Money;
  deal_rate: Money | null;
  party_id: number | null;
  project_id: number | null;
  memo: string | null;
}

export interface JournalEntry {
  id: number;
  number: string | null;
  entry_date: string;
  description: string | null;
  status: string;
  lines: EntryLine[];
}

export interface Attachment {
  id: number;
  original_name: string;
  mime: string | null;
  size: number | null;
  created_at: string | null;
}

export interface Voucher {
  id: number;
  type: VoucherType;
  purpose: VoucherPurpose | null;
  number: string | null;
  display_number?: string;
  status: VoucherStatus;
  voucher_date: string;
  branch_id: number | null;
  cash_box_id: number | null;
  currency_id: number | null;
  amount: Money | null;
  counter_cash_box_id: number | null;
  counter_currency_id: number | null;
  counter_amount: Money | null;
  rate: Money | null;
  fee_amount: Money | null;
  fee_currency_id: number | null;
  party_id: number | null;
  project_id: number | null;
  project_cost_category_id: number | null;
  expense_category_id: number | null;
  asset_id: number | null;
  employee_id: number | null;
  contractor_agreement_id: number | null;
  custody_id: number | null;
  account_id: number | null;
  advance_deduction: Money | null;
  classification: 'partner_personal' | 'company_expense' | null;
  debt_direction: 'we_owe' | 'owed_to_us' | null;
  override_reason: string | null;
  description: string | null;
  reference_no: string | null;
  meta: Record<string, unknown> | null;
  version: number;
  rejected_reason?: string | null;
  reversal_reason?: string | null;
  submitted_at?: string | null;
  reversed_at?: string | null;
  journal_entry_id?: number | null;
  reversal_entry_id?: number | null;
  created_by: number | null;
  approved_by: number | null;
  approved_at: string | null;
  created_at: string | null;
  lines?: VoucherLine[];
  attachments?: Attachment[];
  entry?: JournalEntry | null;
  reversal_entry?: JournalEntry | null;
  warnings?: string[];
}

/** What the voucher form posts. Any field the type does not use is left out. */
export type VoucherDraft = Partial<Omit<Voucher, 'id' | 'status' | 'number' | 'lines'>> & {
  type?: VoucherType;
  currency?: string;
  counter_currency?: string;
  fee_currency?: string;
  lines?: VoucherLine[];
  approve?: boolean;
};

export interface VoucherFilters {
  type?: VoucherType | '';
  purpose?: VoucherPurpose | '';
  status?: VoucherStatus | '';
  box_id?: number;
  party_id?: number;
  project_id?: number;
  branch_id?: number;
  from?: string;
  to?: string;
  search?: string;
  per_page?: number;
}

/** Shared finance store. Lookups load once per session and refresh after a change that touches them. */
@Injectable({ providedIn: 'root' })
export class Finance {
  private readonly api = inject(Api);

  readonly currencies = signal<FinCurrency[]>([]);
  readonly branches = signal<FinBranch[]>([]);
  readonly boxes = signal<FinBox[]>([]);
  /** The chart as a tree (roots with `children`). */
  readonly accountTree = signal<FinAccount[]>([]);
  /** The chart flattened in code order. */
  readonly accounts = signal<FinAccount[]>([]);
  readonly parties = signal<FinParty[]>([]);
  readonly projects = signal<FinProjectRef[]>([]);
  readonly expenseCategories = signal<FinExpenseCategory[]>([]);
  readonly costCategories = signal<FinCostCategory[]>([]);
  /** Branches this account works in; null = every branch. */
  readonly myBranches = signal<number[] | null>(null);

  private readonly loaded = new Map<string, Promise<void>>();

  /** Loads a lookup once; `force` reloads it. A 403 leaves it empty without an error. */
  private once(key: string, run: () => Promise<void>, force: boolean): Promise<void> {
    if (!force && this.loaded.has(key)) return this.loaded.get(key)!;
    const job = run().catch((error: unknown) => {
      this.loaded.delete(key);
      if (statusOf(error) === 403) return;
      throw error;
    });
    this.loaded.set(key, job);
    return job;
  }

  loadCurrencies(force = false): Promise<void> {
    return this.once('currencies', async () => this.currencies.set((await this.api.get<ApiItem<FinCurrency[]>>('/settings/currencies')).data), force);
  }

  loadBranches(force = false): Promise<void> {
    return this.once('branches', async () => this.branches.set((await this.api.get<ApiItem<FinBranch[]>>('/settings/branches')).data), force);
  }

  loadBoxes(force = false): Promise<void> {
    return this.once('boxes', async () => this.boxes.set((await this.api.get<ApiItem<FinBox[]>>('/finance/cash-boxes')).data), force);
  }

  loadAccounts(force = false): Promise<void> {
    return this.once(
      'accounts',
      async () => {
        const tree = (await this.api.get<ApiItem<FinAccount[]>>('/finance/accounts')).data;
        this.accountTree.set(tree);
        this.accounts.set(flatten(tree).sort((a, b) => a.code.localeCompare(b.code)));
      },
      force,
    );
  }

  loadParties(force = false): Promise<void> {
    return this.once('parties', async () => this.parties.set(await this.api.all<FinParty>('/finance/parties', { per_page: 100 })), force);
  }

  loadProjects(force = false): Promise<void> {
    return this.once('projects', async () => this.projects.set(await this.api.all<FinProjectRef>('/finance/projects', { per_page: 100 })), force);
  }

  loadExpenseCategories(force = false): Promise<void> {
    return this.once(
      'expenseCategories',
      async () => this.expenseCategories.set((await this.api.get<ApiItem<FinExpenseCategory[]>>('/finance/expense-categories')).data),
      force,
    );
  }

  loadCostCategories(force = false): Promise<void> {
    return this.once(
      'costCategories',
      async () => this.costCategories.set((await this.api.get<ApiItem<FinCostCategory[]>>('/finance/project-cost-categories')).data),
      force,
    );
  }

  loadMyBranches(force = false): Promise<void> {
    return this.once(
      'myBranches',
      async () => this.myBranches.set((await this.api.get<ApiItem<{ branch_ids: number[] | null }>>('/finance/my-branches')).data.branch_ids),
      force,
    );
  }

  // ----- Lookup readers -----

  currency(id: number | null | undefined): FinCurrency | undefined {
    return this.currencies().find((item) => item.id === id);
  }

  currencyByCode(code: string | null | undefined): FinCurrency | undefined {
    return this.currencies().find((item) => item.code === code);
  }

  /** The currency's code, or '' when unknown. */
  code(id: number | null | undefined): string {
    return this.currency(id)?.code ?? '';
  }

  /** Decimal places for a currency id or code (2 when unknown). */
  decimals(currency: number | string | null | undefined): number {
    const found = typeof currency === 'string' ? this.currencyByCode(currency) : this.currency(currency);
    return found?.decimals ?? 2;
  }

  /** "1,250.00 USD" with the currency's own decimals. */
  money(value: Money | null | undefined, currency: number | string | null | undefined): string {
    const code = typeof currency === 'string' ? currency : this.code(currency);
    const figure = formatMoney(value, this.decimals(currency));
    return code ? `${figure} ${code}` : figure;
  }

  activeCurrencies(): FinCurrency[] {
    return this.currencies().filter((item) => item.is_active);
  }

  branchName(id: number | null | undefined): string {
    return this.branches().find((item) => item.id === id)?.name ?? '';
  }

  box(id: number | null | undefined): FinBox | undefined {
    return this.boxes().find((item) => item.id === id);
  }

  boxName(id: number | null | undefined): string {
    return this.box(id)?.name ?? '';
  }

  activeBoxes(): FinBox[] {
    return this.boxes().filter((item) => item.is_active);
  }

  /** Active boxes that hold this currency (id or code). */
  boxesWith(currency: number | string | null | undefined): FinBox[] {
    return this.activeBoxes().filter((box) => box.currencies.some((item) => item.currency_id === currency || item.code === currency));
  }

  account(id: number | null | undefined): FinAccount | undefined {
    return this.accounts().find((item) => item.id === id);
  }

  accountLabel(id: number | null | undefined): string {
    const found = this.account(id);
    return found ? `${found.code} · ${found.name}` : '';
  }

  /** Accounts a voucher line may post to: active, postable, not a cash box or other system account. */
  postableAccounts(): FinAccount[] {
    return this.accounts().filter((item) => item.is_active && item.is_postable && !item.is_system && !item.cash_box_id);
  }

  party(id: number | null | undefined): FinParty | undefined {
    return this.parties().find((item) => item.id === id);
  }

  partyName(id: number | null | undefined): string {
    return this.party(id)?.name ?? '';
  }

  /** Active parties holding one of these roles (all active parties when none is given). */
  partiesWith(...roles: PartyRole[]): FinParty[] {
    return this.parties().filter((item) => item.is_active && (!roles.length || item.roles.some((role) => roles.includes(role))));
  }

  project(projectId: number | null | undefined): FinProjectRef | undefined {
    return this.projects().find((item) => item.project_id === projectId);
  }

  projectName(projectId: number | null | undefined): string {
    const found = this.project(projectId);
    return found ? (found.code ? `${found.code} · ${found.name}` : found.name) : '';
  }

  /** Projects that still take entries (not closed). */
  openProjects(): FinProjectRef[] {
    return this.projects().filter((item) => item.status !== 'closed');
  }

  /** Leaf expense categories (the ones a voucher line uses), flattened with their path. */
  expenseLeaves(): { id: number; label: string }[] {
    const out: { id: number; label: string }[] = [];
    const walk = (nodes: FinExpenseCategory[], path: string): void => {
      for (const node of nodes) {
        if (!node.is_active) continue;
        const label = path ? `${path} / ${node.name}` : node.name;
        if (node.children?.length) walk(node.children, label);
        else out.push({ id: node.id, label });
      }
    };
    walk(this.expenseCategories(), '');
    return out;
  }

  expenseCategoryName(id: number | null | undefined): string {
    const walk = (nodes: FinExpenseCategory[]): string => {
      for (const node of nodes) {
        if (node.id === id) return node.name;
        const inner = walk(node.children ?? []);
        if (inner) return inner;
      }
      return '';
    };
    return walk(this.expenseCategories());
  }

  costCategoryName(id: number | null | undefined): string {
    return this.costCategories().find((item) => item.id === id)?.name ?? '';
  }

  activeCostCategories(): FinCostCategory[] {
    return this.costCategories().filter((item) => item.is_active);
  }

  /**
   * The finance party linked to an HR employee. The backend posts advances, custody and salaries against the
   * employee's party, so the first time an employee is picked in finance the party is created for them (role
   * `employee`, linked by `employee_id`). Needs `finance.vouchers`.
   */
  async employeeParty(employee: { id: number; name: string; phone?: string | null }): Promise<FinParty> {
    const linked = this.parties().find((item) => item.employee_id === employee.id);
    if (linked) return linked;
    const created = (
      await this.api.post<ApiItem<FinParty>>('/finance/parties', {
        name: employee.name,
        roles: ['employee'],
        employee_id: employee.id,
        phone: employee.phone || null,
      })
    ).data;
    this.parties.update((list) => [...list, created]);
    return created;
  }

  // ----- Vouchers -----

  vouchers(filters: VoucherFilters, page = 1): Promise<ApiPage<Voucher>> {
    return this.api.get('/finance/vouchers', { ...cleanQuery({ ...filters }), page });
  }

  async voucher(id: number): Promise<Voucher> {
    return (await this.api.get<ApiItem<Voucher>>(`/finance/vouchers/${id}`)).data;
  }

  async createVoucher(draft: VoucherDraft): Promise<Voucher> {
    return (await this.api.post<ApiItem<Voucher>>('/finance/vouchers', draft)).data;
  }

  async updateVoucher(id: number, version: number, draft: VoucherDraft): Promise<Voucher> {
    const { type: _type, ...rest } = draft;
    return (await this.api.put<ApiItem<Voucher>>(`/finance/vouchers/${id}`, { ...rest, version })).data;
  }

  deleteVoucher(id: number): Promise<unknown> {
    return this.api.delete(`/finance/vouchers/${id}`);
  }

  async submitVoucher(id: number): Promise<Voucher> {
    return (await this.api.post<ApiItem<Voucher>>(`/finance/vouchers/${id}/submit`)).data;
  }

  async approveVoucher(id: number): Promise<Voucher> {
    return (await this.api.post<ApiItem<Voucher>>(`/finance/vouchers/${id}/approve`)).data;
  }

  async rejectVoucher(id: number, reason: string): Promise<Voucher> {
    return (await this.api.post<ApiItem<Voucher>>(`/finance/vouchers/${id}/reject`, { reason })).data;
  }

  async reverseVoucher(id: number, date: string, reason: string): Promise<Voucher> {
    return (await this.api.post<ApiItem<Voucher>>(`/finance/vouchers/${id}/reverse`, { date, reason })).data;
  }

  async attachToVoucher(id: number, file: File): Promise<Attachment> {
    const body = new FormData();
    body.append('file', file);
    return (await this.api.post<ApiItem<Attachment>>(`/finance/vouchers/${id}/attachments`, body)).data;
  }

  voucherAttachment(id: number, attachmentId: number): Promise<Blob> {
    return this.api.blob(`/finance/vouchers/${id}/attachments/${attachmentId}`);
  }

  removeVoucherAttachment(id: number, attachmentId: number): Promise<unknown> {
    return this.api.delete(`/finance/vouchers/${id}/attachments/${attachmentId}`);
  }

  /** The bulletin's cross rate from one currency to another on a day (null when a rate is missing). */
  async suggestRate(from: string, to: string, date: string): Promise<Money | null> {
    return (await this.api.get<ApiItem<{ rate: Money | null }>>('/finance/vouchers/suggest-rate', { from, to, date })).data.rate;
  }
}

function flatten(nodes: FinAccount[]): FinAccount[] {
  return nodes.flatMap((node) => [node, ...flatten(node.children ?? [])]);
}
