import { Component, computed, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { amount, BoxKind, cleanQuery, FinInvoice, FinTransaction, today, TransactionInput, TransactionKind, TransactionStatus } from '../../../data/finance';
import { FinanceTab } from '../finance-tab';

type NewKind = Exclude<TransactionKind, 'payroll'>;
type Panel = 'none' | 'transaction' | 'box' | 'reverse' | 'limit' | 'decide';

/** Boxes, their balances, and the cash operations: receipt, payment, transfer and exchange. */
@Component({
  selector: 'app-finance-cash',
  imports: [FormsModule],
  templateUrl: './finance-cash.html',
  styleUrl: '../finance-shared.scss',
})
export class FinanceCash extends FinanceTab {
  protected readonly kinds: NewKind[] = ['receipt', 'payment', 'transfer', 'exchange'];
  protected readonly filterKinds: TransactionKind[] = ['receipt', 'payment', 'transfer', 'exchange', 'payroll'];
  protected readonly statuses: TransactionStatus[] = ['pending', 'posted', 'rejected', 'reversed'];

  protected readonly rows = signal<FinTransaction[]>([]);
  protected readonly page = signal(1);
  protected readonly lastPage = signal(1);
  protected readonly loading = signal(false);
  protected readonly panel = signal<Panel>('none');

  // Filters
  protected filterBox = 0;
  protected filterKind = '';
  protected filterStatus = '';
  protected filterFrom = '';
  protected filterTo = '';

  // New transaction
  protected readonly kind = signal<NewKind>('receipt');
  protected txBox = 0;
  protected txCounterBox = 0;
  protected txAmount: number | null = null;
  protected txCounterAmount: number | null = null;
  protected txDate = today();
  protected txDescription = '';
  protected txParty = 0;
  protected txAccount = 0;
  protected txInvoice = 0;
  protected readonly openInvoices = signal<FinInvoice[]>([]);

  // New box
  protected boxName = '';
  protected boxKind: BoxKind = 'cash';
  protected boxCurrency = 'SYP';

  // Reverse
  protected reversing: FinTransaction | null = null;
  protected reverseDate = today();
  protected reverseReason = '';

  // Approval limit
  protected limitCurrency = 'SYP';
  protected limitAmount: number | null = null;

  protected readonly pendingCount = computed(() => this.rows().filter((row) => row.status === 'pending').length);
  protected readonly activeBoxes = computed(() => this.finance.boxes().filter((box) => box.active));

  constructor() {
    super();
    void this.load(1);
  }

  protected async load(page: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.finance.transactions(
        cleanQuery({ box_id: this.filterBox, kind: this.filterKind, status: this.filterStatus, from: this.filterFrom, to: this.filterTo }),
        page,
      );
      this.rows.set(result.data);
      this.page.set(result.meta.current_page);
      this.lastPage.set(result.meta.last_page);
    });
    this.loading.set(false);
  }

  protected resetFilters(): void {
    this.filterBox = 0;
    this.filterKind = '';
    this.filterStatus = '';
    this.filterFrom = '';
    this.filterTo = '';
    void this.load(1);
  }

  protected open(panel: Panel): void {
    this.panel.set(this.panel() === panel ? 'none' : panel);
  }

  protected boxCurrencyOf(id: number): string {
    return this.finance.boxes().find((box) => box.id === id)?.currency_code ?? '';
  }

  protected twoBoxes(): boolean {
    return this.kind() === 'transfer' || this.kind() === 'exchange';
  }

  /** The exchange rate the two amounts imply, shown so the officer can check it before saving. */
  protected impliedRate(): string {
    const from = amount(this.txAmount);
    const to = amount(this.txCounterAmount);
    return from > 0 && to > 0 ? this.qty(to / from) : '—';
  }

  protected limitFor(currency: string): number | null {
    const found = this.finance.limits().find((item) => item.currency_code === currency);
    return found ? amount(found.amount) : null;
  }

  /** A receipt or payment above the currency's limit waits for an approver. */
  protected needsApproval(): boolean {
    if (this.twoBoxes() && this.kind() === 'transfer') return false;
    const limit = this.limitFor(this.boxCurrencyOf(this.txBox));
    return limit !== null && amount(this.txAmount) > limit;
  }

  /** Posted invoices of the chosen party with something left to pay, in the box's currency. */
  protected async partyChanged(): Promise<void> {
    this.txInvoice = 0;
    this.openInvoices.set([]);
    if (!this.txParty) return;
    await this.quiet(async () => {
      const result = await this.finance.invoices(cleanQuery({ party_id: this.txParty, status: 'posted' }));
      const currency = this.boxCurrencyOf(this.txBox);
      this.openInvoices.set(result.data.filter((row) => row.remaining > 0 && (!currency || row.currency_code === currency)));
    });
  }

  protected async saveTransaction(): Promise<void> {
    if (!this.txBox || amount(this.txAmount) <= 0 || !this.txDescription.trim()) return;
    const kind = this.kind();
    const body: TransactionInput = { date: this.txDate, box_id: this.txBox, amount: amount(this.txAmount), description: this.txDescription.trim() };
    if (kind === 'transfer' || kind === 'exchange') {
      body.counter_box_id = this.txCounterBox;
      if (kind === 'exchange') body.counter_amount = amount(this.txCounterAmount);
    } else {
      body.party_id = this.txParty || null;
      body.account_id = this.txAccount || null;
      body.invoice_id = this.txInvoice || null;
    }
    const saved = await this.act(() => this.finance.createTransaction(kind, body), 'finSavedTransaction');
    if (!saved) return;
    this.txAmount = null;
    this.txCounterAmount = null;
    this.txDescription = '';
    this.txInvoice = 0;
    this.panel.set('none');
    await Promise.all([this.load(1), this.finance.loadBoxes()]);
  }

  // Decision
  protected deciding: FinTransaction | null = null;
  protected decisionNote = '';

  protected startDecide(row: FinTransaction): void {
    this.deciding = row;
    this.decisionNote = '';
    this.panel.set('decide');
  }

  /** A rejection must say why; an approval may leave the note empty. */
  protected async decide(decision: 'approve' | 'reject'): Promise<void> {
    const row = this.deciding;
    if (!row) return;
    if (decision === 'reject' && !this.decisionNote.trim()) {
      this.warn('finRejectReason');
      return;
    }
    const note = this.decisionNote.trim() || null;
    const done = await this.act(() => this.finance.decideTransaction(row.id, decision, note), decision === 'approve' ? 'finApproved' : 'finRejected');
    if (!done) return;
    this.deciding = null;
    this.panel.set('none');
    await Promise.all([this.load(this.page()), this.finance.loadBoxes()]);
  }

  protected startReverse(row: FinTransaction): void {
    this.reversing = row;
    this.reverseDate = today();
    this.reverseReason = '';
    this.panel.set('reverse');
  }

  protected async saveReverse(): Promise<void> {
    const row = this.reversing;
    if (!row || this.reverseReason.trim().length < 3) return;
    const done = await this.act(() => this.finance.reverseTransaction(row.id, this.reverseDate, this.reverseReason.trim()), 'finReversed');
    if (!done) return;
    this.reversing = null;
    this.panel.set('none');
    await Promise.all([this.load(this.page()), this.finance.loadBoxes()]);
  }

  protected async saveBox(): Promise<void> {
    if (!this.boxName.trim()) return;
    const done = await this.act(
      () => this.finance.createBox({ name: this.boxName.trim(), kind: this.boxKind, currency_code: this.boxCurrency, parent_account_id: null }),
      'finSavedBox',
    );
    if (!done) return;
    this.boxName = '';
    this.panel.set('none');
    await Promise.all([this.finance.loadBoxes(), this.finance.loadAccounts()]);
  }

  protected async toggleBox(id: number, active: boolean): Promise<void> {
    const done = await this.act(() => this.finance.updateBox(id, { active }), 'finSavedBox');
    if (done) await this.finance.loadBoxes();
  }

  protected async saveLimit(): Promise<void> {
    if (this.limitAmount === null || this.limitAmount < 0) return;
    const done = await this.act(() => this.finance.setLimit(this.limitCurrency, amount(this.limitAmount)), 'finSavedLimit');
    if (!done) return;
    this.limitAmount = null;
    this.panel.set('none');
    await this.finance.loadLimits();
  }

  /** Where the money went: one box, or "from → to" for a transfer or exchange. */
  protected boxes(row: FinTransaction): string {
    const first = this.finance.boxName(row.box_id);
    const arrow = this.language.lang() === 'ar' ? '←' : '→';
    return row.counter_box_id ? `${first} ${arrow} ${this.finance.boxName(row.counter_box_id)}` : first;
  }

  protected kindIcon(kind: TransactionKind): string {
    if (kind === 'receipt') return 'fa-solid fa-arrow-down';
    if (kind === 'payment' || kind === 'payroll') return 'fa-solid fa-arrow-up';
    return 'fa-solid fa-right-left';
  }

  protected rowAmount(row: FinTransaction): string {
    return `${this.money(row.amount)} ${this.boxCurrencyOf(row.box_id)}`;
  }
}
