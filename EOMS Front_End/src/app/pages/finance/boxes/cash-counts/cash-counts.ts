import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { CashCount, CountFilters, countStatuses, FinBoxesApi, loginPeople } from '../../../../data/finance-boxes';
import { FinanceBase } from '../../finance-base';
import { CountDetail } from '../count-detail/count-detail';
import { CountForm } from '../count-form/count-form';

/**
 * The cash counts view (`?view=counts`): the counts newest first with a small filter bar, the count sheet
 * (`&new=count`, or a draft's edit), and the open count (`&id=`) with its differences and decisions.
 */
@Component({
  selector: 'app-fin-cash-counts',
  imports: [FormsModule, CountDetail, CountForm],
  templateUrl: './cash-counts.html',
  styleUrl: './cash-counts.scss',
  host: { class: 'ledger' },
})
export class CashCounts extends FinanceBase {
  private readonly boxesApi = inject(FinBoxesApi);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly statuses = countStatuses;
  protected readonly counts = signal<CashCount[]>([]);
  protected readonly page = signal(1);
  protected readonly lastPage = signal(1);
  protected readonly loading = signal(true);
  /** The draft being edited; null with the sheet open = a new count. */
  protected readonly editing = signal<CashCount | null>(null);
  protected readonly sheetOpen = signal(false);

  protected filters: CountFilters = { box_id: 0, status: '', from: '', to: '' };

  protected readonly openId = computed(() => Number(this.params()?.get('id') ?? 0) || null);

  constructor() {
    super();
    void this.finance.loadBoxes();
    void this.finance.loadCurrencies();
    void this.load(1);
    effect(() => {
      const wanted = this.params()?.get('new') === 'count';
      untracked(() => {
        if (wanted && this.may('finance.counts')) {
          this.editing.set(null);
          this.sheetOpen.set(true);
        }
      });
    });
  }

  protected async load(page: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.boxesApi.counts(this.filters, page);
      this.counts.set(result.data);
      this.page.set(result.meta?.current_page ?? page);
      this.lastPage.set(result.meta?.last_page ?? 1);
    });
    this.loading.set(false);
  }

  protected reset(): void {
    this.filters = { box_id: 0, status: '', from: '', to: '' };
    void this.load(1);
  }

  protected open(count: CashCount): void {
    void this.router.navigate([], { queryParams: { view: 'counts', id: this.openId() === count.id ? null : count.id, new: null } });
  }

  protected edit(count: CashCount): void {
    this.editing.set(count);
    this.sheetOpen.set(true);
  }

  protected closeSheet(): void {
    this.sheetOpen.set(false);
    this.editing.set(null);
    if (this.params()?.get('new')) void this.router.navigate([], { queryParams: { new: null }, queryParamsHandling: 'merge' });
  }

  /** A saved count opens in place so its differences show right away. */
  protected saved(count: CashCount): void {
    this.sheetOpen.set(false);
    this.editing.set(null);
    void this.router.navigate([], { queryParams: { view: 'counts', id: count.id, new: null } });
    void this.load(this.page());
  }

  protected removed(): void {
    void this.router.navigate([], { queryParams: { view: 'counts', id: null } });
    void this.load(this.page());
  }

  protected who(userId: number): string {
    if (userId === this.session.account().userId) return this.t('fbxYou');
    const found = loginPeople(this.office).find((item) => item.userId === userId);
    return found ? this.text(found.name) : '—';
  }
}
