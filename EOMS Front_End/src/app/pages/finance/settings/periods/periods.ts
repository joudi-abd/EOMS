import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ChecklistItem, ClosingMonth, FinSettingsApi } from '../../../../data/finance-settings';
import { FinanceBase } from '../../finance-base';

/**
 * Month closing (`GET /finance/periods/closing`): every month newest first. An open month shows its checklist —
 * blockers red, warnings amber, passed green — and closes only when nothing blocks it. A closed month shows who
 * closed it and when; only the latest closed month reopens, with a reason.
 */
@Component({
  selector: 'app-fst-periods',
  imports: [FormsModule],
  templateUrl: './periods.html',
  styleUrl: './periods.scss',
  host: { class: 'ledger' },
})
export class FstPeriods extends FinanceBase implements OnInit {
  private readonly settings = inject(FinSettingsApi);

  protected readonly months = signal<ClosingMonth[]>([]);
  protected readonly loaded = signal(false);
  /** Months whose checklist is unfolded; the oldest open month (the next to close) starts unfolded. */
  protected readonly unfolded = signal<ReadonlySet<string>>(new Set());
  protected readonly sheet = signal<{ action: 'close' | 'reopen'; month: ClosingMonth } | null>(null);
  protected reason = '';

  /** Only the latest closed month reopens. */
  protected readonly latestClosed = computed(() => this.months().find((item) => item.status === 'closed')?.month ?? null);

  ngOnInit(): void {
    void this.load();
  }

  private async load(): Promise<void> {
    await this.quiet(async () => {
      const months = await this.settings.closing();
      this.months.set(months);
      const next = [...months].reverse().find((item) => item.status === 'open');
      this.unfolded.set(new Set(next ? [next.month] : []));
    });
    this.loaded.set(true);
  }

  protected toggle(month: string): void {
    const next = new Set(this.unfolded());
    if (next.has(month)) next.delete(month);
    else next.add(month);
    this.unfolded.set(next);
  }

  /** Failing blockers first, then failing warnings, then what passed. */
  protected items(month: ClosingMonth): ChecklistItem[] {
    const rank = (item: ChecklistItem) => (item.passed ? 2 : item.level === 'blocker' ? 0 : 1);
    return [...(month.checklist ?? [])].sort((a, b) => rank(a) - rank(b));
  }

  protected itemTone(item: ChecklistItem): 'ok' | 'wait' | 'late' {
    return item.passed ? 'ok' : item.level === 'blocker' ? 'late' : 'wait';
  }

  protected failingWarnings(month: ClosingMonth): ChecklistItem[] {
    return (month.checklist ?? []).filter((item) => !item.passed && item.level === 'warning');
  }

  /** "تشرين الثاني 2026" in the reader's language. */
  protected monthName(month: ClosingMonth): string {
    const locale = this.language.lang() === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB';
    return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(month.year, month.month_number - 1, 1)));
  }

  protected closedBy(month: ClosingMonth): string {
    const account = this.office.accounts().find((item) => item.userId === month.closed_by);
    return account ? this.text(account.name) : '';
  }

  protected startClose(month: ClosingMonth): void {
    this.sheet.set({ action: 'close', month });
  }

  protected startReopen(month: ClosingMonth): void {
    this.reason = '';
    this.sheet.set({ action: 'reopen', month });
  }

  protected async confirm(): Promise<void> {
    const sheet = this.sheet();
    if (!sheet) return;
    const month = sheet.month.month;
    if (sheet.action === 'reopen') {
      if (this.reason.trim().length < 3) {
        this.warn('fstReasonNeeded');
        return;
      }
      const done = await this.act(() => this.settings.reopenMonth(month, this.reason.trim()), 'fstMonthReopened');
      if (!done) return;
    } else {
      const done = await this.act(() => this.settings.closeMonth(month), 'fstMonthClosed');
      if (!done) return;
    }
    this.sheet.set(null);
    await this.load();
  }
}
