import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem, ApiPage } from '../core/api/types';
import { Attachment, cleanQuery, Money } from './finance';
import { Custody, CustodyKind } from './finance-advances';

/**
 * Site expense requests (`/finance/site-requests`, R24/R25) and the supervisor's own custody (`/finance/my-custody`).
 * A supervisor (`finance.site_requests`) submits with the invoice photo and follows theirs; finance
 * (`finance.vouchers`) approves, which posts a custody settlement or a debt to the supervisor, or rejects with a reason.
 */

export type SiteRequestStatus = 'submitted' | 'approved' | 'rejected';
export type PaymentSource = 'custody' | 'own_money';

export interface SiteRequest {
  id: number;
  project_id: number;
  project_cost_category_id: number;
  amount: Money;
  currency_id: number;
  supplier_party_id: number | null;
  description: string;
  payment_source: PaymentSource;
  requested_by: number;
  holder_party_id: number;
  status: SiteRequestStatus;
  branch_id: number | null;
  decided_by: number | null;
  decided_at: string | null;
  rejected_reason: string | null;
  voucher_id: number | null;
  custody_id: number | null;
  created_at: string | null;
  attachments?: Attachment[];
  project_name: string | null;
  cost_category_name: string | null;
  currency_code: string | null;
  holder_name: string | null;
  supplier_name: string | null;
  voucher_number: string | null;
}

export interface SiteRequestFilters {
  status?: SiteRequestStatus | '';
  project_id?: number;
  payment_source?: PaymentSource | '';
  from?: string;
  to?: string;
  per_page?: number;
}

export interface SiteRequestDraft {
  project_id: number;
  project_cost_category_id: number;
  amount: number;
  currency: string;
  supplier_party_id: number | null;
  description: string;
  payment_source: PaymentSource;
  invoice: File;
}

/** "عهدتي": what the signed-in holder carries. `party` is null when the account is not linked to a party. */
export interface MyCustody {
  party: { id: number; name: string } | null;
  custodies: Custody[];
  balances: { currency: string; balance: Money }[];
  movements: {
    voucher_id: number;
    number: string | null;
    kind: CustodyKind;
    custody_id: number;
    voucher_date: string;
    description: string | null;
    currency: string | null;
    amount: Money;
  }[];
}

/** The server checks the file name's extension (jpg, jpeg, png, webp, pdf); a camera shot may arrive without one. */
const invoiceExtensions: Record<string, string> = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'application/pdf': 'pdf' };

function invoiceName(file: File): string {
  if (/\.(jpe?g|png|webp|pdf)$/i.test(file.name)) return file.name;
  const extension = invoiceExtensions[file.type];
  return extension ? `${(file.name || 'invoice').replace(/\.[^.]*$/, '')}.${extension}` : file.name;
}

@Injectable({ providedIn: 'root' })
export class FinSite {
  private readonly api = inject(Api);

  /** Finance inbox, pending first. */
  inbox(filters: SiteRequestFilters, page = 1): Promise<ApiPage<SiteRequest>> {
    return this.api.get('/finance/site-requests', { ...cleanQuery({ ...filters }), page });
  }

  /** "طلباتي": the caller's own requests, pending first. */
  mine(filters: SiteRequestFilters, page = 1): Promise<ApiPage<SiteRequest>> {
    return this.api.get('/finance/site-requests/mine', { ...cleanQuery({ ...filters }), page });
  }

  async request(id: number): Promise<SiteRequest> {
    return (await this.api.get<ApiItem<SiteRequest>>(`/finance/site-requests/${id}`)).data;
  }

  async submit(draft: SiteRequestDraft): Promise<SiteRequest> {
    const body = new FormData();
    body.append('project_id', String(draft.project_id));
    body.append('project_cost_category_id', String(draft.project_cost_category_id));
    body.append('amount', String(draft.amount));
    body.append('currency', draft.currency);
    if (draft.supplier_party_id) body.append('supplier_party_id', String(draft.supplier_party_id));
    body.append('description', draft.description);
    body.append('payment_source', draft.payment_source);
    body.append('invoice', draft.invoice, invoiceName(draft.invoice));
    return (await this.api.post<ApiItem<SiteRequest>>('/finance/site-requests', body)).data;
  }

  attachment(id: number, attachmentId: number): Promise<Blob> {
    return this.api.blob(`/finance/site-requests/${id}/attachments/${attachmentId}`);
  }

  async approve(id: number, date: string): Promise<SiteRequest> {
    return (await this.api.post<ApiItem<SiteRequest>>(`/finance/site-requests/${id}/approve`, cleanQuery({ date }))).data;
  }

  async reject(id: number, reason: string): Promise<SiteRequest> {
    return (await this.api.post<ApiItem<SiteRequest>>(`/finance/site-requests/${id}/reject`, { reason })).data;
  }

  async myCustody(): Promise<MyCustody> {
    return (await this.api.get<ApiItem<MyCustody>>('/finance/my-custody')).data;
  }
}
