import { Directive, inject, signal } from '@angular/core';
import { Session } from '../../core/access/session';
import { Permission } from '../../core/access/accounts';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import {
  AccountType,
  BoxType,
  ContractType,
  Finance,
  formatMoney,
  Money,
  PartyRole,
  ProjectStatus,
  VoucherPurpose,
  VoucherStatus,
  VoucherType,
} from '../../data/finance';
import { Office } from '../../data/office';

export const voucherTypeKey: Record<VoucherType, CopyKey> = {
  receipt: 'vtReceipt',
  payment: 'vtPayment',
  transfer: 'vtTransfer',
  exchange: 'vtExchange',
  expense: 'vtExpense',
  project_billing: 'vtProjectBilling',
  custody_issue: 'vtCustodyIssue',
  custody_settlement: 'vtCustodySettlement',
  custody_return: 'vtCustodyReturn',
  advance_issue: 'vtAdvanceIssue',
  asset_purchase: 'vtAssetPurchase',
  asset_maintenance: 'vtAssetMaintenance',
  asset_disposal: 'vtAssetDisposal',
  count_adjustment: 'vtCountAdjustment',
  opening_balance: 'vtOpeningBalance',
  allocation: 'vtAllocation',
  manual_journal: 'vtManualJournal',
  payroll_accrual: 'vtPayrollAccrual',
  payroll_payment: 'vtPayrollPayment',
};

export const voucherPurposeKey: Record<VoucherPurpose, CopyKey> = {
  client_payment: 'vpClientPayment',
  contractor_payment: 'vpContractorPayment',
  supplier_payment: 'vpSupplierPayment',
  internal_expense: 'vpInternalExpense',
  partner_drawing: 'vpPartnerDrawing',
  partner_deposit: 'vpPartnerDeposit',
  employee_advance: 'vpEmployeeAdvance',
  contractor_advance: 'vpContractorAdvance',
  custody: 'vpCustody',
  debt: 'vpDebt',
  other: 'vpOther',
};

export const voucherStatusKey: Record<VoucherStatus, CopyKey> = {
  draft: 'vsDraft',
  submitted: 'vsSubmitted',
  approved: 'vsApproved',
  rejected: 'vsRejected',
  reversed: 'vsReversed',
};

export const boxTypeKey: Record<BoxType, CopyKey> = {
  cash_safe: 'btCashSafe',
  exchange_office: 'btExchangeOffice',
  bank: 'btBank',
  e_wallet: 'btEWallet',
};

export const boxTypeIcon: Record<BoxType, string> = {
  cash_safe: 'fa-solid fa-vault',
  exchange_office: 'fa-solid fa-money-bill-transfer',
  bank: 'fa-solid fa-building-columns',
  e_wallet: 'fa-solid fa-wallet',
};

export const partyRoleKey: Record<PartyRole, CopyKey> = {
  client: 'prClient',
  supplier: 'prSupplier',
  contractor: 'prContractor',
  employee: 'prEmployee',
  partner: 'prPartner',
  other: 'prOther',
};

export const accountTypeKey: Record<AccountType, CopyKey> = {
  asset: 'atAsset',
  liability: 'atLiability',
  equity: 'atEquity',
  revenue: 'atRevenue',
  expense: 'atExpense',
};

export const projectStatusKey: Record<ProjectStatus, CopyKey> = {
  planned: 'psPlanned',
  active: 'psActive',
  on_hold: 'psOnHold',
  completed: 'psCompleted',
  closed: 'psClosed',
};

export const contractTypeKey: Record<ContractType, CopyKey> = {
  lump_sum: 'ctLumpSum',
  cost_plus: 'ctCostPlus',
  percentage_of_receipts: 'ctPercentageOfReceipts',
  internal: 'ctInternal',
};

/** The status word's colour (design rule): green done, amber waiting, red refused, grey retired or neutral. */
export function tone(status: string | null | undefined): 'ok' | 'wait' | 'late' | 'mute' {
  switch (status) {
    case 'approved':
    case 'active':
    case 'open':
    case 'in_service':
    case 'settled':
    case 'deducted':
    case 'confirmed':
    case 'completed':
      return 'ok';
    case 'draft':
    case 'submitted':
    case 'pending':
    case 'planned':
    case 'on_hold':
    case 'under_maintenance':
    case 'postponed':
    case 'warning':
      return 'wait';
    case 'rejected':
    case 'exceeded':
    case 'lost':
    case 'overdue':
      return 'late';
    default:
      return 'mute';
  }
}

/**
 * The shared base of every finance screen: the store and session, money in each currency's own decimals, the labels
 * of the backend's enums, one way to run a server action (notice on success, the server's own message on failure),
 * and the permission checks the screens share. Finance "own" scope means the account's assigned branches, so actions
 * test `may(...)` (any reach) and the server refuses anything outside those branches.
 */
@Directive()
export abstract class FinanceBase extends Ui {
  protected readonly finance = inject(Finance);
  protected readonly session = inject(Session);
  protected readonly office = inject(Office);
  protected readonly busy = signal(false);
  protected readonly tone = tone;

  /**
   * Employees finance can pick (advance holder, asset holder…): every active HR employee when the account can read
   * HR, plus employees already linked to a finance party (the only source for a finance-only account). `partyId` is
   * 0 until the employee is first used in finance; `finance.employeeParty()` then links them.
   */
  protected financeEmployees(): { id: number; name: string; phone: string; partyId: number }[] {
    const parties = this.finance.parties().filter((party) => party.employee_id);
    const out = new Map<number, { id: number; name: string; phone: string; partyId: number }>();
    for (const item of this.office.employees()) {
      const id = Number(item.id);
      if (!id || item.status === 'ended') continue;
      const party = parties.find((p) => p.employee_id === id);
      out.set(id, { id, name: this.text(item.name), phone: item.phone ?? '', partyId: party?.id ?? 0 });
    }
    for (const party of parties) {
      if (!party.is_active || out.has(party.employee_id!)) continue;
      out.set(party.employee_id!, { id: party.employee_id!, name: party.name, phone: party.phone ?? '', partyId: party.id });
    }
    return [...out.values()].sort((a, b) => a.name.localeCompare(b.name, 'ar'));
  }

  /** Holds the permission at any reach (all branches, or only the assigned ones). */
  protected may(permission: Permission): boolean {
    return this.session.reach(permission) !== null;
  }

  /** "1,250.00 USD" in the currency's own decimals; the currency is an id or a code. */
  protected money(value: Money | null | undefined, currency?: number | string | null): string {
    if (currency === undefined || currency === null || currency === '') return formatMoney(value, 2);
    return this.finance.money(value, currency);
  }

  /** The figure alone, in the currency's decimals, for a column whose header already names the currency. */
  protected figure(value: Money | null | undefined, currency?: number | string | null): string {
    return formatMoney(value, this.finance.decimals(currency ?? null));
  }

  /** A signed balance as "1,000.00 USD · لنا / علينا" (positive = owed to us). */
  protected side(value: Money | null | undefined): CopyKey {
    const n = Number(value ?? 0);
    return n > 0 ? 'finOursSide' : n < 0 ? 'finTheirsSide' : 'finEven';
  }

  protected isNegative(value: Money | null | undefined): boolean {
    return Number(value ?? 0) < 0;
  }

  /** A quantity or percentage: up to three decimals, no padding. */
  protected qty(value: Money | null | undefined): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 3 }).format(Number(value ?? 0) || 0);
  }

  protected positive(value: Money | null | undefined): boolean {
    return Number(value ?? 0) > 0;
  }

  protected negative(value: Money | null | undefined): boolean {
    return Number(value ?? 0) < 0;
  }

  protected voucherTypeLabel(type: VoucherType | string): string {
    return this.t(voucherTypeKey[type as VoucherType] ?? 'vpOther');
  }

  protected purposeLabel(purpose: VoucherPurpose | string | null | undefined): string {
    return purpose ? this.t(voucherPurposeKey[purpose as VoucherPurpose] ?? 'vpOther') : '';
  }

  /** The voucher's type, with its purpose for receipts and payments ("صرف · دفعة مورد"). */
  protected voucherKind(type: VoucherType | string, purpose?: VoucherPurpose | string | null): string {
    const label = this.voucherTypeLabel(type);
    return purpose && purpose !== 'other' ? `${label} · ${this.purposeLabel(purpose)}` : label;
  }

  protected statusLabel(status: VoucherStatus | string): string {
    return this.t(voucherStatusKey[status as VoucherStatus] ?? 'vsDraft');
  }

  protected boxTypeLabel(type: BoxType | string): string {
    return this.t(boxTypeKey[type as BoxType] ?? 'btCashSafe');
  }

  protected roleLabel(role: PartyRole | string): string {
    return this.t(partyRoleKey[role as PartyRole] ?? 'prOther');
  }

  protected accountTypeLabel(type: AccountType | string): string {
    return this.t(accountTypeKey[type as AccountType] ?? 'atAsset');
  }

  protected projectStatusLabel(status: ProjectStatus | string): string {
    return this.t(projectStatusKey[status as ProjectStatus] ?? 'psPlanned');
  }

  protected contractTypeLabel(type: ContractType | string | null | undefined): string {
    return type ? this.t(contractTypeKey[type as ContractType] ?? 'ctInternal') : '';
  }

  /**
   * Runs one server action: shows the success notice (with any non-blocking `warnings` the server returned),
   * or the server's own error wording. Returns the result, or null when it failed.
   */
  protected async act<T>(run: () => Promise<T>, message: CopyKey): Promise<T | null> {
    this.busy.set(true);
    try {
      const result = await run();
      const warnings = (result as { warnings?: string[] } | null)?.warnings ?? [];
      this.office.done(message);
      if (warnings.length) {
        this.office.notice.set({ message, detail: `${this.t(message)} ${warnings.join(' · ')}`, undo: null, error: false });
      }
      return result ?? (true as T);
    } catch (error) {
      this.office.fail(error);
      return null;
    } finally {
      this.busy.set(false);
    }
  }

  /** A local check failed before reaching the server: show it the way a server error is shown. */
  protected warn(message: CopyKey): void {
    this.office.notice.set({ message, detail: '', undo: null, error: true });
  }

  /** Loads quietly: a failed load shows the error notice and leaves the list as it was. */
  protected async quiet(run: () => Promise<unknown>): Promise<void> {
    try {
      await run();
    } catch (error) {
      this.office.fail(error);
    }
  }

  /** Saves a server file (a blob) under its own name. */
  protected saveFile(blob: Blob, fallback: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = (blob as File).name || fallback;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
}
