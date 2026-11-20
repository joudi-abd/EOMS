import { HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem } from '../core/api/types';
import { cleanQuery, Finance, Money, VoucherFilters } from './finance';

/**
 * What the voucher screens need beyond the shared finance store: counts for the figure strip, and the area data a
 * voucher form picks from on demand (a contractor's open agreements, open custodies, the asset register).
 */

/** An open contractor agreement (`GET /finance/contractor-agreements`), with its running totals. */
export interface AgreementRef {
  id: number;
  project_id: number;
  contractor_party_id: number;
  project_cost_category_id: number | null;
  currency_id: number;
  scope: string | null;
  net_value: Money;
  status: string;
  remaining: Money;
  advances_outstanding: Money;
  category?: { id: number; name: string } | null;
}

/** A custody row (`GET /finance/custodies`). */
export interface CustodyRef {
  id: number;
  holder_party_id: number;
  holder_name: string | null;
  project_id: number | null;
  project_name: string | null;
  currency_id: number;
  currency: string | null;
  branch_id: number | null;
  status: 'open' | 'closed';
  opened_on: string | null;
  balance: Money;
}

export interface AssetRef {
  id: number;
  code: string | null;
  name: string;
  status: string;
}

/** The field errors of a 422 response (`{errors: {field: [message]}}`), or an empty map. */
export function fieldErrors(error: unknown): Record<string, string[]> {
  if (!(error instanceof HttpErrorResponse) || error.status !== 422) return {};
  const errors = (error.error as { errors?: Record<string, unknown> } | null)?.errors ?? {};
  const out: Record<string, string[]> = {};
  for (const [key, value] of Object.entries(errors)) {
    out[key] = (Array.isArray(value) ? value : [value]).filter(
      (item): item is string => typeof item === 'string',
    );
  }
  return out;
}

@Injectable({ providedIn: 'root' })
export class FinVoucherData {
  private readonly api = inject(Api);
  private readonly finance = inject(Finance);
  private assetList: Promise<AssetRef[]> | null = null;

  /** How many vouchers match (one row is asked for; the total comes from the page meta). */
  async count(filters: VoucherFilters): Promise<number> {
    return (await this.finance.vouchers({ ...filters, per_page: 1 })).meta.total;
  }

  /** A contractor's open agreements. Needs `finance.projects.view`. */
  agreements(partyId: number): Promise<AgreementRef[]> {
    return this.api.all<AgreementRef>('/finance/contractor-agreements', {
      party_id: partyId,
      status: 'open',
      per_page: 100,
    });
  }

  async agreement(id: number): Promise<AgreementRef> {
    return (await this.api.get<ApiItem<AgreementRef>>(`/finance/contractor-agreements/${id}`)).data;
  }

  /** Open custodies, of one holder when given. */
  custodies(holderPartyId?: number | null): Promise<CustodyRef[]> {
    return this.api.all<CustodyRef>(
      '/finance/custodies',
      cleanQuery({ status: 'open', holder_party_id: holderPartyId ?? null, per_page: 100 }),
    );
  }

  async custody(id: number): Promise<CustodyRef> {
    return (await this.api.get<ApiItem<CustodyRef>>(`/finance/custodies/${id}`)).data;
  }

  /** The asset register (loaded once). */
  assets(): Promise<AssetRef[]> {
    this.assetList ??= this.api
      .all<AssetRef>('/finance/assets', { per_page: 100 })
      .catch((error: unknown) => {
        this.assetList = null;
        throw error;
      });
    return this.assetList;
  }
}
