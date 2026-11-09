import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Api } from '../../../core/api/api';
import { ApiItem, ApiPage } from '../../../core/api/types';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { amount, FinBox } from '../../../data/finance';
import { PayRunSummary, RunStatus, totalsList } from '../../../data/payroll';

const money2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Finance and payroll at a glance on the dashboard: box balances, what waits for approval, overdue invoices, and the
 * latest pay run. Each part loads only for an account with the matching permission, and a part that fails stays empty.
 */
@Component({
  selector: 'app-money-glance',
  imports: [RouterLink],
  templateUrl: './money.html',
  styleUrl: './money.scss',
})
export class MoneyGlance extends Ui {
  protected readonly session = inject(Session);
  private readonly api = inject(Api);
  protected readonly totalsList = totalsList;

  protected readonly boxes = signal<FinBox[] | null>(null);
  protected readonly pendingTransactions = signal(0);
  protected readonly pendingInvoices = signal(0);
  protected readonly overdueInvoices = signal(0);
  protected readonly run = signal<PayRunSummary | null | undefined>(undefined);

  constructor() {
    super();
    if (this.session.has('finance.view')) void this.loadFinance();
    if (this.session.has('payroll.view')) void this.loadPayroll();
  }

  protected money(value: string | number | null | undefined): string {
    return money2.format(amount(value));
  }

  protected runLabel(status: RunStatus): string {
    return this.t(status === 'draft' ? 'prStatusDraft' : status === 'submitted' ? 'prStatusSubmitted' : 'prStatusApproved');
  }

  protected runTone(status: RunStatus): string {
    return status === 'approved' ? 'ok' : status === 'submitted' ? 'wait' : '';
  }

  private async total(path: string, params: Record<string, string | number>): Promise<number> {
    try {
      return (await this.api.get<ApiPage<unknown>>(path, params)).meta?.total ?? 0;
    } catch {
      return 0;
    }
  }

  private async loadFinance(): Promise<void> {
    try {
      this.boxes.set((await this.api.get<ApiItem<FinBox[]>>('/finance/boxes')).data.filter((box) => box.active));
    } catch {
      this.boxes.set([]);
    }
    const [transactions, invoices, overdue] = await Promise.all([
      this.total('/finance/transactions', { status: 'pending' }),
      this.total('/finance/invoices', { status: 'pending' }),
      this.total('/finance/invoices', { status: 'posted', overdue: 1 }),
    ]);
    this.pendingTransactions.set(transactions);
    this.pendingInvoices.set(invoices);
    this.overdueInvoices.set(overdue);
  }

  private async loadPayroll(): Promise<void> {
    try {
      this.run.set((await this.api.get<ApiPage<PayRunSummary>>('/payroll/runs')).data[0] ?? null);
    } catch {
      this.run.set(null);
    }
  }
}
