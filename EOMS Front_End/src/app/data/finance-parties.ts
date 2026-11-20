import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem, ApiPage } from '../core/api/types';
import { cleanQuery, FinParty, Money, PartyRole } from './finance';

/**
 * Parties and partners (`/finance/parties`, `/finance/partners`, docs `finance/phases/parties-expenses.md` §1–§2).
 * Balances are debit − credit: positive = the party owes us ("لنا"), negative = we owe the party ("علينا").
 */

export type TrackingMode = 'agreement' | 'payments_only';

export interface PartyFilters {
  role?: PartyRole | '';
  search?: string;
  /** true = active only; undefined = every party. */
  active?: boolean;
  employee_id?: number;
}

/** One account behind a party's balance in one currency. */
export interface PartyAccountBalance {
  account_id: number;
  code: string;
  name: string;
  type: string;
  nature: string;
  balance: Money;
  side: string;
}

export interface PartyBalance {
  currency_id: number;
  code: string;
  balance: Money;
  side: string;
  accounts: PartyAccountBalance[];
}

export interface Partner {
  id: number;
  party_id: number;
  share_pct: Money | null;
  joined_at: string | null;
  is_active: boolean;
  party?: { id: number; name: string; phone: string | null; is_active: boolean } | null;
}

/** `GET /parties/{id}`: the record, its roles, the partner row and the balances per currency. */
export interface PartyCard extends FinParty {
  effective_tracking_mode: TrackingMode;
  partner: Partner | null;
  balances: PartyBalance[];
}

export interface StatementLine {
  date: string;
  entry_id: number;
  entry_number: string | null;
  voucher_id: number | null;
  voucher_number: string | null;
  voucher_type: string | null;
  voucher_purpose: string | null;
  account_id: number;
  account_code: string | null;
  account_name: string | null;
  project_id: number | null;
  description: string | null;
  memo: string | null;
  debit: Money;
  credit: Money;
  balance: Money;
  side: string;
}

export interface StatementCurrency {
  currency_id: number;
  code: string;
  opening: Money;
  opening_side: string;
  lines: StatementLine[];
  total_debit: Money;
  total_credit: Money;
  closing: Money;
  closing_side: string;
}

export interface PartyStatement {
  party: { id: number; name: string; phone: string | null; is_active: boolean };
  filters: { currency_id: number | null; project_id: number | null; from: string | null; to: string | null };
  currencies: StatementCurrency[];
}

export interface StatementFilters {
  currency?: string;
  project_id?: number;
  from?: string;
  to?: string;
}

/** What the party form sends (create and update; on update `roles` replaces every role). */
export interface PartyDraft {
  name: string;
  roles: PartyRole[];
  phone: string | null;
  address: string | null;
  notes: string | null;
  employee_id: number | null;
  contractor_tracking_mode: TrackingMode | null;
  trade: string | null;
  sham_cash_wallet: string | null;
  sham_cash_name: string | null;
  is_active?: boolean;
}

export interface ImportResult {
  created: number;
  updated: number;
  skipped: number;
}

export interface PartnerDraft {
  party_id?: number;
  share_pct: number | null;
  joined_at: string | null;
  is_active: boolean;
  /** Other partners' new shares (partner id → share, null clears it), saved in the same call. */
  other_shares?: Record<number, number | null>;
}

@Injectable({ providedIn: 'root' })
export class FinanceParties {
  private readonly api = inject(Api);

  list(filters: PartyFilters, page = 1, perPage = 25): Promise<ApiPage<FinParty>> {
    return this.api.get('/finance/parties', { ...cleanQuery({ ...filters }), page, per_page: perPage });
  }

  async card(id: number): Promise<PartyCard> {
    return (await this.api.get<ApiItem<PartyCard>>(`/finance/parties/${id}`)).data;
  }

  async statement(id: number, filters: StatementFilters): Promise<PartyStatement> {
    return (await this.api.get<ApiItem<PartyStatement>>(`/finance/parties/${id}/statement`, cleanQuery({ ...filters }))).data;
  }

  async create(draft: PartyDraft): Promise<PartyCard> {
    return (await this.api.post<ApiItem<PartyCard>>('/finance/parties', draft)).data;
  }

  async update(id: number, draft: Partial<PartyDraft>): Promise<PartyCard> {
    return (await this.api.patch<ApiItem<PartyCard>>(`/finance/parties/${id}`, draft)).data;
  }

  async deactivate(id: number): Promise<PartyCard> {
    return (await this.api.post<ApiItem<PartyCard>>(`/finance/parties/${id}/deactivate`)).data;
  }

  /** CSV with the header `name,phone,role,trade`; any bad row rejects the whole file (422 listing the rows). */
  async importCsv(file: File): Promise<ImportResult> {
    const body = new FormData();
    body.append('file', file);
    return (await this.api.post<ApiItem<ImportResult>>('/finance/parties/import', body)).data;
  }

  async partners(): Promise<Partner[]> {
    return (await this.api.get<ApiItem<Partner[]>>('/finance/partners')).data;
  }

  async createPartner(draft: PartnerDraft): Promise<Partner> {
    return (await this.api.post<ApiItem<Partner>>('/finance/partners', draft)).data;
  }

  async updatePartner(id: number, draft: PartnerDraft): Promise<Partner> {
    const { party_id: _party, ...rest } = draft;
    return (await this.api.patch<ApiItem<Partner>>(`/finance/partners/${id}`, rest)).data;
  }
}
