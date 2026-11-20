import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { amount, FinParty, thisMonth } from '../../../../data/finance';
import { AdvanceReport, AdvanceStatus, EmployeeAdvance, FinAdvances } from '../../../../data/finance-advances';
import { FinanceBase } from '../../finance-base';

export const advanceStatusKey: Record<AdvanceStatus, CopyKey> = {
  active: 'fadStatusActive',
  settled: 'fadStatusSettled',
  cancelled: 'fadStatusCancelled',
};

/** Employee advances: what is still owed per currency, what falls due soon, and the list of advances. */
@Component({
  selector: 'app-fin-advance-list',
  imports: [FormsModule],
  templateUrl: './advance-list.html',
  styleUrl: './advance-list.scss',
  host: { class: 'ledger' },
})
export class AdvanceList extends FinanceBase implements OnInit {
  private readonly store = inject(FinAdvances);
  private readonly router = inject(Router);

  protected readonly rows = signal<EmployeeAdvance[]>([]);
  protected readonly report = signal<AdvanceReport | null>(null);
  protected readonly page = signal(1);
  protected readonly pages = signal(1);
  protected readonly loading = signal(true);

  protected status: AdvanceStatus | '' = 'active';
  protected employeeId = 0;

  protected readonly statuses: AdvanceStatus[] = ['active', 'settled', 'cancelled'];

  /** Employees that have a finance party (an advance always does). */
  protected readonly employees = computed<FinParty[]>(() =>
    this.finance
      .parties()
      .filter((party) => party.employee_id !== null)
      .sort((a, b) => a.name.localeCompare(b.name)),
  );

  /** Outstanding per currency, with what falls due this month. */
  protected readonly figures = computed(() => {
    const report = this.report();
    if (!report) return [];
    const month = thisMonth();
    const out = new Map<string, { currency: string; outstanding: number; due: number; people: number }>();
    for (const row of report.balances) {
      const slot = out.get(row.currency) ?? { currency: row.currency, outstanding: 0, due: 0, people: 0 };
      slot.outstanding += amount(row.outstanding);
      slot.people += 1;
      out.set(row.currency, slot);
    }
    for (const row of report.upcoming.filter((item) => item.due_month === month)) {
      const slot = out.get(row.currency) ?? { currency: row.currency, outstanding: 0, due: 0, people: 0 };
      slot.due += amount(row.amount);
      out.set(row.currency, slot);
    }
    return [...out.values()].sort((a, b) => a.currency.localeCompare(b.currency));
  });

  async ngOnInit(): Promise<void> {
    void this.quiet(() => Promise.all([this.finance.loadCurrencies(), this.finance.loadParties()]));
    void this.quiet(async () => this.report.set(await this.store.advanceReport(3)));
    await this.load(1);
  }

  protected async load(page: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.store.advances({ status: this.status, employee_id: this.employeeId, per_page: 25 }, page);
      this.rows.set(result.data);
      this.page.set(result.meta.current_page);
      this.pages.set(result.meta.last_page);
    });
    this.loading.set(false);
  }

  protected reset(): void {
    this.status = 'active';
    this.employeeId = 0;
    void this.load(1);
  }

  protected open(row: EmployeeAdvance): void {
    void this.router.navigate([], { queryParams: { view: null, id: row.id } });
  }

  protected advanceStatus(status: AdvanceStatus): string {
    return this.t(advanceStatusKey[status] ?? 'fadStatusActive');
  }

  /** The list does not carry the next month; the report does, per employee and currency. */
  protected nextDue(row: EmployeeAdvance): string {
    if (row.status !== 'active') return '—';
    const found = this.report()?.balances.find((item) => item.employee_id === row.employee_id && item.currency_id === row.currency_id);
    return found?.next_due_month ?? '—';
  }

  protected code(row: EmployeeAdvance): string {
    return row.currency?.code ?? this.finance.code(row.currency_id);
  }
}
