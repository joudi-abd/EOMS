import { Component, computed, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { amount } from '../../../../data/finance';
import { FinanceBase } from '../../finance-base';

/**
 * The overview for an account without `finance.reports`: each currency's total over the active boxes, the boxes
 * that are below zero or near their limit, and every box's balances, each linking to its statement.
 */
@Component({
  selector: 'app-fin-overview-boxes',
  imports: [RouterLink],
  templateUrl: './boxes-summary.html',
  styleUrl: './boxes-summary.scss',
})
export class BoxesSummary extends FinanceBase {
  protected readonly loading = signal(true);

  protected readonly totals = computed(() => {
    const sums = new Map<string, number>();
    for (const box of this.finance.activeBoxes()) {
      for (const row of box.currencies) sums.set(row.code, (sums.get(row.code) ?? 0) + amount(row.balance));
    }
    return [...sums].sort(([a], [b]) => a.localeCompare(b)).map(([code, total]) => ({ code, total }));
  });

  protected readonly alerts = computed(() =>
    this.finance.activeBoxes().flatMap((box) => box.currencies.filter((row) => row.negative || row.near_limit).map((row) => ({ box, row }))),
  );

  constructor() {
    super();
    void this.quiet(() => Promise.all([this.finance.loadBoxes(true), this.finance.loadBranches(), this.finance.loadCurrencies()])).then(() =>
      this.loading.set(false),
    );
  }
}
