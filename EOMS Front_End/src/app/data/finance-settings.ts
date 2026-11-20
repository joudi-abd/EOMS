import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem } from '../core/api/types';
import { AccountType, FinAccount, FinBranch, FinCostCategory, FinCurrency, FinExpenseCategory, Money, Voucher } from './finance';

/**
 * Finance settings (`/finance/setup`, `/settings/*`, `/finance/accounts`, `/finance/system-accounts`, categories,
 * `/payroll/components`, `/finance/periods`): the setup wizard, currencies and the daily bulletin, branches and the
 * accountants' branches, the chart and its system roles, the three category lists, salary components, month closing.
 */

// ---------- Setup wizard ----------

export type SetupKey = 'currencies' | 'branches' | 'chart' | 'boxes' | 'parties' | 'projects' | 'opening_balances' | 'opening_check';

export interface SetupStep {
  step: number;
  key: SetupKey;
  title: string;
  done: boolean;
  missing: string[];
  details: {
    starter_chart_available?: boolean;
    accounts?: number;
    without_rate?: string[];
    start_date?: string | null;
    date?: string | null;
    [key: string]: unknown;
  };
}

export interface SetupStatus {
  steps: SetupStep[];
  done: number;
  total: number;
  completed: boolean;
}

export interface OpeningCheckCurrency {
  currency_id: number;
  currency: string | null;
  total_debit: Money;
  total_credit: Money;
  balanced: boolean;
  opening_balance_equity: Money;
  net_assets: Money;
  other_equity: Money;
  profit_to_date: Money;
  equity_matches: boolean;
}

export interface OpeningCheck {
  date: string | null;
  balanced: boolean;
  equity_matches: boolean;
  currencies: OpeningCheckCurrency[];
}

export type Direction = 'owed_to_us' | 'we_owe';

/** Step 7: one branch's opening balances on the start date. Only the non-empty sections are sent. */
export interface OpeningDraft {
  date: string;
  branch_id?: number | null;
  description?: string | null;
  boxes?: { cash_box_id: number; currency: string; amount: number }[];
  projects?: {
    project_id: number;
    currency?: string | null;
    receipts?: number | null;
    billed?: number | null;
    costs?: { project_cost_category_id: number; amount: number }[];
  }[];
  agreements?: { contractor_agreement_id: number; paid: number }[];
  parties?: { party_id: number; currency: string; direction: Direction; amount: number }[];
  employee_advances?: { employee_id: number; currency: string; remaining: number; installments_count?: number | null; start_month?: string | null }[];
  custodies?: { holder_party_id: number; project_id?: number | null; currency: string; balance: number; opened_on?: string | null }[];
  partners?: { party_id: number; currency: string; direction: Direction; amount: number }[];
}

export interface OpeningResult {
  voucher: Voucher;
  billings: unknown[];
  custody_ids: number[];
  employee_advance_ids: number[];
  check: OpeningCheck;
}

/** A contractor agreement as the list returns it (only what the opening sheet needs). */
export interface AgreementRef {
  id: number;
  project_id: number;
  contractor_party_id: number;
  contractor?: { id: number; name: string } | null;
  scope: string;
  currency_id: number;
  status: string;
}

// ---------- Rates ----------

export interface BulletinRate {
  currency_id: number;
  code: string;
  name: string;
  rate_to_base: Money;
  source: string;
  rate_date: string;
}

export interface Bulletin {
  date: string;
  base: string | null;
  rates: BulletinRate[];
  missing: string[];
}

export interface RateHistoryRow {
  rate_to_base: Money;
  rate_date: string;
  source: string;
}

export type CurrencyDraft = Partial<Omit<FinCurrency, 'id' | 'rounding_step'>> & { rounding_step?: number | null };
export type BranchDraft = Partial<Omit<FinBranch, 'id'>>;

// ---------- Chart ----------

export interface AccountDraft {
  code?: string;
  name?: string;
  type?: AccountType;
  parent_id?: number | null;
  is_postable?: boolean;
  requires_party?: boolean;
  requires_project?: boolean;
  is_active?: boolean;
}

export interface SystemRole {
  role: string;
  label: string;
  account_id: number | null;
  account: { id: number; code: string; name: string; type: AccountType; is_active: boolean } | null;
  unmapped: boolean;
}

export interface SystemRoles {
  roles: SystemRole[];
  unmapped_count: number;
}

// ---------- Categories ----------

export interface ExpenseCategoryDraft {
  name?: string;
  parent_id?: number | null;
  account_id?: number | null;
  is_overhead_allocatable?: boolean;
  is_active?: boolean;
}

export type CostCategoryDraft = Partial<Pick<FinCostCategory, 'name' | 'is_billable' | 'is_active' | 'sort'>>;

export interface AssetCategory {
  id: number;
  parent_id: number | null;
  name: string;
  children_count?: number;
  assets_count?: number;
}

// ---------- Salary components ----------

export type ComponentKind = 'earning' | 'deduction';
export type CalcMethod = 'fixed' | 'percentage';
export type PercentageBase = 'basic_salary' | 'project_fee';

export interface SalaryComponent {
  id: number;
  code: string;
  name: string;
  kind: ComponentKind;
  calc_method: CalcMethod;
  percentage_base: PercentageBase | null;
  percentage: Money | null;
  is_project_linked: boolean;
  is_recurring: boolean;
  expense_account_id: number | null;
  is_system: boolean;
  is_active: boolean;
  sort: number;
}

export interface ComponentDraft {
  code?: string;
  name?: string;
  kind?: ComponentKind;
  calc_method?: CalcMethod;
  percentage_base?: PercentageBase | null;
  percentage?: number | null;
  is_project_linked?: boolean;
  is_recurring?: boolean;
  expense_account_id?: number | null;
  is_active?: boolean;
}

// ---------- Month closing ----------

export interface ChecklistItem {
  key: string;
  label: string;
  level: 'blocker' | 'warning';
  passed: boolean;
  count: number | null;
  messages: string[];
}

export interface ClosingMonth {
  month: string;
  year: number;
  month_number: number;
  status: 'open' | 'closed';
  closed_at: string | null;
  closed_by: number | null;
  reopened_at: string | null;
  reopen_reason: string | null;
  blockers?: number;
  warnings?: number;
  can_close?: boolean;
  checklist?: ChecklistItem[];
}

/** Server calls of the finance settings screen. Lookups that other screens share stay in the `Finance` store. */
@Injectable({ providedIn: 'root' })
export class FinSettingsApi {
  private readonly api = inject(Api);

  private async read<T>(path: string, params: Record<string, string | number> = {}): Promise<T> {
    return (await this.api.get<ApiItem<T>>(path, params)).data;
  }

  private async send<T>(method: 'post' | 'put' | 'patch', path: string, body: unknown = {}): Promise<T> {
    return (await this.api[method]<ApiItem<T>>(path, body)).data;
  }

  // Setup
  setupStatus(): Promise<SetupStatus> {
    return this.read('/finance/setup/status');
  }
  starterChart(): Promise<{ accounts: number }> {
    return this.send('post', '/finance/setup/starter-chart');
  }
  openingBalances(draft: OpeningDraft): Promise<OpeningResult> {
    return this.send('post', '/finance/setup/opening-balances', draft);
  }
  openingCheck(date?: string): Promise<OpeningCheck> {
    return this.read('/finance/setup/opening-check', date ? { date } : {});
  }
  agreements(): Promise<AgreementRef[]> {
    return this.api.all<AgreementRef>('/finance/contractor-agreements', { per_page: 100, status: 'open' });
  }

  // Currencies and rates
  createCurrency(draft: CurrencyDraft): Promise<FinCurrency> {
    return this.send('post', '/settings/currencies', draft);
  }
  updateCurrency(id: number, draft: CurrencyDraft): Promise<FinCurrency> {
    return this.send('patch', `/settings/currencies/${id}`, draft);
  }
  bulletin(date: string): Promise<Bulletin> {
    return this.read('/settings/exchange-rates', { date });
  }
  history(currency: string, limit = 30): Promise<RateHistoryRow[]> {
    return this.read('/settings/exchange-rates/history', { currency, limit });
  }
  saveRates(date: string, rates: Record<string, number>): Promise<Bulletin> {
    return this.send('post', '/settings/exchange-rates', { date, rates });
  }
  copyPrevious(date: string): Promise<Bulletin> {
    return this.send('post', '/settings/exchange-rates/copy-previous', { date });
  }

  // Branches
  createBranch(draft: BranchDraft): Promise<FinBranch> {
    return this.send('post', '/settings/branches', draft);
  }
  updateBranch(id: number, draft: BranchDraft): Promise<FinBranch> {
    return this.send('patch', `/settings/branches/${id}`, draft);
  }
  async userBranches(userId: number): Promise<number[]> {
    return (await this.read<{ branch_ids: number[] }>(`/finance/users/${userId}/branches`)).branch_ids;
  }
  async setUserBranches(userId: number, branchIds: number[]): Promise<number[]> {
    return (await this.send<{ branch_ids: number[] }>('put', `/finance/users/${userId}/branches`, { branch_ids: branchIds })).branch_ids;
  }

  // Chart
  createAccount(draft: AccountDraft): Promise<FinAccount> {
    return this.send('post', '/finance/accounts', draft);
  }
  updateAccount(id: number, draft: AccountDraft): Promise<FinAccount> {
    return this.send('patch', `/finance/accounts/${id}`, draft);
  }
  deactivateAccount(id: number): Promise<FinAccount> {
    return this.send('post', `/finance/accounts/${id}/deactivate`);
  }
  systemAccounts(): Promise<SystemRoles> {
    return this.read('/finance/system-accounts');
  }
  setSystemAccounts(mappings: Record<string, number>): Promise<SystemRoles> {
    return this.send('put', '/finance/system-accounts', { mappings });
  }

  // Categories
  createExpenseCategory(draft: ExpenseCategoryDraft): Promise<FinExpenseCategory> {
    return this.send('post', '/finance/expense-categories', draft);
  }
  updateExpenseCategory(id: number, draft: ExpenseCategoryDraft): Promise<FinExpenseCategory> {
    return this.send('patch', `/finance/expense-categories/${id}`, draft);
  }
  deactivateExpenseCategory(id: number): Promise<FinExpenseCategory> {
    return this.send('post', `/finance/expense-categories/${id}/deactivate`);
  }
  createCostCategory(draft: CostCategoryDraft): Promise<FinCostCategory> {
    return this.send('post', '/finance/project-cost-categories', draft);
  }
  updateCostCategory(id: number, draft: CostCategoryDraft): Promise<FinCostCategory> {
    return this.send('patch', `/finance/project-cost-categories/${id}`, draft);
  }
  assetCategories(): Promise<AssetCategory[]> {
    return this.read('/finance/asset-categories');
  }
  createAssetCategory(draft: { name: string; parent_id?: number | null }): Promise<AssetCategory> {
    return this.send('post', '/finance/asset-categories', draft);
  }
  updateAssetCategory(id: number, draft: { name?: string; parent_id?: number | null }): Promise<AssetCategory> {
    return this.send('patch', `/finance/asset-categories/${id}`, draft);
  }
  deleteAssetCategory(id: number): Promise<unknown> {
    return this.api.delete(`/finance/asset-categories/${id}`);
  }

  // Salary components
  components(): Promise<SalaryComponent[]> {
    return this.read('/payroll/components');
  }
  createComponent(draft: ComponentDraft): Promise<SalaryComponent> {
    return this.send('post', '/payroll/components', draft);
  }
  updateComponent(id: number, draft: ComponentDraft): Promise<SalaryComponent> {
    return this.send('patch', `/payroll/components/${id}`, draft);
  }

  // Month closing
  closing(): Promise<ClosingMonth[]> {
    return this.read('/finance/periods/closing');
  }
  closeMonth(month: string): Promise<ClosingMonth & { warnings: string[] }> {
    return this.send('post', `/finance/periods/${month}/close`);
  }
  reopenMonth(month: string, reason: string): Promise<ClosingMonth> {
    return this.send('post', `/finance/periods/${month}/reopen`, { reason });
  }
}

/** Server wording sometimes names the API route in brackets ("(POST /finance/…)"); people do not need it. */
export function plainMessage(text: string): string {
  return text.replace(/\s*\((?:GET|POST|PUT|PATCH|DELETE)\s[^)]*\)/g, '').trim();
}
