import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CopyKey } from '../../../core/copy';
import { amount } from '../../../data/finance';
import { Asset, AssetCategory, assetStatuses, AssetStatus, categoryOptions, FinAssets } from '../../../data/finance-assets';
import { Notice } from '../../../shared/notice/notice';
import { FinanceBase } from '../finance-base';
import { AssetCardView } from './asset-card/asset-card';
import { AssetForm } from './asset-form/asset-form';
import { AssetImport } from './asset-import/asset-import';

type Sheet = 'none' | 'purchase' | 'opening' | 'import';

export const assetStatusKey: Record<AssetStatus, CopyKey> = {
  in_service: 'fasStInService',
  under_maintenance: 'fasStUnderMaintenance',
  sold: 'fasStSold',
  scrapped: 'fasStScrapped',
  lost: 'fasStLost',
  cancelled: 'fasStCancelled',
};

/**
 * Finance area: the asset register. One question: what does the company own, who holds it, and what did it cost.
 * A row opens the asset card in place (`?id=`); purchase, opening assets and the CSV import open as working sheets.
 * No depreciation: the book value is the cost until disposal.
 */
@Component({
  selector: 'app-fin-assets',
  imports: [FormsModule, RouterLink, Notice, AssetCardView, AssetForm, AssetImport],
  templateUrl: './assets.html',
  styleUrl: './assets.scss',
})
export class FinAssetsPage extends FinanceBase {
  private readonly assets = inject(FinAssets);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly statuses = assetStatuses;
  protected readonly canRead = this.may('finance.view');
  protected readonly canAct = this.may('finance.assets');

  /** The open asset card, from `?id=`. */
  protected readonly openId = computed(() => {
    const id = Number(this.params()?.get('id') ?? 0);
    return Number.isInteger(id) && id > 0 ? id : null;
  });

  protected readonly sheet = signal<Sheet>('none');
  protected readonly rows = signal<Asset[]>([]);
  protected readonly everything = signal<Asset[]>([]);
  protected readonly categories = signal<AssetCategory[]>([]);
  protected readonly categoryList = computed(() => categoryOptions(this.categories()));
  protected readonly loading = signal(false);
  protected readonly page = signal(1);
  protected readonly lastPage = signal(1);
  protected readonly total = signal(0);

  // Filters
  protected status: AssetStatus | '' = '';
  protected categoryId = 0;
  protected holderId = 0;
  protected search = '';
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  /** Figures over the whole register (not cancelled): counts and cost on the books per currency. */
  protected readonly figures = computed(() => {
    const all = this.everything();
    const active = all.filter((item) => item.status === 'in_service' || item.status === 'under_maintenance');
    const cost = new Map<string, number>();
    for (const item of active) {
      const code = item.currency ?? '';
      cost.set(code, (cost.get(code) ?? 0) + amount(item.total_cost));
    }
    return {
      inService: active.filter((item) => item.status === 'in_service').length,
      underMaintenance: active.filter((item) => item.status === 'under_maintenance').length,
      cost: [...cost.entries()].map(([currency, value]) => ({ currency, value })).sort((a, b) => a.currency.localeCompare(b.currency)),
    };
  });

  /** People who hold an asset now, for the holder filter. */
  protected readonly holders = computed(() => {
    const seen = new Map<number, string>();
    for (const item of this.everything()) if (item.holder) seen.set(item.holder.id, item.holder.name);
    return [...seen.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  });

  constructor() {
    super();
    if (this.canRead) {
      void this.quiet(() => this.finance.loadCurrencies());
      void this.quiet(() => this.finance.loadBranches());
      void this.quiet(() => this.finance.loadBoxes());
      void this.quiet(() => this.finance.loadParties());
      void this.quiet(() => this.finance.loadMyBranches());
      void this.quiet(async () => this.categories.set(await this.assets.categories()));
    }
    // Back on the register (first open, or after closing a card): refresh the list and the figures.
    effect(() => {
      if (this.openId() === null && this.canRead) untracked(() => void this.refresh());
    });
  }

  protected async refresh(): Promise<void> {
    await Promise.all([this.load(this.page()), this.quiet(async () => this.everything.set(await this.assets.all()))]);
  }

  protected async load(page = 1): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.assets.list(
        { status: this.status, category_id: this.categoryId, holder_employee_id: this.holderId, search: this.search.trim() },
        page,
      );
      this.rows.set(result.data);
      this.page.set(result.meta.current_page);
      this.lastPage.set(result.meta.last_page);
      this.total.set(result.meta.total);
    });
    this.loading.set(false);
  }

  protected applyFilters(): void {
    void this.load(1);
  }

  protected searchChanged(): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.applyFilters(), 350);
  }

  protected get hasFilters(): boolean {
    return !!(this.status || this.categoryId || this.holderId || this.search.trim());
  }

  protected resetFilters(): void {
    this.status = '';
    this.categoryId = 0;
    this.holderId = 0;
    this.search = '';
    this.applyFilters();
  }

  protected toggle(sheet: Sheet): void {
    this.sheet.set(this.sheet() === sheet ? 'none' : sheet);
  }

  protected open(id: number): void {
    this.sheet.set('none');
    void this.router.navigate([], { queryParams: { id }, queryParamsHandling: 'merge' });
  }

  protected closeCard(): void {
    void this.router.navigate([], { queryParams: { id: null }, queryParamsHandling: 'merge' });
  }

  /** A new asset was saved: open its card, or refresh the register when several came in. */
  protected created(id: number | null): void {
    this.sheet.set('none');
    if (id) this.open(id);
    else void this.refresh();
  }

  protected assetStatus(item: Asset): string {
    return this.t(assetStatusKey[item.status] ?? 'fasStInService');
  }

  /** A purchase that is still a draft reads as waiting, whatever the asset's own status says. */
  protected pending(item: Asset): boolean {
    return (item.status === 'in_service' || item.status === 'under_maintenance') && item.acquisition?.status !== 'approved';
  }

  protected statusKey(status: AssetStatus): CopyKey {
    return assetStatusKey[status];
  }
}
