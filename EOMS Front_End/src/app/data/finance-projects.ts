import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem, ApiPage } from '../core/api/types';
import { cleanQuery, ContractType, FinProjectRef, Money, ProjectStatus, Voucher, VoucherStatus } from './finance';

/**
 * Project finance (`/finance/projects/*`, budget lines, contractor agreements, billings, statement) and the monthly
 * allocation of payroll and overhead to projects (`/finance/allocations/*`). `projectId` is always the general
 * project's id (`project_id`), never the finance profile's own id.
 */

export const projectStatuses: readonly ProjectStatus[] = ['planned', 'active', 'on_hold', 'completed', 'closed'];
export const contractTypes: readonly ContractType[] = ['lump_sum', 'cost_plus', 'percentage_of_receipts', 'internal'];

/** One row of the projects summary (T8), in the project's contract currency. */
export interface ProjectSummaryRow {
  project_id: number;
  code: string | null;
  name: string;
  status: ProjectStatus;
  branch_id: number | null;
  client: string | null;
  contract_type: ContractType;
  currency: string;
  receipts: Money;
  costs: Money;
  remaining: Money;
  fee_pct: Money | null;
  fee: Money | null;
  revenue: Money;
  profit: Money;
  client_balance: Money;
}

export interface CostByCategory {
  id: number | null;
  name: string;
  system_key: string | null;
  is_billable: boolean;
  amount: Money;
}

/** Profitability as of a date, in the contract currency (§6.2). */
export interface Profitability {
  project_id: number;
  currency: string;
  date: string;
  revenue: Money;
  direct_costs: Money;
  loaded_costs: Money;
  total_costs: Money;
  gross_margin: Money;
  net_margin: Money;
  receipts: Money;
  billed: Money;
  client_balance: Money;
  fee_pct: Money | null;
  fee: Money | null;
  fee_base_costs: Money;
  costs_by_category: CostByCategory[];
}

/** `GET /finance/projects/{id}`: the list row, the general project, and a summary per tab. */
export interface ProjectFile extends FinProjectRef {
  fee_base_category_ids: number[] | null;
  project: { id: number; name: string; notes: string | null; status: ProjectStatus } & Record<string, unknown>;
  summary: {
    profitability: Profitability | { error: string };
    budget: { lines: number; warning: number; exceeded: number };
    agreements: { count: number; open: number };
    custodies: { open: number; balances: { currency: string; balance: Money }[] };
    billings: { approved: number };
  };
}

/** What the project form sends (create or patch). */
export interface ProjectDraft {
  name?: string;
  status?: ProjectStatus;
  branch_id?: number | null;
  manager_user_id?: number | null;
  start_date?: string | null;
  end_date?: string | null;
  notes?: string | null;
  client_party_id?: number | null;
  contract_type?: ContractType;
  contract_currency_id?: number;
  contract_value?: number | null;
  fee_pct?: number | null;
  fee_base_category_ids?: number[] | null;
}

export interface ProjectFilters {
  status?: ProjectStatus | '';
  contract_type?: ContractType | '';
  search?: string;
}

export type BudgetLevel = 'none' | 'warning' | 'exceeded';

export interface BudgetLine {
  id: number;
  project_id: number;
  project_cost_category_id: number;
  currency_id: number;
  budget_amount: Money;
  notes: string | null;
  category: { id: number; name: string; system_key: string | null; is_billable: boolean } | null;
  currency: string | null;
  /** Null when the bulletin has no rate for the line's currency. */
  actual: Money | null;
  remaining: Money | null;
  pct: Money | null;
  level: BudgetLevel | null;
}

export type AgreementStatus = 'open' | 'completed' | 'cancelled';
export const agreementStatuses: readonly AgreementStatus[] = ['open', 'completed', 'cancelled'];

export interface Agreement {
  id: number;
  project_id: number;
  contractor_party_id: number;
  scope: string;
  project_cost_category_id: number | null;
  currency_id: number;
  agreed_value: Money;
  discount: Money;
  net_value: Money;
  status: AgreementStatus;
  notes: string | null;
  contractor?: { id: number; name: string } | null;
  category?: { id: number; name: string } | null;
  gross_paid: Money;
  cash_paid: Money;
  advances_deducted: Money;
  advances_outstanding: Money;
  remaining: Money;
  overrun: boolean;
}

/** A payment or an advance on an agreement's card. */
export interface AgreementMove {
  voucher_id: number;
  number: string | null;
  date: string;
  cash_box_id: number | null;
  cash_box: string | null;
  gross: Money;
  advance_deduction: Money;
  cash: Money;
  description: string | null;
  override_reason: string | null;
}

export interface AgreementCard extends Agreement {
  currency: string | null;
  payments: AgreementMove[];
  advances: AgreementMove[];
}

export interface AgreementDraft {
  project_id?: number;
  contractor_party_id: number;
  scope: string;
  project_cost_category_id?: number | null;
  currency_id: number;
  agreed_value: number;
  discount?: number | null;
  status?: AgreementStatus;
  notes?: string | null;
}

/** The billing suggestion (§6.1); `suggested` is null for lump sum (manual, capped by `max`). */
export interface BillingSuggestion {
  project_id: number;
  contract_type: ContractType;
  currency: string;
  date: string;
  billable_costs: Money;
  fee_base_costs: Money;
  fee_pct: Money | null;
  fee: Money | null;
  receipts: Money;
  already_billed: Money;
  suggested: Money | null;
  max: Money | null;
  available_advances: Money;
}

export interface BillingDraft {
  amount: number;
  voucher_date?: string;
  description?: string | null;
  reference_no?: string | null;
  approve?: boolean;
}

export interface StatementLine {
  date: string;
  number: string | null;
  cash_box?: string | null;
  party?: string | null;
  description: string | null;
  original_amount: Money;
  original_currency: string | null;
  amount: Money;
}

export interface StatementGroup {
  category_id: number | null;
  name: string;
  system_key: string | null;
  is_billable: boolean;
  lines: StatementLine[];
  total: Money;
}

export interface ProjectStatement {
  variant: 'internal' | 'client';
  hide_internal: boolean;
  header: {
    company: string;
    statement_code: string | null;
    project: string | null;
    project_id: number;
    client: string | null;
    date: string;
    currency: string;
    contract_type: ContractType;
    contract_value: Money | null;
    fee_pct?: Money | null;
  };
  incoming: StatementLine[];
  outgoing: StatementGroup[];
  totals: { incoming: Money; outgoing: Money; remaining: Money; fee?: Money | null; remaining_after_fee?: Money | null };
  signatures: string[];
}

// ---------- Allocations ----------

export interface TimeShareEmployee {
  employee_id: number;
  employee_name: string | null;
  total: Money;
  shares: { project_id: number; project_name: string | null; percentage: Money }[];
}

export interface TimeShareMonth {
  year: number;
  month: number;
  locked: boolean;
  employees: TimeShareEmployee[];
  copied?: number;
}

export type AllocationKind = 'payroll' | 'overhead';
export type AllocationMethod = 'time_shares' | 'percentages' | 'manual_amounts' | 'proportional_direct_cost';
export const allocationMethods: Record<AllocationKind, readonly AllocationMethod[]> = {
  payroll: ['time_shares', 'percentages', 'manual_amounts'],
  overhead: ['proportional_direct_cost', 'percentages', 'manual_amounts'],
};

export interface AllocationRow {
  id: number;
  kind: AllocationKind;
  year: number;
  month: number;
  branch_id: number | null;
  method: AllocationMethod;
  status: 'draft' | 'approved' | 'reversed';
  voucher_id: number | null;
  notes: string | null;
  voucher?: { id: number; number: string | null; status: VoucherStatus; voucher_date: string } | null;
}

export interface AllocationResultLine {
  id?: number;
  project_id: number;
  project_name: string | null;
  currency_id: number;
  currency: string | null;
  employee_id: number | null;
  employee_name: string | null;
  percentage: Money | null;
  amount: Money;
}

export interface AllocationDetail extends AllocationRow {
  label: string;
  /** Currency code → amount. */
  totals: Record<string, Money>;
  lines: AllocationResultLine[];
}

export interface CurrencyAmount {
  currency_id: number;
  currency: string | null;
  amount: Money;
}

export interface AllocationPreview {
  kind: AllocationKind;
  year: number;
  month: number;
  branch_id: number;
  method: AllocationMethod;
  pool: CurrencyAmount[];
  pool_base_total: Money;
  lines: AllocationResultLine[];
  allocated: CurrencyAmount[];
  unallocated: CurrencyAmount[];
  warnings: string[];
  existing_allocation_id: number | null;
}

export interface AllocationDraft {
  kind: AllocationKind;
  year: number;
  month: number;
  branch_id?: number | null;
  method: AllocationMethod;
  lines?: { project_id: number; percentage?: number; currency?: string; amount?: number }[];
  notes?: string | null;
}

@Injectable({ providedIn: 'root' })
export class FinProjects {
  private readonly api = inject(Api);

  // ----- Projects -----

  async summary(filters: ProjectFilters): Promise<ProjectSummaryRow[]> {
    return (await this.api.get<ApiItem<ProjectSummaryRow[]>>('/finance/projects/summary', cleanQuery({ ...filters }))).data;
  }

  async project(projectId: number): Promise<ProjectFile> {
    return (await this.api.get<ApiItem<ProjectFile>>(`/finance/projects/${projectId}`)).data;
  }

  async create(draft: ProjectDraft): Promise<FinProjectRef> {
    return (await this.api.post<ApiItem<FinProjectRef>>('/finance/projects', draft)).data;
  }

  async update(projectId: number, draft: ProjectDraft): Promise<FinProjectRef> {
    return (await this.api.patch<ApiItem<FinProjectRef>>(`/finance/projects/${projectId}`, draft)).data;
  }

  async setStatus(projectId: number, status: ProjectStatus): Promise<FinProjectRef> {
    return (await this.api.post<ApiItem<FinProjectRef>>(`/finance/projects/${projectId}/status`, { status })).data;
  }

  async profitability(projectId: number): Promise<Profitability> {
    return (await this.api.get<ApiItem<Profitability>>(`/finance/projects/${projectId}/profitability`)).data;
  }

  async statement(projectId: number, variant: 'internal' | 'client', hideInternal: boolean): Promise<ProjectStatement> {
    const query = cleanQuery({ variant, hide_internal: variant === 'internal' && hideInternal });
    return (await this.api.get<ApiItem<ProjectStatement>>(`/finance/projects/${projectId}/statement`, query)).data;
  }

  // ----- Budget -----

  async budget(projectId: number): Promise<BudgetLine[]> {
    return (await this.api.get<ApiItem<BudgetLine[]>>(`/finance/projects/${projectId}/budget`)).data;
  }

  addBudgetLine(projectId: number, line: { project_cost_category_id: number; currency_id: number; budget_amount: number; notes?: string | null }): Promise<unknown> {
    return this.api.post(`/finance/projects/${projectId}/budget-lines`, line);
  }

  updateBudgetLine(id: number, line: { budget_amount: number; notes?: string | null }): Promise<unknown> {
    return this.api.patch(`/finance/budget-lines/${id}`, line);
  }

  deleteBudgetLine(id: number): Promise<unknown> {
    return this.api.delete(`/finance/budget-lines/${id}`);
  }

  // ----- Contractor agreements -----

  agreements(projectId: number): Promise<Agreement[]> {
    return this.api.all<Agreement>('/finance/contractor-agreements', { project_id: projectId, per_page: 100 });
  }

  async agreement(id: number): Promise<AgreementCard> {
    return (await this.api.get<ApiItem<AgreementCard>>(`/finance/contractor-agreements/${id}`)).data;
  }

  async createAgreement(draft: AgreementDraft): Promise<AgreementCard> {
    return (await this.api.post<ApiItem<AgreementCard>>('/finance/contractor-agreements', draft)).data;
  }

  async updateAgreement(id: number, draft: Partial<AgreementDraft>): Promise<AgreementCard> {
    return (await this.api.patch<ApiItem<AgreementCard>>(`/finance/contractor-agreements/${id}`, draft)).data;
  }

  deleteAgreement(id: number): Promise<unknown> {
    return this.api.delete(`/finance/contractor-agreements/${id}`);
  }

  // ----- Billings -----

  async suggestion(projectId: number): Promise<BillingSuggestion> {
    return (await this.api.get<ApiItem<BillingSuggestion>>(`/finance/projects/${projectId}/billing-suggestion`)).data;
  }

  async billings(projectId: number): Promise<Voucher[]> {
    return (await this.api.get<ApiItem<Voucher[]>>(`/finance/projects/${projectId}/billings`)).data;
  }

  async bill(projectId: number, draft: BillingDraft): Promise<Voucher> {
    return (await this.api.post<ApiItem<Voucher>>(`/finance/projects/${projectId}/billings`, draft)).data;
  }

  // ----- Time shares and allocations -----

  async timeShares(year: number, month: number): Promise<TimeShareMonth> {
    return (await this.api.get<ApiItem<TimeShareMonth>>('/finance/allocations/time-shares', { year, month })).data;
  }

  async setTimeShares(
    year: number,
    month: number,
    employees: { employee_id: number; shares: { project_id: number; percentage: number }[] }[],
  ): Promise<TimeShareMonth> {
    return (await this.api.put<ApiItem<TimeShareMonth>>('/finance/allocations/time-shares', { year, month, employees })).data;
  }

  async copyTimeShares(year: number, month: number): Promise<TimeShareMonth> {
    return (await this.api.post<ApiItem<TimeShareMonth>>('/finance/allocations/time-shares/copy-previous', { year, month })).data;
  }

  allocations(year: number, month: number, page = 1): Promise<ApiPage<AllocationRow>> {
    return this.api.get('/finance/allocations', { year, month, page, per_page: 25 });
  }

  async allocation(id: number): Promise<AllocationDetail> {
    return (await this.api.get<ApiItem<AllocationDetail>>(`/finance/allocations/${id}`)).data;
  }

  async previewAllocation(draft: AllocationDraft): Promise<AllocationPreview> {
    return (await this.api.post<ApiItem<AllocationPreview>>('/finance/allocations/preview', draft)).data;
  }

  async createAllocation(draft: AllocationDraft): Promise<AllocationDetail & { warnings?: string[] }> {
    return (await this.api.post<ApiItem<AllocationDetail & { warnings?: string[] }>>('/finance/allocations', draft)).data;
  }
}
