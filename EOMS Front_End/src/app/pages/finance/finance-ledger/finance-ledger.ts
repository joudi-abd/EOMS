import { Component, computed, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AccountType, amount, cleanQuery, FinAccount, FinEntry, FinPeriod, today } from '../../../data/finance';
import { CopyKey } from '../../../core/copy';
import { FinanceTab } from '../finance-tab';

type Panel = 'none' | 'account' | 'entry' | 'reverse' | 'period';

interface DraftLine {
  account_id: number;
  debit: number | null;
  credit: number | null;
  party_id: number;
  memo: string;
}

export const accountTypeKey: Record<AccountType, CopyKey> = {
  asset: 'finTypeAsset',
  liability: 'finTypeLiability',
  equity: 'finTypeEquity',
  revenue: 'finTypeRevenue',
  expense: 'finTypeExpense',
};

function blank(): DraftLine {
  return { account_id: 0, debit: null, credit: null, party_id: 0, memo: '' };
}

/** Chart of accounts, journal entries (manual ones can be posted and reversed here) and monthly period closing. */
@Component({
  selector: 'app-finance-ledger',
  imports: [FormsModule],
  templateUrl: './finance-ledger.html',
  styleUrl: './finance-ledger.scss',
  host: { class: 'ledger' },
})
export class FinanceLedger extends FinanceTab {
  protected readonly types: AccountType[] = ['asset', 'liability', 'equity', 'revenue', 'expense'];
  protected readonly panel = signal<Panel>('none');
  protected readonly entries = signal<FinEntry[]>([]);
  protected readonly page = signal(1);
  protected readonly lastPage = signal(1);
  protected readonly loading = signal(false);
  protected readonly periods = signal<FinPeriod[]>([]);
  protected readonly openEntry = signal<number | null>(null);

  // Filters
  protected filterCurrency = '';
  protected filterFrom = '';
  protected filterTo = '';
  protected filterSource = '';

  // Account form
  protected editing: FinAccount | null = null;
  protected accCode = '';
  protected accName = '';
  protected accType: AccountType = 'expense';
  protected accParent = 0;

  // Manual entry
  protected entryDate = today();
  protected entryCurrency = 'SYP';
  protected entryDescription = '';
  protected readonly lines = signal<DraftLine[]>([blank(), blank()]);

  // Reverse
  protected reversing: FinEntry | null = null;
  protected reverseDate = today();
  protected reverseReason = '';

  // Periods
  protected closeMonth = new Date().toISOString().slice(0, 7);
  protected periodNote = '';
  protected deciding: FinPeriod | null = null;

  /** Accounts in code order with their depth, so the list reads as a tree. */
  protected readonly tree = computed(() => {
    const all = this.finance.accounts();
    const byId = new Map(all.map((item) => [item.id, item]));
    const depth = (item: FinAccount): number => {
      let level = 0;
      let parent = item.parent_id ? byId.get(item.parent_id) : undefined;
      while (parent && level < 8) {
        level++;
        parent = parent.parent_id ? byId.get(parent.parent_id) : undefined;
      }
      return level;
    };
    return [...all].sort((a, b) => a.code.localeCompare(b.code)).map((item) => ({ item, depth: depth(item) }));
  });

  protected readonly groups = computed(() => this.finance.accounts().filter((item) => item.is_group || !item.box_id));

  constructor() {
    super();
    void this.load(1);
    void this.loadPeriods();
  }

  protected open(panel: Panel): void {
    this.panel.set(this.panel() === panel ? 'none' : panel);
  }

  protected typeLabel(type: AccountType): string {
    return this.t(accountTypeKey[type]);
  }

  protected async load(page: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.finance.entries(
        cleanQuery({ currency: this.filterCurrency, from: this.filterFrom, to: this.filterTo, source_type: this.filterSource }),
        page,
      );
      this.entries.set(result.data);
      this.page.set(result.meta.current_page);
      this.lastPage.set(result.meta.last_page);
    });
    this.loading.set(false);
  }

  protected async loadPeriods(): Promise<void> {
    await this.quiet(async () => this.periods.set(await this.finance.periods()));
  }

  protected toggleEntry(id: number): void {
    this.openEntry.set(this.openEntry() === id ? null : id);
  }

  protected entryTotal(entry: FinEntry): number {
    return entry.lines.reduce((sum, line) => sum + amount(line.debit), 0);
  }

  protected sourceLabel(source: string | null): string {
    const keys: Record<string, CopyKey> = {
      manual: 'finSourceManual',
      transaction: 'finSourceTransaction',
      invoice: 'finSourceInvoice',
      stock_move: 'finSourceAdjust',
      reversal: 'finSourceReversal',
      payroll: 'finKindPayroll',
    };
    const key = source ? keys[source] : undefined;
    return key ? this.t(key) : (source ?? '—');
  }

  // Accounts

  protected startAccount(account: FinAccount | null): void {
    this.editing = account;
    this.accCode = account?.code ?? '';
    this.accName = account?.name ?? '';
    this.accType = account?.type ?? 'expense';
    this.accParent = account?.parent_id ?? 0;
    this.panel.set('account');
  }

  protected async saveAccount(): Promise<void> {
    if (!this.accCode.trim() || !this.accName.trim()) return;
    const editing = this.editing;
    const ok = await this.act(
      () =>
        editing
          ? this.finance.updateAccount(editing.id, { code: this.accCode.trim(), name: this.accName.trim(), parent_id: this.accParent || null })
          : this.finance.createAccount({ code: this.accCode.trim(), name: this.accName.trim(), type: this.accType, parent_id: this.accParent || null }),
      'finAccountSaved',
    );
    if (!ok) return;
    this.panel.set('none');
    await this.finance.loadAccounts();
  }

  protected async toggleAccount(account: FinAccount): Promise<void> {
    const ok = await this.act(() => this.finance.updateAccount(account.id, { active: !account.active }), 'finAccountSaved');
    if (ok) await this.finance.loadAccounts();
  }

  // Manual entry

  protected startEntry(): void {
    this.entryDate = today();
    this.entryCurrency = this.records.currencies()[0]?.code ?? 'SYP';
    this.entryDescription = '';
    this.lines.set([blank(), blank()]);
    this.panel.set('entry');
  }

  protected addLine(): void {
    this.lines.update((rows) => [...rows, blank()]);
  }

  protected removeLine(index: number): void {
    this.lines.update((rows) => (rows.length > 2 ? rows.filter((_, i) => i !== index) : rows));
  }

  protected touch(): void {
    this.lines.update((rows) => [...rows]);
  }

  protected debitTotal(): number {
    return Math.round(this.lines().reduce((sum, line) => sum + amount(line.debit), 0) * 100) / 100;
  }

  protected creditTotal(): number {
    return Math.round(this.lines().reduce((sum, line) => sum + amount(line.credit), 0) * 100) / 100;
  }

  protected balanced(): boolean {
    return this.debitTotal() > 0 && this.debitTotal() === this.creditTotal();
  }

  protected async saveEntry(): Promise<void> {
    const lines = this.lines()
      .filter((line) => line.account_id && (amount(line.debit) > 0 || amount(line.credit) > 0))
      .map((line) => ({
        account_id: line.account_id,
        debit: amount(line.debit),
        credit: amount(line.credit),
        party_id: line.party_id || null,
        memo: line.memo.trim() || null,
      }));
    if (lines.length < 2 || !this.balanced() || !this.entryDescription.trim()) {
      this.warn('finEntryUnbalanced');
      return;
    }
    const ok = await this.act(
      () => this.finance.postEntry({ date: this.entryDate, currency_code: this.entryCurrency, description: this.entryDescription.trim(), lines }),
      'finEntryPosted',
    );
    if (!ok) return;
    this.panel.set('none');
    await this.load(1);
  }

  protected startReverse(entry: FinEntry): void {
    this.reversing = entry;
    this.reverseDate = today();
    this.reverseReason = '';
    this.panel.set('reverse');
  }

  protected async saveReverse(): Promise<void> {
    const entry = this.reversing;
    if (!entry || this.reverseReason.trim().length < 3) return;
    const ok = await this.act(() => this.finance.reverseEntry(entry.id, this.reverseDate, this.reverseReason.trim()), 'finReversed');
    if (!ok) return;
    this.panel.set('none');
    await this.load(1);
  }

  /** An entry already reversed, or one that is itself a reversal, cannot be reversed again. */
  protected reversible(entry: FinEntry): boolean {
    return entry.source_type === 'manual' && !entry.reverses_id && !this.entries().some((other) => other.reverses_id === entry.id);
  }

  // Periods

  protected async requestClose(): Promise<void> {
    if (!/^\d{4}-\d{2}$/.test(this.closeMonth)) return;
    const ok = await this.act(() => this.finance.requestClose(this.closeMonth), 'finCloseRequested');
    if (ok) await this.loadPeriods();
  }

  protected startDecide(period: FinPeriod): void {
    this.deciding = period;
    this.periodNote = '';
    this.panel.set('period');
  }

  protected async decide(decision: 'approve' | 'reject'): Promise<void> {
    const period = this.deciding;
    if (!period) return;
    const ok = await this.act(() => this.finance.decideClose(period.month, decision, this.periodNote.trim() || null), decision === 'approve' ? 'finPeriodClosedNotice' : 'finRejected');
    if (!ok) return;
    this.panel.set('none');
    await this.loadPeriods();
  }

  protected async reopen(period: FinPeriod): Promise<void> {
    if (this.periodNote.trim().length < 3) {
      this.warn('finReopenReason');
      return;
    }
    const ok = await this.act(() => this.finance.reopen(period.month, this.periodNote.trim()), 'finPeriodReopened');
    if (!ok) return;
    this.panel.set('none');
    await this.loadPeriods();
  }
}
