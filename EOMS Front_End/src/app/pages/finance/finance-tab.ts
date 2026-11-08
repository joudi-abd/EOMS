import { Directive, inject, signal } from '@angular/core';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import { Records } from '../../data/records';
import { amount, Finance, InvoiceStatus, Money, PeriodStatus, TransactionKind, TransactionStatus } from '../../data/finance';
import { Office } from '../../data/office';

export const transactionKindKey: Record<TransactionKind, CopyKey> = {
  receipt: 'finKindReceipt',
  payment: 'finKindPayment',
  transfer: 'finKindTransfer',
  exchange: 'finKindExchange',
  payroll: 'finKindPayroll',
};

export const transactionStatusKey: Record<TransactionStatus, CopyKey> = {
  pending: 'finStatusPending',
  posted: 'finStatusPosted',
  rejected: 'finStatusRejected',
  reversed: 'finStatusReversed',
};

export const invoiceStatusKey: Record<InvoiceStatus, CopyKey> = {
  draft: 'finStatusDraft',
  pending: 'finStatusPending',
  posted: 'finStatusPosted',
  rejected: 'finStatusRejected',
  cancelled: 'finStatusCancelled',
};

export const periodStatusKey: Record<PeriodStatus, CopyKey> = {
  open: 'finPeriodOpen',
  close_requested: 'finPeriodRequested',
  closed: 'finPeriodClosed',
};

/** The status word's color, following the design rule: green done, amber waiting, red refused, grey retired. */
export function tone(status: string): 'ok' | 'wait' | 'late' | 'mute' {
  if (status === 'posted' || status === 'open' || status === 'paid') return 'ok';
  if (status === 'pending' || status === 'draft' || status === 'close_requested' || status === 'partial') return 'wait';
  if (status === 'rejected' || status === 'unpaid' || status === 'overdue') return 'late';
  return 'mute';
}

const money2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty3 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 3 });

/** Shared helpers for the finance tabs: money formatting and one way to run a server action. */
@Directive()
export abstract class FinanceTab extends Ui {
  protected readonly finance = inject(Finance);
  protected readonly session = inject(Session);
  protected readonly office = inject(Office);
  protected readonly records = inject(Records);
  protected readonly busy = signal(false);
  protected readonly tone = tone;

  protected get canManage(): boolean {
    return this.session.has('finance.manage');
  }

  protected get canApprove(): boolean {
    return this.session.has('finance.approve');
  }

  /** Arabic shows Latin digits (design rule), so money uses one en-US format with two decimals. */
  protected money(value: Money | null | undefined): string {
    return money2.format(amount(value));
  }

  protected qty(value: Money | null | undefined): string {
    return qty3.format(amount(value));
  }

  protected positive(value: Money | null | undefined): boolean {
    return amount(value) > 0;
  }

  protected negative(value: Money | null | undefined): boolean {
    return amount(value) < 0;
  }

  protected kindLabel(kind: TransactionKind): string {
    return this.t(transactionKindKey[kind]);
  }

  protected statusLabel(status: TransactionStatus): string {
    return this.t(transactionStatusKey[status]);
  }

  protected invoiceStatusLabel(status: InvoiceStatus): string {
    return this.t(invoiceStatusKey[status]);
  }

  protected periodLabel(status: PeriodStatus): string {
    return this.t(periodStatusKey[status]);
  }

  /** Runs one server action: shows the success notice, or the server's own error wording. */
  protected async act(run: () => Promise<unknown>, message: CopyKey): Promise<boolean> {
    this.busy.set(true);
    try {
      await run();
      this.office.done(message);
      return true;
    } catch (error) {
      this.office.fail(error);
      return false;
    } finally {
      this.busy.set(false);
    }
  }

  /** A local check failed before reaching the server: show it the way a server error is shown. */
  protected warn(message: CopyKey): void {
    this.office.notice.set({ message, detail: '', undo: null, error: true });
  }

  /** Loads quietly: a failed lookup shows the error notice and leaves the list empty. */
  protected async quiet(run: () => Promise<unknown>): Promise<void> {
    try {
      await run();
    } catch (error) {
      this.office.fail(error);
    }
  }
}
