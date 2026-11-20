import { computed, inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem, ApiPage } from '../core/api/types';
import { cleanQuery, Finance, Money, Voucher } from './finance';
import { Office } from './office';
import { Text } from './text';

/**
 * Fixed assets (`/api/v1/finance/assets`, phase 9): the register, the asset card, purchase from a box, existing
 * assets as opening balances (one by one or a CSV), custody transfer, maintenance, disposal and the in-service ↔
 * under-maintenance toggle. No depreciation: the book value is the cost until the asset is disposed of.
 */

export type AssetStatus = 'in_service' | 'under_maintenance' | 'sold' | 'scrapped' | 'lost' | 'cancelled';
export const assetStatuses: readonly AssetStatus[] = ['in_service', 'under_maintenance', 'sold', 'scrapped', 'lost', 'cancelled'];
export type ActiveStatus = 'in_service' | 'under_maintenance';
export type DisposalType = 'sale' | 'scrap' | 'loss';
export const disposalTypes: readonly DisposalType[] = ['sale', 'scrap', 'loss'];

export interface AssetPerson {
  id: number;
  name: string;
  employee_no: string | null;
}

/** A voucher in short, as the asset card shows it. A draft's number reads "مسودة #12". */
export interface AssetVoucherRef {
  id: number;
  type: string;
  number: string;
  status: string;
  date: string | null;
  amount: Money | null;
  currency: string | null;
}

export interface AssetCategory {
  id: number;
  parent_id: number | null;
  name: string;
  children_count?: number;
  assets_count?: number;
}

export interface Asset {
  id: number;
  code: string;
  name: string;
  category_id: number | null;
  purchase_date: string | null;
  quantity: Money;
  unit_cost: Money;
  total_cost: Money;
  currency_id: number;
  purchase_voucher_id: number | null;
  current_holder_employee_id: number | null;
  branch_id: number;
  status: AssetStatus;
  serial_no: string | null;
  notes: string | null;
  category: { id: number; name: string | null } | null;
  currency: string | null;
  branch: { id: number; name: string | null } | null;
  holder: AssetPerson | null;
  book_value: Money;
  /** The purchase or opening-balance voucher; a draft purchase shows while it waits. */
  acquisition: AssetVoucherRef | null;
}

export interface AssetCustodyRow {
  id: number;
  transferred_at: string | null;
  from: AssetPerson | null;
  to: AssetPerson | null;
  notes: string | null;
}

export interface AssetMaintenanceRow {
  id: number;
  date: string | null;
  description: string;
  project_id: number | null;
  voucher_id: number | null;
  voucher: AssetVoucherRef | null;
}

export interface AssetDisposalRow {
  id: number;
  type: DisposalType;
  date: string | null;
  sale_amount: Money | null;
  currency: string | null;
  notes: string | null;
  voucher_id: number | null;
  voucher: AssetVoucherRef | null;
}

export interface AssetExpenseLine {
  id: number;
  date: string;
  kind: 'maintenance' | 'operating';
  currency: string;
  amount: Money;
  account: { code: string; name: string };
  project_id: number | null;
  memo: string | null;
  entry_number: string | null;
  voucher_id: number | null;
  voucher_number: string | null;
}

export interface AssetCard extends Asset {
  custody_history: AssetCustodyRow[];
  maintenance: AssetMaintenanceRow[];
  disposals: AssetDisposalRow[];
  expenses: {
    lines: AssetExpenseLine[];
    totals: { operating: Record<string, Money>; maintenance: Record<string, Money> };
  };
}

export interface AssetFilters {
  status?: AssetStatus | '';
  holder_employee_id?: number;
  category_id?: number;
  branch_id?: number;
  search?: string;
  per_page?: number;
}

/** What the purchase and opening sheets send (`currency` is a code). */
export interface AssetDraft {
  name: string;
  category_id: number | null;
  quantity: number;
  unit_cost: number;
  currency: string;
  purchase_date: string | null;
  holder_employee_id: number | null;
  branch_id: number;
  serial_no: string | null;
  notes: string | null;
  // Purchase only
  cash_box_id?: number;
  approve?: boolean;
  reference_no?: string | null;
  // Opening only
  date?: string;
  status?: ActiveStatus;
}

export interface AssetMaintenanceDraft {
  cash_box_id: number;
  currency: string;
  amount: number;
  date: string;
  description: string;
  project_id: number | null;
  reference_no: string | null;
  approve: boolean;
}

export interface AssetDisposalDraft {
  type: DisposalType;
  date: string;
  sale_amount?: number;
  cash_box_id?: number;
  notes: string | null;
  approve: boolean;
}

/** An asset created with its voucher (purchase, opening, maintenance, disposal). */
export interface AssetWithVoucher {
  asset: AssetCard | null;
  voucher: Voucher;
}

export interface AssetImportResult {
  count: number;
  assets: Asset[];
  voucher: Voucher;
}

/** An employee who can hold an asset. */
export interface AssetHolderOption {
  id: number;
  name: Text;
  no: string;
}

/** The CSV headers the import accepts (backend `AssetImportService::HEADERS`). */
export const importColumns: readonly { field: 'name' | 'category' | 'quantity' | 'unit_cost' | 'currency' | 'purchase_date' | 'holder' | 'notes' | 'status' | 'serial_no'; headers: string; required: boolean }[] = [
  { field: 'name', headers: 'name · الاسم · اسم الأصل', required: true },
  { field: 'category', headers: 'category · التصنيف', required: false },
  { field: 'quantity', headers: 'quantity · الكمية · العدد', required: false },
  { field: 'unit_cost', headers: 'unit_cost · سعر الوحدة · التكلفة · السعر', required: true },
  { field: 'currency', headers: 'currency · العملة', required: true },
  { field: 'purchase_date', headers: 'purchase_date · تاريخ الشراء', required: false },
  { field: 'holder', headers: 'holder · المسؤول · الحائز', required: false },
  { field: 'notes', headers: 'notes · ملاحظات · طريقة الدفع', required: false },
  { field: 'status', headers: 'status · الحالة', required: false },
  { field: 'serial_no', headers: 'serial_no · الرقم التسلسلي', required: false },
];

/** Asset register API and the lookups its sheets pick from. */
@Injectable({ providedIn: 'root' })
export class FinAssets {
  private readonly api = inject(Api);
  private readonly finance = inject(Finance);
  private readonly office = inject(Office);

  /**
   * Employees who may receive an asset: the HR list when this account may read it, otherwise the finance parties
   * linked to an employee file (the finance store's `parties`, loaded by the page).
   */
  readonly people = computed<AssetHolderOption[]>(() => {
    const staff = this.office
      .employees()
      .filter((item) => item.status !== 'ended' && Number(item.id) > 0)
      .map((item) => ({ id: Number(item.id), name: item.name, no: item.staffNo }));
    const seen = new Set(staff.map((item) => item.id));
    const fromParties = this.finance
      .parties()
      .filter((item) => item.is_active && item.employee_id && !seen.has(item.employee_id))
      .map((item) => ({ id: item.employee_id as number, name: { ar: item.name, en: item.name }, no: '' }));
    return [...staff, ...fromParties];
  });

  list(filters: AssetFilters, page = 1): Promise<ApiPage<Asset>> {
    return this.api.get('/finance/assets', { ...cleanQuery({ per_page: 25, ...filters }), page });
  }

  /** Every asset the register shows by default (not cancelled), for the figure strip. */
  all(): Promise<Asset[]> {
    return this.api.all<Asset>('/finance/assets', { per_page: 100 });
  }

  async card(id: number): Promise<AssetCard> {
    return (await this.api.get<ApiItem<AssetCard>>(`/finance/assets/${id}`)).data;
  }

  async categories(): Promise<AssetCategory[]> {
    return (await this.api.get<ApiItem<AssetCategory[]>>('/finance/asset-categories')).data;
  }

  async update(id: number, patch: { name: string; category_id: number | null; serial_no: string | null; notes: string | null }): Promise<AssetCard> {
    return (await this.api.patch<ApiItem<AssetCard>>(`/finance/assets/${id}`, patch)).data;
  }

  remove(id: number): Promise<unknown> {
    return this.api.delete(`/finance/assets/${id}`);
  }

  async purchase(draft: AssetDraft): Promise<AssetWithVoucher> {
    return (await this.api.post<ApiItem<AssetWithVoucher>>('/finance/assets/purchase', draft)).data;
  }

  async opening(draft: AssetDraft): Promise<AssetWithVoucher> {
    return (await this.api.post<ApiItem<AssetWithVoucher>>('/finance/assets/opening', draft)).data;
  }

  async import(file: File, date: string, branchId: number): Promise<AssetImportResult> {
    const body = new FormData();
    body.append('file', file);
    body.append('date', date);
    body.append('branch_id', String(branchId));
    return (await this.api.post<ApiItem<AssetImportResult>>('/finance/assets/import', body)).data;
  }

  async transfer(id: number, toEmployeeId: number, date: string, notes: string | null): Promise<AssetCard> {
    return (await this.api.post<ApiItem<AssetCard>>(`/finance/assets/${id}/transfer`, { to_employee_id: toEmployeeId, date, notes })).data;
  }

  async setStatus(id: number, status: ActiveStatus): Promise<AssetCard> {
    return (await this.api.post<ApiItem<AssetCard>>(`/finance/assets/${id}/status`, { status })).data;
  }

  async maintain(id: number, draft: AssetMaintenanceDraft): Promise<AssetWithVoucher> {
    return (await this.api.post<ApiItem<AssetWithVoucher>>(`/finance/assets/${id}/maintenance`, draft)).data;
  }

  async dispose(id: number, draft: AssetDisposalDraft): Promise<AssetWithVoucher> {
    return (await this.api.post<ApiItem<AssetWithVoucher>>(`/finance/assets/${id}/dispose`, draft)).data;
  }
}

/** Categories flattened in tree order with their path ("سيارات / شاحنات"). */
export function categoryOptions(categories: AssetCategory[]): { id: number; label: string }[] {
  const byParent = new Map<number | null, AssetCategory[]>();
  for (const item of categories) {
    const key = item.parent_id ?? null;
    byParent.set(key, [...(byParent.get(key) ?? []), item]);
  }
  const out: { id: number; label: string }[] = [];
  const seen = new Set<number>();
  const walk = (parent: number | null, path: string): void => {
    for (const item of (byParent.get(parent) ?? []).sort((a, b) => a.name.localeCompare(b.name))) {
      if (seen.has(item.id)) continue;
      seen.add(item.id);
      const label = path ? `${path} / ${item.name}` : item.name;
      out.push({ id: item.id, label });
      walk(item.id, label);
    }
  };
  walk(null, '');
  // Orphans (a parent outside the list) still show.
  for (const item of categories) if (!seen.has(item.id)) out.push({ id: item.id, label: item.name });
  return out;
}

/** The asset accepts operations: active and its acquisition voucher is approved. */
export function operable(asset: Asset): boolean {
  return (asset.status === 'in_service' || asset.status === 'under_maintenance') && asset.acquisition?.status === 'approved';
}

/** Deletable: the purchase was never posted (no linked voucher, or only a draft). */
export function deletable(asset: Asset): boolean {
  return asset.purchase_voucher_id === null && (!asset.acquisition || asset.acquisition.status === 'draft');
}
