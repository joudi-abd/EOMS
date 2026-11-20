import { Component, input, model } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { amount, FinAccount } from '../../../../data/finance';
import { AssetRef } from '../../../../data/finance-vouchers';
import { FinanceBase } from '../../finance-base';

/** One editable line of an expense, a custody settlement or a manual journal. Ids are 0 when not chosen. */
export interface LineRow {
  key: number;
  expense_category_id: number;
  project_id: number;
  project_cost_category_id: number;
  asset_id: number;
  party_id: number;
  account_id: number;
  currency: string;
  debit: number | null;
  credit: number | null;
  memo: string;
}

let nextKey = 1;

export function blankLine(currency = ''): LineRow {
  return {
    key: nextKey++,
    expense_category_id: 0,
    project_id: 0,
    project_cost_category_id: 0,
    asset_id: 0,
    party_id: 0,
    account_id: 0,
    currency,
    debit: null,
    credit: null,
    memo: '',
  };
}

/** Accounts a manual journal or an "other" receipt/payment may use: active, postable, not a box, not a system account. */
export function plainAccounts(accounts: FinAccount[]): FinAccount[] {
  return accounts.filter(
    (item) => item.is_active && item.is_postable && !item.cash_box_id && !item.is_system,
  );
}

/** A row counts once it carries an amount. */
export function filledLines(rows: LineRow[]): LineRow[] {
  return rows.filter((row) => amount(row.debit) > 0 || amount(row.credit) > 0);
}

/**
 * The line table of a voucher form. `expense` mode: what the money went on (an expense category, or a project and its
 * cost category), the amount and a note, with a running total in the one currency. `journal` mode: account, currency,
 * debit, credit and note, with each currency's balance checked live.
 */
@Component({
  selector: 'app-voucher-lines',
  imports: [FormsModule],
  templateUrl: './voucher-lines.html',
  styleUrl: './voucher-lines.scss',
})
export class VoucherLines extends FinanceBase {
  readonly mode = input<'expense' | 'journal'>('expense');
  /** Expense mode: the voucher's one currency. */
  readonly currency = input('');
  /** Show the optional columns (asset; party and project in a journal). */
  readonly more = input(false);
  /** A supplier on each line (custody settlement). */
  readonly withParty = input(false);
  readonly assets = input<AssetRef[]>([]);
  readonly disabled = input(false);
  readonly lines = model.required<LineRow[]>();

  protected add(): void {
    this.lines.update((rows) => [
      ...rows,
      blankLine(this.mode() === 'journal' ? (rows.at(-1)?.currency ?? '') : this.currency()),
    ]);
  }

  protected remove(key: number): void {
    this.lines.update((rows) => rows.filter((row) => row.key !== key));
  }

  protected accounts(): FinAccount[] {
    return plainAccounts(this.finance.accounts());
  }

  /**
   * Journal mode: the party and project columns show with the extra details, or as soon as a chosen account requires
   * one (the server refuses such a line without it).
   */
  protected journalExtras(): boolean {
    return (
      this.more() ||
      this.lines().some((row) => {
        const account = this.finance.account(row.account_id);
        return !!(account?.requires_party || account?.requires_project);
      })
    );
  }

  protected currencies(): string[] {
    return this.finance.activeCurrencies().map((item) => item.code);
  }

  protected hasProjects(): boolean {
    return this.finance.projects().length > 0;
  }

  /** A debit and a credit on one line are exclusive: typing one clears the other. */
  protected setDebit(row: LineRow, value: number | null): void {
    row.debit = value;
    if (amount(value) > 0) row.credit = null;
  }

  protected setCredit(row: LineRow, value: number | null): void {
    row.credit = value;
    if (amount(value) > 0) row.debit = null;
  }

  protected setProject(row: LineRow, projectId: number): void {
    row.project_id = projectId;
    if (!projectId) row.project_cost_category_id = 0;
  }

  protected total(): number {
    return this.lines().reduce((sum, row) => sum + amount(row.debit), 0);
  }

  /** Each currency's debit and credit totals, and whether they meet. */
  protected balances(): { currency: string; debit: number; credit: number; even: boolean }[] {
    const sums = new Map<string, { debit: number; credit: number }>();
    for (const row of this.lines()) {
      if (!row.currency || (amount(row.debit) <= 0 && amount(row.credit) <= 0)) continue;
      const sum = sums.get(row.currency) ?? { debit: 0, credit: 0 };
      sum.debit += amount(row.debit);
      sum.credit += amount(row.credit);
      sums.set(row.currency, sum);
    }
    return [...sums].map(([currency, sum]) => ({
      currency,
      ...sum,
      even: Math.abs(sum.debit - sum.credit) < 10 ** -(this.finance.decimals(currency) + 1),
    }));
  }

  protected assetLabel(asset: AssetRef): string {
    return asset.code ? `${asset.code} · ${asset.name}` : asset.name;
  }
}
