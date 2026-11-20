import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem, ApiPage } from '../core/api/types';
import { Attachment, BoxType, cleanQuery, FinBox, Money } from './finance';
import { Office } from './office';
import { Text } from './text';

/**
 * Boxes area calls: cash boxes (`/finance/cash-boxes*`), the box statement report (`/finance/reports/box-statement`,
 * T2) and cash counts (`/finance/cash-counts*`). Box lists themselves come from the shared `Finance` store.
 */

/** One currency row on the box sheet. A limit only counts when negatives are allowed. */
export interface BoxCurrencyDraft {
  currency_id: number;
  allow_negative: boolean;
  negative_limit: number | null;
}

export interface BoxDraft {
  name: string;
  type: BoxType;
  branch_id: number;
  custodian_user_id: number | null;
  contact_info: string | null;
  currencies: BoxCurrencyDraft[];
  is_active?: boolean;
}

export interface StatementLine {
  date: string;
  entry_number: string | null;
  voucher_id: number | null;
  voucher_number: string | null;
  voucher_type: string | null;
  description: string | null;
  memo: string | null;
  debit: Money;
  credit: Money;
  balance: Money;
}

export interface StatementBlock {
  currency_id: number;
  code: string;
  opening: Money;
  lines: StatementLine[];
  total_in: Money;
  total_out: Money;
  closing: Money;
}

export interface BoxStatement {
  box: { id: number; name: string; type: BoxType; branch_id: number };
  from: string;
  to: string;
  currencies: StatementBlock[];
}

export type CountStatus = 'draft' | 'submitted' | 'approved' | 'rejected';
export const countStatuses: readonly CountStatus[] = ['draft', 'submitted', 'approved', 'rejected'];

export interface CashCountLine {
  id: number;
  currency_id: number;
  counted_amount: Money;
  /** Captured on submit (and on each new capture); null on a draft. */
  system_balance: Money | null;
  /** counted − system: negative = shortage, positive = overage. */
  difference: Money | null;
  explanation: string | null;
}

export interface CashCount {
  id: number;
  cash_box_id: number;
  branch_id: number;
  count_date: string;
  status: CountStatus;
  counted_by: number;
  notes: string | null;
  submitted_at: string | null;
  approved_by: number | null;
  approved_at: string | null;
  rejected_reason: string | null;
  voucher_id: number | null;
  lines: CashCountLine[];
  attachments?: Attachment[];
  /** The box moved after the balances were captured; approval waits for a new capture. */
  stale?: boolean;
}

export interface CountLineDraft {
  currency_id: number;
  counted_amount: number;
  explanation: string | null;
}

export interface CountDraft {
  cash_box_id?: number;
  count_date: string;
  notes: string | null;
  lines: CountLineDraft[];
}

export interface CountFilters {
  box_id?: number;
  status?: CountStatus | '';
  from?: string;
  to?: string;
}

/**
 * People with a login the signed-in account can see (by `usr_users.id`): the account list when it may manage users,
 * otherwise the employee files that carry a linked login. Used for a box's custodian and a count's counter.
 */
export function loginPeople(office: Office): { userId: number; name: Text }[] {
  const seen = new Map<number, Text>();
  for (const account of office.accounts()) seen.set(account.userId, account.name);
  for (const person of office.employees()) {
    const id = Number(person.userId);
    if (id && !seen.has(id)) seen.set(id, person.name);
  }
  return [...seen].map(([userId, name]) => ({ userId, name }));
}

@Injectable({ providedIn: 'root' })
export class FinBoxesApi {
  private readonly api = inject(Api);

  // ----- Boxes -----

  async createBox(draft: BoxDraft): Promise<FinBox> {
    return (await this.api.post<ApiItem<FinBox>>('/finance/cash-boxes', draft)).data;
  }

  async updateBox(id: number, draft: Partial<BoxDraft>): Promise<FinBox> {
    return (await this.api.patch<ApiItem<FinBox>>(`/finance/cash-boxes/${id}`, draft)).data;
  }

  async deactivateBox(id: number): Promise<FinBox> {
    return (await this.api.post<ApiItem<FinBox>>(`/finance/cash-boxes/${id}/deactivate`)).data;
  }

  async statement(boxId: number, currencyId: number | null, from: string, to: string): Promise<BoxStatement> {
    const query = cleanQuery({ box_id: boxId, currency_id: currencyId, from, to });
    return (await this.api.get<ApiItem<BoxStatement>>('/finance/reports/box-statement', query)).data;
  }

  // ----- Cash counts -----

  counts(filters: CountFilters, page = 1): Promise<ApiPage<CashCount>> {
    return this.api.get('/finance/cash-counts', { ...cleanQuery({ ...filters }), page, per_page: 25 });
  }

  async count(id: number): Promise<CashCount> {
    return (await this.api.get<ApiItem<CashCount>>(`/finance/cash-counts/${id}`)).data;
  }

  async createCount(draft: CountDraft): Promise<CashCount> {
    return (await this.api.post<ApiItem<CashCount>>('/finance/cash-counts', draft)).data;
  }

  async updateCount(id: number, draft: CountDraft): Promise<CashCount> {
    const { cash_box_id: _box, ...rest } = draft;
    return (await this.api.put<ApiItem<CashCount>>(`/finance/cash-counts/${id}`, rest)).data;
  }

  deleteCount(id: number): Promise<unknown> {
    return this.api.delete(`/finance/cash-counts/${id}`);
  }

  async submitCount(id: number): Promise<CashCount> {
    return (await this.api.post<ApiItem<CashCount>>(`/finance/cash-counts/${id}/submit`)).data;
  }

  async resnapshotCount(id: number): Promise<CashCount> {
    return (await this.api.post<ApiItem<CashCount>>(`/finance/cash-counts/${id}/resnapshot`)).data;
  }

  async approveCount(id: number): Promise<CashCount> {
    return (await this.api.post<ApiItem<CashCount>>(`/finance/cash-counts/${id}/approve`)).data;
  }

  async rejectCount(id: number, reason: string): Promise<CashCount> {
    return (await this.api.post<ApiItem<CashCount>>(`/finance/cash-counts/${id}/reject`, { reason })).data;
  }

  async attachToCount(id: number, file: File): Promise<Attachment> {
    const body = new FormData();
    body.append('file', file);
    return (await this.api.post<ApiItem<Attachment>>(`/finance/cash-counts/${id}/attachments`, body)).data;
  }

  countAttachment(id: number, attachmentId: number): Promise<Blob> {
    return this.api.blob(`/finance/cash-counts/${id}/attachments/${attachmentId}`);
  }
}
