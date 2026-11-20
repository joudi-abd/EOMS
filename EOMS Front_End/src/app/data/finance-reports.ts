import { inject, Injectable } from '@angular/core';
import { Permission } from '../core/access/accounts';
import { Api } from '../core/api/api';
import { CopyKey } from '../core/copy';
import { Money, PartyRole } from './finance';

/**
 * Finance reports T1–T23 (`/api/v1/finance/reports/*`): the catalogue the reports page offers, the shapes the server
 * returns (checked against `ReportController` and `Services/Reports/*`), and the two calls: the report as JSON and the
 * same report as the server's CSV (Excel opens it). Every report shares the frame `branch_id, from, to, as_of, currency,
 * view=separate|consolidated, rate_mode, format=csv`, and returns its header (`filters`, and `consolidation` with the
 * consolidated view) at the top level of `data`.
 */

export type ReportGroup = 'cash' | 'accounts' | 'projects' | 'parties' | 'people' | 'costs' | 'control';

/** The filters a report may show. Each maps to one or two backend parameters. */
export type ReportFilter =
  | 'period'
  | 'asOf'
  | 'branch'
  | 'currency'
  | 'box'
  | 'party'
  | 'account'
  | 'project'
  | 'side'
  | 'countStatus'
  | 'projectStatus'
  | 'months'
  | 'variant'
  | 'groupBy'
  | 'search'
  | 'action'
  | 'byBranch';

export type ReportPicker = 'box' | 'party' | 'account' | 'project';

export interface ReportDef {
  /** The backend path under `/finance/reports/`, also the `?report=` key. */
  key: string;
  code: string;
  group: ReportGroup;
  title: CopyKey;
  ask: CopyKey;
  permission: Permission;
  filters: ReportFilter[];
  /** Pickers that must hold a value before the report can run. */
  required?: ReportPicker[];
  /** Offers "each currency apart / consolidated in dollars". */
  view?: boolean;
  /** Paginated (`meta`). */
  paged?: boolean;
  /** The server answers `format=csv` (all but the asset register). */
  csv: boolean;
  /** The party picker offers parties with these roles. */
  roles?: PartyRole[];
  /** The backend name of the one-day filter (`as_of`, or `date` for the project statement). */
  dayParam?: 'as_of' | 'date';
}

export const reportGroups: { key: ReportGroup; title: CopyKey }[] = [
  { key: 'cash', title: 'frpGroupCash' },
  { key: 'accounts', title: 'frpGroupAccounts' },
  { key: 'projects', title: 'frpGroupProjects' },
  { key: 'parties', title: 'frpGroupParties' },
  { key: 'people', title: 'frpGroupPeople' },
  { key: 'costs', title: 'frpGroupCosts' },
  { key: 'control', title: 'frpGroupControl' },
];

export const reportCatalogue: ReportDef[] = [
  // Cash
  { key: 'box-balances', code: 'T1', group: 'cash', title: 'frpBoxBalances', ask: 'frpBoxBalancesAsk', permission: 'finance.reports', filters: ['asOf', 'branch'], view: true, csv: true },
  { key: 'box-statement', code: 'T2', group: 'cash', title: 'frpBoxStatement', ask: 'frpBoxStatementAsk', permission: 'finance.reports', filters: ['box', 'period', 'currency'], required: ['box'], view: true, csv: true },
  { key: 'exchanges', code: 'T21', group: 'cash', title: 'frpExchanges', ask: 'frpExchangesAsk', permission: 'finance.reports', filters: ['period', 'box', 'branch'], csv: true },
  { key: 'cash-counts', code: 'T22', group: 'cash', title: 'frpCashCounts', ask: 'frpCashCountsAsk', permission: 'finance.reports', filters: ['period', 'box', 'countStatus', 'branch'], csv: true },
  // Accounts
  { key: 'journal', code: 'T3', group: 'accounts', title: 'frpJournal', ask: 'frpJournalAsk', permission: 'finance.reports', filters: ['period', 'account', 'party', 'search', 'branch'], paged: true, csv: true },
  { key: 'general-ledger', code: 'T4', group: 'accounts', title: 'frpLedger', ask: 'frpLedgerAsk', permission: 'finance.reports', filters: ['account', 'period', 'currency', 'branch'], required: ['account'], view: true, csv: true },
  { key: 'trial-balance', code: 'T5', group: 'accounts', title: 'frpTrialBalance', ask: 'frpTrialBalanceAsk', permission: 'finance.reports', filters: ['period', 'branch'], view: true, csv: true },
  { key: 'income-statement', code: 'T6', group: 'accounts', title: 'frpIncome', ask: 'frpIncomeAsk', permission: 'finance.reports', filters: ['period', 'branch', 'byBranch'], view: true, csv: true },
  { key: 'balance-sheet', code: 'T7', group: 'accounts', title: 'frpBalanceSheet', ask: 'frpBalanceSheetAsk', permission: 'finance.reports', filters: ['asOf', 'branch'], view: true, csv: true },
  // Projects
  { key: 'projects-summary', code: 'T8', group: 'projects', title: 'frpProjectsSummary', ask: 'frpProjectsSummaryAsk', permission: 'finance.projects.view', filters: ['asOf', 'projectStatus', 'branch'], view: true, csv: true },
  { key: 'project-statement', code: 'T9', group: 'projects', title: 'frpProjectStatement', ask: 'frpProjectStatementAsk', permission: 'finance.projects.view', filters: ['project', 'asOf', 'variant'], required: ['project'], csv: true, dayParam: 'date' },
  { key: 'budget-vs-actual', code: 'T10', group: 'projects', title: 'frpBudget', ask: 'frpBudgetAsk', permission: 'finance.projects.view', filters: ['asOf', 'project', 'projectStatus'], csv: true },
  { key: 'project-profitability', code: 'T11', group: 'projects', title: 'frpProfitability', ask: 'frpProfitabilityAsk', permission: 'finance.projects.view', filters: ['asOf', 'projectStatus', 'branch'], csv: true },
  // Parties
  { key: 'party-statement', code: 'T12', group: 'parties', title: 'frpPartyStatement', ask: 'frpPartyStatementAsk', permission: 'finance.reports', filters: ['party', 'period', 'project', 'currency'], required: ['party'], view: true, csv: true },
  { key: 'contractor-balances', code: 'T13', group: 'parties', title: 'frpContractors', ask: 'frpContractorsAsk', permission: 'finance.reports', filters: ['asOf', 'party', 'branch'], roles: ['contractor'], csv: true },
  { key: 'aging', code: 'T14', group: 'parties', title: 'frpAging', ask: 'frpAgingAsk', permission: 'finance.reports', filters: ['asOf', 'side', 'party', 'branch'], csv: true },
  // Employees and partners
  { key: 'employee-advances', code: 'T15', group: 'people', title: 'frpAdvances', ask: 'frpAdvancesAsk', permission: 'finance.reports', filters: ['months'], csv: true },
  { key: 'open-custodies', code: 'T16', group: 'people', title: 'frpCustodies', ask: 'frpCustodiesAsk', permission: 'finance.reports', filters: [], csv: true },
  { key: 'partners', code: 'T17', group: 'people', title: 'frpPartners', ask: 'frpPartnersAsk', permission: 'finance.reports', filters: ['period', 'party', 'branch'], roles: ['partner'], csv: true },
  { key: 'payroll-cost', code: 'T19', group: 'people', title: 'frpPayroll', ask: 'frpPayrollAsk', permission: 'finance.reports', filters: ['period', 'project', 'branch'], view: true, csv: true },
  // Expenses and assets
  { key: 'expenses-by-category', code: 'T18', group: 'costs', title: 'frpExpenses', ask: 'frpExpensesAsk', permission: 'finance.reports', filters: ['period', 'branch'], view: true, csv: true },
  { key: 'asset-register', code: 'T20', group: 'costs', title: 'frpAssets', ask: 'frpAssetsAsk', permission: 'finance.reports', filters: ['groupBy', 'branch'], csv: false },
  // Control
  { key: 'audit-log', code: 'T23', group: 'control', title: 'frpAudit', ask: 'frpAuditAsk', permission: 'finance.audit', filters: ['period', 'action'], paged: true, csv: true },
];

// ---------- Shared header ----------

export interface RateUsed {
  currency: string;
  rate: Money | null;
  date: string | null;
}

export interface Consolidation {
  base_currency: string;
  rate_mode: 'report_date' | 'historical';
  rate_date: string;
  rates_used: RateUsed[];
}

export interface ReportHead {
  filters?: Record<string, unknown>;
  consolidation?: Consolidation;
}

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

// ---------- Report shapes ----------

/** T1 */
export interface BoxBalancesReport extends ReportHead {
  as_of: string;
  boxes: { id: number; name: string; type: string; branch_id: number; is_active: boolean; balances: { currency_id: number; code: string; balance: Money; negative: boolean; balance_base?: Money }[]; total_base?: Money }[];
  totals: { currency_id: number; code: string; balance: Money; balance_base?: Money }[];
  consolidated?: { total: Money };
}

export interface StatementLine {
  date: string;
  entry_id?: number;
  entry_number: string | null;
  voucher_id: number | null;
  voucher_number: string | null;
  voucher_type: string | null;
  voucher_purpose?: string | null;
  description: string | null;
  memo: string | null;
  party?: string | null;
  account_code?: string | null;
  account_name?: string | null;
  debit: Money;
  credit: Money;
  balance: Money;
}

/** T2 */
export interface BoxStatementReport extends ReportHead {
  box: { id: number; name: string; type: string; branch_id: number };
  from: string;
  to: string;
  currencies: { currency_id: number; code: string; opening: Money; lines: StatementLine[]; total_in: Money; total_out: Money; closing: Money; base?: { opening: Money; total_in: Money; total_out: Money; closing: Money } }[];
  consolidated?: { opening: Money; total_in: Money; total_out: Money; closing: Money };
}

/** T3 */
export interface JournalReport extends ReportHead {
  entries: {
    id: number;
    number: string | null;
    date: string;
    status: string;
    reversal_of_id: number | null;
    description: string | null;
    source_type: string | null;
    source_id: number | null;
    voucher_number: string | null;
    voucher_type: string | null;
    voucher_purpose: string | null;
    lines: { line_no: number; account_code: string | null; account_name: string | null; currency: string | null; debit: Money; credit: Money; party: string | null; memo: string | null }[];
  }[];
  totals: { currency_id: number; code: string; debit: Money; credit: Money }[];
}

export interface BaseFigures {
  opening: Money;
  debit: Money;
  credit: Money;
  closing: Money;
}

/** T4 */
export interface LedgerReport extends ReportHead {
  accounts: {
    account_id: number;
    code: string;
    name: string;
    type: string;
    currencies: { currency_id: number; code: string; opening: Money; lines: StatementLine[]; total_debit: Money; total_credit: Money; closing: Money; base?: BaseFigures }[];
    consolidated?: BaseFigures;
  }[];
}

export interface TrialRow {
  account_id: number;
  code: string;
  name: string;
  type: string;
  opening_debit: Money;
  opening_credit: Money;
  debit: Money;
  credit: Money;
  closing_debit: Money;
  closing_credit: Money;
}

export interface TrialBlock {
  accounts: TrialRow[];
  totals: { opening_debit: Money; opening_credit: Money; debit: Money; credit: Money; closing_debit: Money; closing_credit: Money; balanced: boolean };
}

/** T5 */
export interface TrialBalanceReport extends ReportHead {
  currencies: (TrialBlock & { currency_id: number; code: string })[];
  consolidated?: TrialBlock;
}

export interface AmountRow {
  account_id: number;
  code: string;
  name: string;
  amount: Money;
}

export interface IncomeBlock {
  revenue: AmountRow[];
  total_revenue: Money;
  expenses: AmountRow[];
  total_expenses: Money;
  net_profit: Money;
}

/** T6 */
export interface IncomeReport extends ReportHead {
  currencies: (IncomeBlock & { code: string })[];
  consolidated?: IncomeBlock;
  branches?: { branch_id: number; name: string; currencies: (IncomeBlock & { code: string })[]; consolidated?: IncomeBlock }[];
}

export interface SheetBlock {
  assets: AmountRow[];
  total_assets: Money;
  liabilities: AmountRow[];
  total_liabilities: Money;
  equity: AmountRow[];
  total_equity: Money;
  current_profit: Money;
  total_liabilities_equity_profit: Money;
  difference: Money;
  balanced: boolean;
}

/** T7 */
export interface BalanceSheetReport extends ReportHead {
  as_of: string;
  currencies: (SheetBlock & { code: string })[];
  consolidated?: SheetBlock;
}

export interface ProjectFigures {
  receipts: Money;
  costs: Money;
  remaining: Money;
  fee: Money | null;
  revenue: Money;
  profit: Money;
  client_balance: Money;
}

/** T8 */
export interface ProjectsSummaryReport extends ReportHead {
  date: string;
  projects: (ProjectFigures & { project_id: number; code: string | null; name: string; status: string; client: string | null; contract_type: string; currency: string; fee_pct: Money | null; base?: ProjectFigures })[];
  totals: (ProjectFigures & { currency: string; projects: number })[];
  consolidated?: ProjectFigures;
}

export interface ProjectStatementLine {
  date: string;
  number: string | null;
  cash_box?: string | null;
  party?: string | null;
  description: string | null;
  original_amount: Money;
  original_currency: string | null;
  amount: Money;
}

/** T9 (no frame header) */
export interface ProjectStatementReport extends ReportHead {
  variant: 'internal' | 'client';
  hide_internal: boolean;
  header: { company: string; statement_code: string | null; project: string | null; project_id: number; client: string | null; date: string; currency: string; contract_type: string; contract_value: Money | null; fee_pct?: Money | null };
  incoming: ProjectStatementLine[];
  outgoing: { category_id: number | null; name: string; is_billable: boolean; lines: ProjectStatementLine[]; total: Money }[];
  totals: { incoming: Money; outgoing: Money; remaining: Money; fee?: Money | null; remaining_after_fee?: Money | null };
}

/** T10 */
export interface BudgetReport extends ReportHead {
  date: string;
  lines: { budget_line_id: number; project_id: number; project_code: string | null; project: string; category: string | null; currency: string; budget: Money; actual: Money | null; remaining: Money | null; pct: Money | null; level: 'exceeded' | 'warning' | 'none' | null }[];
  over_budget_projects: number[];
}

/** T11 */
export interface ProfitabilityReport extends ReportHead {
  date: string;
  ranking_basis: Consolidation;
  projects: { rank: number; project_id: number; code: string | null; name: string; status: string; currency: string; revenue: Money; direct_costs: Money; loaded_costs: Money; gross_margin: Money; gross_margin_pct: Money | null; net_margin: Money; net_margin_pct: Money | null; fee: Money | null; net_margin_base: Money; gross_margin_base: Money }[];
  totals: { gross_margin_base: Money; net_margin_base: Money };
}

/** T12 (the party statement's own `filters` replace the frame's) */
export interface PartyStatementReport extends ReportHead {
  party: { id: number; name: string };
  currencies: { currency_id: number; code: string; opening: Money; lines: StatementLine[]; total_debit: Money; total_credit: Money; closing: Money; base?: { opening: Money; closing: Money } }[];
  consolidated?: { opening: Money; closing: Money };
}

/** T13 */
export interface ContractorsReport extends ReportHead {
  as_of: string;
  contractors: { party_id: number; contractor: string; currency: string; agreements: number; open_agreements: number; net_value: Money; paid_on_agreements: Money; paid_without_agreement: Money; remaining: Money; advances_outstanding: Money; payables: Money }[];
  totals: { currency: string; net_value: Money; paid_on_agreements: Money; paid_without_agreement: Money; remaining: Money; advances_outstanding: Money; payables: Money }[];
}

export interface AgingFigures {
  outstanding: Money;
  '0_30': Money;
  '31_60': Money;
  '61_90': Money;
  '90_plus': Money;
  unapplied: Money;
}

/** T14 */
export interface AgingReport extends ReportHead {
  as_of: string;
  rows: (AgingFigures & { side: 'receivable' | 'payable'; party_id: number; party: string | null; currency: string; net: Money; oldest_open_date: string | null })[];
  totals: (AgingFigures & { side: 'receivable' | 'payable'; currency: string })[];
}

/** T15 */
export interface AdvancesReport {
  balances: { employee_id: number; employee_name: string | null; currency: string; advances: number; outstanding: Money; next_due_month: string | null; next_amount: Money }[];
  upcoming: { due_month: string; currency: string; installments: number; amount: Money }[];
}

/** T16 */
export interface CustodiesReport {
  custodies: { id: number; holder_name: string | null; project_name: string | null; currency: string; opened_on: string | null; issued_total: Money; settled_total: Money; returned_total: Money; balance: Money; age_days: number; overdue: boolean }[];
  totals: { currency: string; balance: Money; count: number }[];
  overdue_days: number;
}

/** T17 */
export interface PartnersReport extends ReportHead {
  months: string[];
  partners: { party_id: number; partner: string | null; currency: string; opening: Money; months: { month: string; drawings: Money; deposits: Money; balance: Money }[]; total_drawings: Money; total_deposits: Money; closing: Money }[];
}

export interface CategoryNode {
  category_id: number;
  name: string;
  depth: number;
  is_subtotal: boolean;
  months: Record<string, Money>;
  total: Money;
  children: CategoryNode[];
}

export interface ExpensesBlock {
  categories: CategoryNode[];
  uncategorized: { account_id: number; code: string; name: string; months: Record<string, Money>; total: Money }[];
  month_totals: Record<string, Money>;
  total: Money;
}

/** T18 */
export interface ExpensesReport extends ReportHead {
  months: string[];
  currencies: (ExpensesBlock & { code: string })[];
  consolidated?: ExpensesBlock;
}

export interface PayrollRow {
  employee?: string | null;
  project_id?: number | null;
  project?: string | null;
  component?: string | null;
  currency: string;
  amount: Money;
  amount_base?: Money;
}

/** T19 */
export interface PayrollReport extends ReportHead {
  by_employee: PayrollRow[];
  by_project: PayrollRow[];
  by_component: PayrollRow[];
  totals: PayrollRow[];
  allocated_to_projects: PayrollRow[];
  consolidated?: { total: Money };
}

/** T20 (no frame, no CSV) */
export interface AssetsReport {
  group_by: 'status' | 'holder' | 'category' | 'branch';
  groups: { key: string | number; label: string; count: number; cost: Record<string, Money>; book_value: Record<string, Money>; operating_expenses: Record<string, Money>; maintenance_expenses: Record<string, Money> }[];
  assets: { id: number; code: string; name: string; status: string; currency: string | null; total_cost: Money; book_value: Money; holder: { name: string } | null; branch: { name: string | null } | null; operating_expenses: Record<string, Money>; maintenance_expenses: Record<string, Money> }[];
}

/** T23 rows (`data` is the page itself) */
export interface AuditRow {
  id: number;
  user_id: number | null;
  action: string;
  auditable_type: string | null;
  auditable_id: number | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  created_at: string | null;
}

export type ReportQuery = Record<string, string | number>;

@Injectable({ providedIn: 'root' })
export class FinanceReports {
  private readonly api = inject(Api);

  /** The report as JSON: `data` and, for the paginated ones, `meta`. */
  async run(key: string, query: ReportQuery): Promise<{ data: unknown; meta: PageMeta | null }> {
    const result = await this.api.get<{ data: unknown; meta?: PageMeta }>(`/finance/reports/${key}`, query);
    return { data: result.data, meta: result.meta ?? null };
  }

  /** The same report as the server's CSV (UTF-8 with BOM, every matching row, not just the page). */
  csv(key: string, query: ReportQuery): Promise<Blob> {
    const { page: _page, per_page: _perPage, ...rest } = query;
    return this.api.blob(`/finance/reports/${key}`, { ...rest, format: 'csv' });
  }
}
