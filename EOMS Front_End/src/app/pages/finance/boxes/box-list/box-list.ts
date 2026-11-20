import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { amount, FinBox } from '../../../../data/finance';
import { FinBoxesApi } from '../../../../data/finance-boxes';
import { FinanceBase } from '../../finance-base';
import { BoxForm } from '../box-form/box-form';
import { BoxStatementView } from '../box-statement/box-statement';

/**
 * The boxes view: every box the account reaches with each currency's balance, a per-currency total strip, the open
 * box's statement in place (`?id=`), and, for `finance.settings`, the box sheet (`?new=box` or a row's edit) and
 * deactivation behind a confirm line.
 */
@Component({
  selector: 'app-fin-box-list',
  imports: [BoxForm, BoxStatementView],
  templateUrl: './box-list.html',
  styleUrl: './box-list.scss',
  host: { class: 'ledger' },
})
export class BoxList extends FinanceBase {
  private readonly boxesApi = inject(FinBoxesApi);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly params = toSignal(this.route.queryParamMap);

  protected readonly loading = signal(true);
  protected readonly showInactive = signal(false);
  /** The box being edited; null with the sheet open = a new box. */
  protected readonly editing = signal<FinBox | null>(null);
  protected readonly sheetOpen = signal(false);
  protected readonly confirming = signal<number | null>(null);

  protected readonly openId = computed(() => Number(this.params()?.get('id') ?? 0) || null);
  protected readonly openBox = computed(() => this.finance.box(this.openId()) ?? null);

  protected readonly hasInactive = computed(() => this.finance.boxes().some((box) => !box.is_active));
  protected readonly rows = computed(() => this.finance.boxes().filter((box) => box.is_active !== this.showInactive()));

  /** Each currency's total over the active boxes (never mixing currencies). */
  protected readonly totals = computed(() => {
    const sums = new Map<string, number>();
    for (const box of this.finance.activeBoxes()) {
      for (const row of box.currencies) sums.set(row.code, (sums.get(row.code) ?? 0) + amount(row.balance));
    }
    return [...sums].sort(([a], [b]) => a.localeCompare(b)).map(([code, total]) => ({ code, total }));
  });

  constructor() {
    super();
    void this.load();
    effect(() => {
      const wanted = this.params()?.get('new') === 'box';
      untracked(() => {
        if (wanted && this.may('finance.settings')) {
          this.editing.set(null);
          this.sheetOpen.set(true);
        }
      });
    });
  }

  private async load(): Promise<void> {
    this.loading.set(true);
    await this.quiet(() => Promise.all([this.finance.loadBoxes(true), this.finance.loadBranches(), this.finance.loadCurrencies()]));
    this.loading.set(false);
  }

  protected open(box: FinBox): void {
    void this.router.navigate([], { queryParams: { id: this.openId() === box.id ? null : box.id }, queryParamsHandling: 'merge' });
  }

  protected edit(box: FinBox): void {
    this.editing.set(box);
    this.sheetOpen.set(true);
    this.confirming.set(null);
  }

  protected closeSheet(): void {
    this.sheetOpen.set(false);
    this.editing.set(null);
    if (this.params()?.get('new')) void this.router.navigate([], { queryParams: { new: null }, queryParamsHandling: 'merge' });
  }

  protected async saved(box: FinBox): Promise<void> {
    this.closeSheet();
    await this.quiet(() => this.finance.loadBoxes(true));
    if (box.is_active) this.showInactive.set(false);
  }

  protected async deactivate(box: FinBox): Promise<void> {
    const done = await this.act(() => this.boxesApi.deactivateBox(box.id), 'fbxDeactivated');
    this.confirming.set(null);
    if (done) await this.quiet(() => this.finance.loadBoxes(true));
  }

  protected async activate(box: FinBox): Promise<void> {
    const done = await this.act(() => this.boxesApi.updateBox(box.id, { is_active: true }), 'fbxActivated');
    if (done) {
      await this.quiet(() => this.finance.loadBoxes(true));
      this.showInactive.set(false);
    }
  }
}
