import { Component, inject, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CopyKey } from '../../../../core/copy';
import {
  amount,
  FinAccount,
  FinBox,
  FinParty,
  FinProjectRef,
  Money,
  PartyRole,
  paymentPurposes,
  receiptPurposes,
  today,
  Voucher,
  VoucherDraft,
  VoucherLine,
  VoucherPurpose,
  VoucherType,
} from '../../../../data/finance';
import {
  AgreementRef,
  AssetRef,
  CustodyRef,
  fieldErrors,
  FinVoucherData,
} from '../../../../data/finance-vouchers';
import { FinanceBase } from '../../finance-base';
import {
  blankLine,
  filledLines,
  LineRow,
  plainAccounts,
  VoucherLines,
} from '../voucher-lines/voucher-lines';

/** What a deep link (`?new=<type>&purpose&box&party&project&currency&custody&agreement`) asks the form to start with. */
export interface VoucherPrefill {
  type: string;
  purpose: string;
  box: number;
  party: number;
  project: number;
  currency: string;
  custody: number;
  agreement: number;
}

/** The types the segmented choice offers. */
const commonTypes: readonly VoucherType[] = [
  'receipt',
  'payment',
  'transfer',
  'exchange',
  'expense',
  'manual_journal',
];
/** Types the form can fill: the common ones, and those other areas start through a deep link. */
const formTypes: readonly VoucherType[] = [
  ...commonTypes,
  'custody_issue',
  'custody_settlement',
  'custody_return',
  'advance_issue',
  'project_billing',
];

const shortTypeKey: Partial<Record<VoucherType, CopyKey>> = {
  receipt: 'fvoSegReceipt',
  payment: 'fvoSegPayment',
  transfer: 'fvoSegTransfer',
  exchange: 'fvoSegExchange',
  expense: 'fvoSegExpense',
  manual_journal: 'fvoSegJournal',
};

const receiptPurposeKey: Partial<Record<VoucherPurpose, CopyKey>> = {
  client_payment: 'fvoPrClient',
  partner_deposit: 'fvoPrPartner',
  debt: 'fvoPrDebt',
  other: 'fvoPrOther',
};

const paymentPurposeKey: Partial<Record<VoucherPurpose, CopyKey>> = {
  supplier_payment: 'fvoPpSupplier',
  contractor_payment: 'fvoPpContractor',
  contractor_advance: 'fvoPpContractorAdvance',
  internal_expense: 'fvoPpExpense',
  employee_advance: 'fvoPpEmployeeAdvance',
  custody: 'fvoPpCustody',
  partner_drawing: 'fvoPpPartner',
  debt: 'fvoPpDebt',
  other: 'fvoPpOther',
};

function nextMonth(from = today()): string {
  const [year, month] = from.slice(0, 7).split('-').map(Number);
  return month === 12 ? `${year + 1}-01` : `${year}-${String(month + 1).padStart(2, '0')}`;
}

function numberOr(value: Money | null | undefined): number | null {
  return value === null || value === undefined || value === '' ? null : amount(value);
}

/**
 * The voucher working sheet. A short segmented choice of the common types, then (for a receipt or payment) a purpose
 * in plain words, then only the fields that type and purpose need. "حفظ واعتماد" posts at once (`approve: true`);
 * "حفظ كمسودة" keeps a draft. Editing a draft sends its `version`. The server's two questions are asked in place:
 * a custody top-up (`meta.confirm_existing`) and a contractor payment past its agreement (`override_reason`).
 */
@Component({
  selector: 'app-voucher-form',
  imports: [FormsModule, VoucherLines],
  templateUrl: './voucher-form.html',
  styleUrl: './voucher-form.scss',
  host: { class: 'ledger' },
})
export class VoucherForm extends FinanceBase implements OnInit {
  private readonly data = inject(FinVoucherData);

  /** A draft to edit. */
  readonly voucher = input<Voucher | null>(null);
  /** A new voucher's starting values. */
  readonly prefill = input<VoucherPrefill | null>(null);
  readonly saved = output<Voucher>();
  readonly closed = output<void>();

  protected readonly common = commonTypes;

  protected type: VoucherType = 'receipt';
  protected purpose: VoucherPurpose = 'other';
  protected voucherDate = today();
  protected boxId = 0;
  protected currency = '';
  protected amount: number | null = null;
  protected accountId = 0;
  protected partyId = 0;
  /** The HR employee an advance is for (`hr_employees.id`). */
  protected employeeId = 0;
  protected projectId = 0;
  protected costCategoryId = 0;
  protected expenseCategoryId = 0;
  protected debtDirection: 'we_owe' | 'owed_to_us' = 'owed_to_us';
  protected classification: 'partner_personal' | 'company_expense' = 'partner_personal';
  protected partnerAccount: 'partners_current' | 'partners_capital' = 'partners_current';
  protected agreementId = 0;
  protected advanceDeduction: number | null = null;
  protected overrideReason = '';
  protected installments = 1;
  protected startMonth = nextMonth();
  protected custodyId = 0;
  protected toBoxId = 0;
  protected toCurrency = '';
  protected counterAmount: number | null = null;
  protected rate: number | null = null;
  protected feeAmount: number | null = null;
  protected description = '';
  protected reference = '';
  protected branchId = 0;
  protected readonly lines = signal<LineRow[]>([]);

  protected readonly more = signal(false);
  protected readonly agreements = signal<AgreementRef[]>([]);
  protected readonly custodies = signal<CustodyRef[]>([]);
  protected readonly assets = signal<AssetRef[]>([]);
  /** The bulletin's cross rate for the exchange pair, when there is one. */
  protected readonly suggested = signal<Money | null>(null);
  /** R15: the server's question before topping up an open custody. */
  protected readonly confirmMessage = signal('');
  /** R11: the server's question before paying past an agreement's net value. */
  protected readonly overrideMessage = signal('');
  private confirmed = false;
  private lastApprove = true;
  private rateTouched = false;

  /** The form shows once the lookups its defaults depend on have arrived (a deep link may open the page cold). */
  protected readonly ready = signal(false);

  ngOnInit(): void {
    void this.start();
  }

  private async start(): Promise<void> {
    const projects = this.may('finance.projects.view');
    await this.quiet(() =>
      Promise.all([
        this.finance.loadCurrencies(),
        this.finance.loadBoxes(),
        this.finance.loadAccounts(),
        this.finance.loadParties(),
        this.finance.loadExpenseCategories(),
        projects ? this.finance.loadProjects() : undefined,
        projects ? this.finance.loadCostCategories() : undefined,
      ]),
    );
    const voucher = this.voucher();
    if (voucher) this.fromVoucher(voucher);
    else this.fromPrefill(this.prefill());
    this.ready.set(true);
  }

  // ---------- Starting values ----------

  private fromPrefill(start: VoucherPrefill | null): void {
    const type = formTypes.find((item) => item === start?.type) ?? 'receipt';
    this.type = type;
    const purposes = this.purposes();
    this.purpose = purposes.find((item) => item === start?.purpose) ?? 'other';
    this.partyId = start?.party ?? 0;
    this.employeeId = this.finance.party(this.partyId)?.employee_id ?? 0;
    this.projectId = start?.project ?? 0;
    this.custodyId = start?.custody ?? 0;
    this.agreementId = start?.agreement ?? 0;
    this.currency = start?.currency ?? '';
    const boxes = this.finance.activeBoxes();
    this.boxId = start?.box || (boxes.length === 1 ? boxes[0].id : 0);
    if (this.purpose === 'client_payment' || type === 'project_billing')
      this.pickProject(this.projectId);
    this.resetLines();
    this.fitCurrency();
    void this.loadAreaData();
  }

  private fromVoucher(v: Voucher): void {
    const meta = (v.meta ?? {}) as Record<string, unknown>;
    this.type = v.type;
    this.purpose = v.purpose ?? 'other';
    this.voucherDate = v.voucher_date.slice(0, 10);
    this.boxId = v.cash_box_id ?? 0;
    this.currency = this.finance.code(v.currency_id);
    this.amount = numberOr(v.amount);
    this.accountId = v.account_id ?? 0;
    this.partyId = v.party_id ?? 0;
    this.projectId = v.project_id ?? 0;
    this.costCategoryId = v.project_cost_category_id ?? 0;
    this.expenseCategoryId = v.expense_category_id ?? 0;
    this.debtDirection = v.debt_direction ?? 'owed_to_us';
    this.classification = v.classification ?? 'partner_personal';
    this.partnerAccount = this.finance.account(v.account_id)?.roles?.includes('partners_capital')
      ? 'partners_capital'
      : 'partners_current';
    this.agreementId = v.contractor_agreement_id ?? 0;
    this.advanceDeduction = numberOr(v.advance_deduction);
    this.overrideReason = v.override_reason ?? '';
    this.installments = Number(meta['installments_count'] ?? 1) || 1;
    this.startMonth =
      typeof meta['start_month'] === 'string' ? meta['start_month'] : nextMonth(this.voucherDate);
    this.confirmed = meta['confirm_existing'] === true;
    this.custodyId = v.custody_id ?? 0;
    this.toBoxId = v.counter_cash_box_id ?? 0;
    this.toCurrency = this.finance.code(v.counter_currency_id);
    this.counterAmount = numberOr(v.counter_amount);
    this.rate = numberOr(v.rate);
    this.rateTouched = this.rate !== null;
    this.feeAmount = numberOr(v.fee_amount);
    this.description = v.description ?? '';
    this.reference = v.reference_no ?? '';
    this.branchId = v.branch_id ?? 0;
    if (this.employeeAdvance() && v.employee_id) this.employeeId = v.employee_id;
    const rows = (v.lines ?? []).map((line) => ({
      ...blankLine(this.finance.code(line.currency_id) || this.currency),
      expense_category_id: line.expense_category_id ?? 0,
      project_id: line.project_id ?? 0,
      project_cost_category_id: line.project_cost_category_id ?? 0,
      asset_id: line.asset_id ?? 0,
      party_id: line.party_id ?? 0,
      account_id: line.account_id ?? 0,
      debit: amount(line.debit) > 0 ? amount(line.debit) : null,
      credit: amount(line.credit) > 0 ? amount(line.credit) : null,
      memo: line.memo ?? '',
    }));
    if (rows.length) this.lines.set(rows);
    else this.resetLines();
    this.more.set(
      !!(
        this.reference ||
        this.feeAmount ||
        this.advanceDeduction ||
        rows.some((row) => row.asset_id)
      ),
    );
    void this.loadAreaData();
    if (this.type === 'exchange') void this.suggest();
  }

  /** Agreements, custodies and assets, fetched only when the chosen type needs them. */
  private async loadAreaData(): Promise<void> {
    if (this.contractor() && this.partyId) await this.loadAgreements();
    if (this.drawsOnCustody()) await this.loadCustodies();
    if (this.more()) await this.loadAssets();
  }

  private async loadAgreements(): Promise<void> {
    this.agreements.set([]);
    if (!this.partyId || !this.may('finance.projects.view')) return;
    await this.quiet(async () => {
      const rows = await this.data.agreements(this.partyId);
      // A draft keeps an agreement that has since closed: show it so the choice is not silently lost.
      if (this.agreementId && !rows.some((row) => row.id === this.agreementId)) {
        rows.push(await this.data.agreement(this.agreementId));
      }
      this.agreements.set(rows);
      if (!rows.some((row) => row.id === this.agreementId)) this.agreementId = 0;
      this.fitAgreement();
    });
  }

  private async loadCustodies(): Promise<void> {
    await this.quiet(async () => {
      const rows = await this.data.custodies();
      if (this.custodyId && !rows.some((row) => row.id === this.custodyId))
        rows.push(await this.data.custody(this.custodyId));
      this.custodies.set(rows);
      this.pickCustody(this.custodyId);
    });
  }

  private async loadAssets(): Promise<void> {
    if (this.assets().length) return;
    await this.quiet(async () => this.assets.set(await this.data.assets()));
  }

  // ---------- What the chosen type needs ----------

  protected isCash(): boolean {
    return this.type === 'receipt' || this.type === 'payment';
  }

  protected is(purpose: VoucherPurpose): boolean {
    return this.isCash() && this.purpose === purpose;
  }

  /** Types started elsewhere show their name instead of the segmented choice. */
  protected fixedType(): boolean {
    return !!this.voucher() || !commonTypes.includes(this.type);
  }

  protected title(): string {
    if (this.voucher())
      return `${this.t('fvoEditTitle')} · ${this.voucher()!.display_number ?? ''}`;
    return this.fixedType()
      ? `${this.t('fvoNew')} · ${this.voucherTypeLabel(this.type)}`
      : this.t('fvoNew');
  }

  protected segLabel(type: VoucherType): string {
    const key = shortTypeKey[type];
    return key ? this.t(key) : this.voucherTypeLabel(type);
  }

  protected purposes(): readonly VoucherPurpose[] {
    return this.type === 'receipt'
      ? receiptPurposes
      : this.type === 'payment'
        ? paymentPurposes
        : [];
  }

  protected purposeText(purpose: VoucherPurpose): string {
    const key = (this.type === 'receipt' ? receiptPurposeKey : paymentPurposeKey)[purpose];
    return key ? this.t(key) : this.purposeLabel(purpose);
  }

  protected needsBox(): boolean {
    return !['manual_journal', 'project_billing', 'custody_settlement'].includes(this.type);
  }

  protected needsAmount(): boolean {
    return !['manual_journal', 'expense', 'custody_settlement'].includes(this.type);
  }

  protected hasLines(): boolean {
    return ['expense', 'manual_journal', 'custody_settlement'].includes(this.type);
  }

  protected contractor(): boolean {
    return this.is('contractor_payment') || this.is('contractor_advance');
  }

  protected employeeAdvance(): boolean {
    return this.is('employee_advance') || this.type === 'advance_issue';
  }

  protected custodyIssue(): boolean {
    return this.is('custody') || this.type === 'custody_issue';
  }

  protected drawsOnCustody(): boolean {
    return this.type === 'custody_settlement' || this.type === 'custody_return';
  }

  /** The roles the party list offers, or null when this voucher has no party field of its own. */
  private partyRoles(): PartyRole[] | null {
    if (this.is('supplier_payment')) return ['supplier'];
    if (this.contractor()) return ['contractor'];
    if (this.is('partner_deposit') || this.is('partner_drawing')) return ['partner'];
    if (this.is('debt') || this.custodyIssue()) return [];
    return null;
  }

  /** The counter account of an "other" receipt or payment, once chosen. */
  protected otherAccount(): FinAccount | undefined {
    return this.is('other') ? this.finance.account(this.accountId) : undefined;
  }

  /** A party field of its own, or on "other" when the chosen account requires a party. */
  protected showParty(): boolean {
    return this.partyRoles() !== null || !!this.otherAccount()?.requires_party;
  }

  /** A party is optional on "other" and on an internal expense; it sits under the extra details. */
  protected optionalParty(): boolean {
    return this.is('other') || this.is('internal_expense');
  }

  protected partyLabel(): string {
    if (this.is('supplier_payment')) return this.t('fvoSupplier');
    if (this.contractor()) return this.t('fvoContractor');
    if (this.is('partner_deposit') || this.is('partner_drawing')) return this.t('fvoPartner');
    if (this.custodyIssue()) return this.t('fvoHolder');
    return this.t('finParty');
  }

  protected partyOptions(): FinParty[] {
    return this.finance.partiesWith(...(this.partyRoles() ?? []));
  }

  protected employeeOptions(): { id: number; name: string; phone: string; partyId: number }[] {
    return this.financeEmployees();
  }

  protected hasProjects(): boolean {
    return this.finance.projects().length > 0;
  }

  /** Projects that bill a client (for a client payment or a billing). */
  protected clientProjects(): FinProjectRef[] {
    return this.finance
      .openProjects()
      .filter(
        (project) =>
          project.client_party_id &&
          (this.type !== 'project_billing' || project.contract_type !== 'internal'),
      );
  }

  /** A project (optional) for a supplier, contractor without agreement, custody, internal expense or debt. */
  protected showProject(): boolean {
    if (!this.hasProjects()) return false;
    if (this.is('supplier_payment') || this.is('internal_expense') || this.custodyIssue())
      return true;
    if (this.contractor()) return !this.agreementId;
    return !!this.otherAccount()?.requires_project;
  }

  protected showCostCategory(): boolean {
    if (!this.projectId) return false;
    return (
      this.is('supplier_payment') || this.is('internal_expense') || this.is('contractor_payment')
    );
  }

  protected showExpenseCategory(): boolean {
    return (
      (this.is('internal_expense') && !this.projectId) ||
      (this.is('partner_drawing') && this.classification === 'company_expense')
    );
  }

  protected agreementMode(): boolean {
    const party = this.finance.party(this.partyId);
    return (party?.effective_tracking_mode ?? party?.contractor_tracking_mode) === 'agreement';
  }

  protected agreement(): AgreementRef | undefined {
    return this.agreements().find((row) => row.id === this.agreementId);
  }

  protected agreementLabel(row: AgreementRef): string {
    const parts = [this.finance.projectName(row.project_id), row.scope ?? '']
      .filter(Boolean)
      .join(' · ');
    return `${parts} — ${this.t('fvoRemaining')} ${this.money(row.remaining, row.currency_id)}`;
  }

  protected custody(): CustodyRef | undefined {
    return this.custodies().find((row) => row.id === this.custodyId);
  }

  protected custodyLabel(row: CustodyRef): string {
    const where = row.project_name ?? this.t('fvoGeneralCustody');
    return `${row.holder_name ?? ''} · ${where} · ${this.money(row.balance, row.currency ?? row.currency_id)}`;
  }

  protected counterAccounts(): FinAccount[] {
    // The server refuses an account that plays a system role, except the opening-balance equity account.
    return plainAccounts(this.finance.accounts()).filter((item) =>
      (item.roles ?? []).every((role) => role === 'opening_balance_equity'),
    );
  }

  protected accountGroups(): { label: string; accounts: FinAccount[] }[] {
    const all = this.counterAccounts();
    return (['asset', 'liability', 'equity', 'revenue', 'expense'] as const)
      .map((type) => ({
        label: this.accountTypeLabel(type),
        accounts: all.filter((item) => item.type === type),
      }))
      .filter((group) => group.accounts.length);
  }

  protected partnerAccountId(): number {
    return (
      this.finance.accounts().find((item) => (item.roles ?? []).includes(this.partnerAccount))
        ?.id ?? 0
    );
  }

  // ---------- Boxes and currencies ----------

  /** A currency the voucher must use: the agreement's, the custody's, or the project contract's. */
  protected lockedCurrency(): string {
    if (this.contractor() && this.agreement())
      return this.finance.code(this.agreement()!.currency_id);
    if (this.drawsOnCustody() && this.custody())
      return this.custody()!.currency ?? this.finance.code(this.custody()!.currency_id);
    if (this.type === 'project_billing')
      return this.finance.project(this.projectId)?.contract_currency ?? '';
    return '';
  }

  protected boxOptions(): FinBox[] {
    const locked = this.lockedCurrency();
    const boxes = locked ? this.finance.boxesWith(locked) : this.finance.activeBoxes();
    const current = this.finance.box(this.boxId);
    return current && !boxes.includes(current) ? [...boxes, current] : boxes;
  }

  protected currencyOptions(): string[] {
    const box = this.finance.box(this.boxId);
    if (!box) return [];
    let codes = box.currencies.map((item) => item.code);
    if (this.employeeAdvance()) {
      const payroll = this.finance
        .currencies()
        .filter((item) => item.is_payroll_currency && item.is_active)
        .map((item) => item.code);
      codes = codes.filter((code) => payroll.includes(code));
    }
    const locked = this.lockedCurrency();
    if (locked) codes = codes.filter((code) => code === locked);
    return codes;
  }

  /** The chosen box's balance in the chosen currency, as a hint beside the amount. */
  protected boxBalance(): { balance: Money; available: Money | null } | null {
    const row = this.finance
      .box(this.boxId)
      ?.currencies.find((item) => item.code === this.currency);
    return row ? { balance: row.balance, available: row.available } : null;
  }

  protected toBoxOptions(): FinBox[] {
    if (this.type === 'transfer')
      return this.finance.boxesWith(this.currency).filter((box) => box.id !== this.boxId);
    return this.finance.activeBoxes();
  }

  protected toCurrencyOptions(): string[] {
    return (this.finance.box(this.toBoxId)?.currencies ?? [])
      .map((item) => item.code)
      .filter((code) => code !== this.currency);
  }

  /** Keeps the currency one the box holds (and the locked one when there is one). */
  private fitCurrency(): void {
    const options = this.currencyOptions();
    if (!options.includes(this.currency)) {
      // Prefer the base currency (USD) when the box holds it, else the box's first currency.
      const base = this.finance.currencies().find((item) => item.is_base)?.code;
      this.currency = (base && options.includes(base) ? base : options[0]) ?? (this.lockedCurrency() || this.currency);
    }
    for (const row of this.lines())
      if (this.type !== 'manual_journal') row.currency = this.currency;
    if (this.type === 'transfer' && !this.toBoxOptions().some((box) => box.id === this.toBoxId))
      this.toBoxId = 0;
    if (this.type === 'exchange') this.fitToCurrency();
  }

  private fitToCurrency(): void {
    const options = this.toCurrencyOptions();
    if (!options.includes(this.toCurrency)) this.toCurrency = options[0] ?? '';
    void this.suggest();
  }

  private fitAgreement(): void {
    const row = this.agreement();
    if (!row) return;
    this.projectId = 0;
    this.costCategoryId = 0;
    const code = this.finance.code(row.currency_id);
    if (!this.finance.box(this.boxId)?.currencies.some((item) => item.code === code)) {
      const boxes = this.finance.boxesWith(code);
      this.boxId = boxes.length === 1 ? boxes[0].id : 0;
    }
    this.fitCurrency();
  }

  // ---------- Field changes ----------

  protected setType(type: VoucherType): void {
    if (type === this.type) return;
    this.type = type;
    this.purpose = 'other';
    this.clearParty();
    this.resetLines();
    this.fitCurrency();
    if (type === 'exchange') void this.suggest();
  }

  protected setPurpose(purpose: VoucherPurpose): void {
    this.purpose = purpose;
    this.clearParty();
    this.fitCurrency();
  }

  private clearParty(): void {
    this.partyId = 0;
    this.projectId = 0;
    this.costCategoryId = 0;
    this.expenseCategoryId = 0;
    this.agreementId = 0;
    this.agreements.set([]);
    this.confirmMessage.set('');
    this.overrideMessage.set('');
    this.confirmed = false;
  }

  protected setBox(boxId: number): void {
    this.boxId = boxId;
    this.fitCurrency();
  }

  protected setCurrency(code: string): void {
    this.currency = code;
    this.rateTouched = false;
    // A custody top-up was confirmed for one currency; another currency is a different custody.
    this.confirmMessage.set('');
    this.confirmed = false;
    this.fitCurrency();
  }

  protected setParty(partyId: number): void {
    this.partyId = partyId;
    this.confirmMessage.set('');
    this.confirmed = false;
    if (this.contractor()) {
      this.agreementId = 0;
      void this.loadAgreements();
    }
  }

  protected setAgreement(agreementId: number): void {
    this.agreementId = agreementId;
    this.overrideMessage.set('');
    if (agreementId) this.fitAgreement();
    else this.fitCurrency();
  }

  protected setProject(projectId: number): void {
    this.projectId = projectId;
    if (!projectId) this.costCategoryId = 0;
    this.confirmMessage.set('');
    this.confirmed = false;
  }

  /** A client payment or a billing: the project names the client and, for a billing, the currency. */
  protected pickProject(projectId: number): void {
    this.projectId = projectId;
    const project = this.finance.project(projectId);
    this.partyId = project?.client_party_id ?? 0;
    if (this.type === 'project_billing') {
      this.branchId = project?.branch_id ?? 0;
      this.currency = project?.contract_currency ?? '';
    }
  }

  protected pickCustody(custodyId: number): void {
    this.custodyId = custodyId;
    const row = this.custody();
    if (!row) return;
    this.partyId = row.holder_party_id;
    this.branchId = row.branch_id ?? 0;
    this.currency = row.currency ?? this.finance.code(row.currency_id);
    if (
      this.type === 'custody_return' &&
      !this.finance.box(this.boxId)?.currencies.some((item) => item.code === this.currency)
    ) {
      const boxes = this.finance.boxesWith(this.currency);
      this.boxId = boxes.length === 1 ? boxes[0].id : 0;
    }
    for (const line of this.lines()) line.currency = this.currency;
  }

  protected setToBox(boxId: number): void {
    this.toBoxId = boxId;
    if (this.type === 'exchange') this.fitToCurrency();
  }

  protected setToCurrency(code: string): void {
    this.toCurrency = code;
    this.rateTouched = false;
    void this.suggest();
  }

  protected setDate(date: string): void {
    this.voucherDate = date;
    if (this.employeeAdvance() && this.startMonth < date.slice(0, 7))
      this.startMonth = nextMonth(date);
    if (this.type === 'exchange') void this.suggest();
  }

  protected toggleMore(): void {
    this.more.update((open) => !open);
    // The asset column of expense and custody-settlement lines.
    if (this.more() && this.hasLines() && this.type !== 'manual_journal') void this.loadAssets();
  }

  // ---------- Exchange ----------

  /** Prefills the rate from the bulletin (unless the user typed one) and fills the counter amount from it. */
  private async suggest(): Promise<void> {
    this.suggested.set(null);
    if (this.type !== 'exchange' || !this.currency || !this.toCurrency) return;
    const from = this.currency;
    const to = this.toCurrency;
    try {
      const rate = await this.finance.suggestRate(from, to, this.voucherDate);
      if (from !== this.currency || to !== this.toCurrency) return;
      this.suggested.set(rate);
      if (rate !== null && !this.rateTouched) {
        this.rate = Number(Number(rate).toFixed(6));
        this.fromRate();
      }
    } catch {
      // No bulletin to suggest from: the user types the rate.
    }
  }

  protected setAmount(value: number | null): void {
    this.amount = value;
    if (this.type === 'exchange') this.fromRate();
  }

  protected setRate(value: number | null): void {
    this.rate = value;
    this.rateTouched = true;
    this.fromRate();
  }

  protected setCounter(value: number | null): void {
    this.counterAmount = value;
    this.rateTouched = true;
    if (amount(this.amount) > 0 && amount(value) > 0)
      this.rate = Number((amount(value) / amount(this.amount)).toFixed(6));
  }

  private fromRate(): void {
    if (amount(this.amount) > 0 && amount(this.rate) > 0) {
      const decimals = this.finance.decimals(this.toCurrency);
      this.counterAmount = Number((amount(this.amount) * amount(this.rate)).toFixed(decimals));
    }
  }

  /** "1 USD = 32.50 TRY", from what was typed. */
  protected impliedRate(): string {
    if (amount(this.amount) <= 0 || amount(this.counterAmount) <= 0 || !this.toCurrency) return '';
    return `1 ${this.currency} = ${this.qtyRate(amount(this.counterAmount) / amount(this.amount))} ${this.toCurrency}`;
  }

  protected qtyRate(value: Money): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 6 }).format(amount(value));
  }

  // ---------- Lines ----------

  private resetLines(): void {
    if (this.type === 'manual_journal') {
      const base = this.finance.currencies().find((item) => item.is_base)?.code ?? this.currency;
      this.lines.set([blankLine(base), blankLine(base)]);
    } else this.lines.set([blankLine(this.currency)]);
  }

  private linePayload(): VoucherLine[] {
    const id = (value: number) => value || null;
    return filledLines(this.lines()).map((row) => {
      if (this.type === 'manual_journal') {
        return {
          account_id: id(row.account_id),
          currency_id: this.finance.currencyByCode(row.currency)?.id ?? null,
          debit: row.debit ?? 0,
          credit: row.credit ?? 0,
          party_id: id(row.party_id),
          project_id: id(row.project_id),
          memo: row.memo.trim() || null,
        };
      }
      return {
        expense_category_id: id(row.expense_category_id),
        project_id: id(row.project_id),
        project_cost_category_id: row.project_id ? id(row.project_cost_category_id) : null,
        asset_id: id(row.asset_id),
        party_id: id(row.party_id),
        currency_id: this.finance.currencyByCode(this.currency)?.id ?? null,
        debit: row.debit ?? 0,
        memo: row.memo.trim() || null,
      };
    });
  }

  private linesTotal(): number {
    return filledLines(this.lines()).reduce((sum, row) => sum + amount(row.debit), 0);
  }

  private unbalanced(): boolean {
    const sums = new Map<string, number>();
    for (const row of filledLines(this.lines()))
      sums.set(
        row.currency,
        (sums.get(row.currency) ?? 0) + amount(row.debit) - amount(row.credit),
      );
    return [...sums].some(
      ([code, diff]) => Math.abs(diff) >= 10 ** -(this.finance.decimals(code) + 1),
    );
  }

  // ---------- Saving ----------

  /**
   * Every field the server keeps on a voucher, null unless this type uses it: an edited draft keeps whatever is not
   * sent, so a field the new choice no longer uses must be cleared explicitly. Currencies go as ids, because an
   * edited draft's stored `currency_id` wins over a sent code.
   */
  private payload(approve: boolean): VoucherDraft {
    const id = (value: number) => value || null;
    const currencyId = (code: string) =>
      code ? (this.finance.currencyByCode(code)?.id ?? null) : null;
    const draft: VoucherDraft = {
      type: this.type,
      purpose: this.isCash() ? this.purpose : null,
      voucher_date: this.voucherDate,
      branch_id: null,
      cash_box_id: null,
      counter_cash_box_id: null,
      currency_id: null,
      counter_currency_id: null,
      fee_currency_id: null,
      amount: null,
      counter_amount: null,
      fee_amount: null,
      rate: null,
      advance_deduction: null,
      party_id: null,
      project_id: null,
      project_cost_category_id: null,
      expense_category_id: null,
      asset_id: null,
      employee_id: null,
      contractor_agreement_id: null,
      custody_id: null,
      account_id: null,
      classification: null,
      debt_direction: null,
      override_reason: null,
      meta: null,
      description: this.description.trim() || null,
      reference_no: this.reference.trim() || null,
      approve,
    };
    if (this.needsBox()) draft.cash_box_id = id(this.boxId);
    draft.currency_id = currencyId(this.currency);
    if (this.needsAmount()) draft.amount = this.amount;
    if (this.hasLines()) draft.lines = this.linePayload();
    else if (this.voucher()) draft.lines = [];

    if (this.isCash()) {
      if (this.showParty() || (this.optionalParty() && this.partyId))
        draft.party_id = id(this.partyId);
      switch (this.purpose) {
        case 'other':
          draft.account_id = id(this.accountId);
          if (this.otherAccount()?.requires_project) draft.project_id = id(this.projectId);
          break;
        case 'debt':
          draft.debt_direction = this.debtDirection;
          draft.project_id = id(this.projectId);
          break;
        case 'client_payment':
          draft.party_id = id(this.partyId);
          draft.project_id = id(this.projectId);
          break;
        case 'partner_deposit':
          draft.account_id = id(this.partnerAccountId());
          break;
        case 'partner_drawing':
          draft.classification = this.classification;
          if (this.classification === 'company_expense')
            draft.expense_category_id = id(this.expenseCategoryId);
          break;
        case 'supplier_payment':
        case 'internal_expense':
          draft.project_id = id(this.projectId);
          draft.project_cost_category_id = this.projectId ? id(this.costCategoryId) : null;
          if (this.purpose === 'internal_expense')
            draft.expense_category_id = this.projectId ? null : id(this.expenseCategoryId);
          break;
        case 'contractor_payment':
        case 'contractor_advance':
          draft.contractor_agreement_id = id(this.agreementId);
          draft.project_id = this.agreementId ? null : id(this.projectId);
          if (this.purpose === 'contractor_payment') {
            draft.project_cost_category_id = this.agreementId ? null : id(this.costCategoryId);
            draft.advance_deduction = this.advanceDeduction;
            draft.override_reason = this.overrideReason.trim() || null;
          }
          break;
        case 'custody':
          draft.project_id = id(this.projectId);
          break;
      }
    }
    if (this.employeeAdvance()) {
      draft.party_id = id(this.partyId);
      draft.employee_id = id(this.employeeId);
      draft.meta = { installments_count: this.installments, start_month: this.startMonth };
    }
    if (this.custodyIssue()) {
      draft.party_id = id(this.partyId);
      draft.project_id = id(this.projectId);
      if (this.confirmed) draft.meta = { confirm_existing: true };
    }
    if (this.type === 'transfer' || this.type === 'exchange') {
      draft.counter_cash_box_id = id(this.toBoxId);
      if (amount(this.feeAmount) > 0) {
        draft.fee_amount = this.feeAmount;
        draft.fee_currency_id = currencyId(this.currency);
      }
    }
    if (this.type === 'exchange') {
      draft.counter_currency_id = currencyId(this.toCurrency);
      draft.counter_amount = this.counterAmount;
      draft.rate = amount(this.counterAmount) > 0 ? null : this.rate;
    }
    if (this.type === 'expense') draft.amount = this.linesTotal();
    if (this.drawsOnCustody()) {
      draft.custody_id = id(this.custodyId);
      draft.party_id = id(this.partyId);
      draft.branch_id = id(this.branchId);
    }
    if (this.type === 'project_billing') {
      draft.project_id = id(this.projectId);
      draft.party_id = id(this.partyId);
      draft.branch_id = id(this.branchId);
    }
    if (this.type === 'manual_journal') draft.branch_id = id(this.branchId);
    return draft;
  }

  protected async send(approve: boolean): Promise<void> {
    if (this.type === 'manual_journal' && this.unbalanced()) {
      this.warn('fvoUnbalanced');
      return;
    }
    if (this.overrideMessage() && !this.overrideReason.trim()) {
      this.warn('fvoOverrideNeeded');
      return;
    }
    this.lastApprove = approve;
    this.busy.set(true);
    try {
      // An employee picked from HR gets their finance party on first use (the backend posts advances to it).
      if (this.employeeAdvance() && this.employeeId) {
        const employee = this.employeeOptions().find((item) => item.id === this.employeeId);
        if (employee) this.partyId = (await this.finance.employeeParty(employee)).id;
      }
      const draft = this.payload(approve);
      const editing = this.voucher();
      const result = editing
        ? await this.finance.updateVoucher(editing.id, editing.version, draft)
        : await this.finance.createVoucher(draft);
      const message: CopyKey = result.status === 'approved' ? 'fvoSavedApproved' : 'fvoSavedDraft';
      this.office.done(message);
      if (result.warnings?.length) {
        this.office.notice.set({
          message,
          detail: `${this.t(message)} ${result.warnings.join(' · ')}`,
          undo: null,
          error: false,
        });
      }
      this.saved.emit(result);
    } catch (error) {
      const errors = fieldErrors(error);
      const confirm = errors['meta.confirm_existing']?.[0];
      const override = errors['override_reason']?.[0];
      if (confirm && !this.confirmed) {
        this.confirmMessage.set(confirm);
        this.office.notice.set(null);
      } else if (override) {
        this.overrideMessage.set(override);
        this.office.notice.set(null);
      } else {
        this.office.fail(error);
      }
    } finally {
      this.busy.set(false);
    }
  }

  /** R15: the user agreed to top up the holder's open custody. */
  protected confirmExisting(): void {
    this.confirmed = true;
    this.confirmMessage.set('');
    void this.send(this.lastApprove);
  }
}
