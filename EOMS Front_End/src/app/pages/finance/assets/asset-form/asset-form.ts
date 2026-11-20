import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { amount, FinBox, today } from '../../../../data/finance';
import { ActiveStatus, AssetCategory, AssetDraft, categoryOptions, FinAssets } from '../../../../data/finance-assets';
import { FinanceBase } from '../../finance-base';

/**
 * The working sheet that adds one asset: bought now from a box (an asset_purchase voucher, posted at once unless
 * "approve now" is off), or already owned before the system started (an approved opening-balance voucher, no box).
 */
@Component({
  selector: 'app-fin-asset-form',
  imports: [FormsModule],
  templateUrl: './asset-form.html',
  styleUrl: './asset-form.scss',
  host: { class: 'ledger' },
})
export class AssetForm extends FinanceBase {
  private readonly assets = inject(FinAssets);

  readonly mode = input<'purchase' | 'opening'>('purchase');
  readonly categories = input<AssetCategory[]>([]);
  readonly saved = output<number | null>();
  readonly closed = output<void>();

  protected readonly categoryList = computed(() => categoryOptions(this.categories()));
  protected readonly people = this.assets.people;
  protected readonly more = signal(false);

  protected name = '';
  protected categoryId: number | null = null;
  protected quantity: number | null = 1;
  protected unitCost: number | null = null;
  protected readonly currency = signal('');
  protected purchaseDate = today();
  protected openingDate = today();
  protected openingPurchaseDate = '';
  protected status: ActiveStatus = 'in_service';
  protected holderId: number | null = null;
  protected readonly branchId = signal(0);
  protected boxId = 0;
  protected approve = true;
  protected serial = '';
  protected notes = '';
  protected reference = '';

  /** Branches this account works in (every branch when unrestricted), head office first. */
  protected readonly branchList = computed(() => {
    const mine = this.finance.myBranches();
    return this.finance
      .branches()
      .filter((item) => item.is_active && (mine === null || mine.includes(item.id)))
      .sort((a, b) => Number(b.is_head_office) - Number(a.is_head_office));
  });

  /** Boxes that hold the chosen currency, the asset's branch first. */
  protected readonly boxList = computed<FinBox[]>(() => {
    const branch = this.branchId();
    return [...this.finance.boxesWith(this.currency())].sort((a, b) => Number(b.branch_id === branch) - Number(a.branch_id === branch));
  });

  constructor() {
    super();
    void this.quiet(async () => {
      await Promise.all([this.finance.loadCurrencies(), this.finance.loadBranches(), this.finance.loadBoxes(), this.finance.loadMyBranches()]);
      const currencies = this.finance.activeCurrencies();
      this.currency.set((currencies.find((item) => item.is_base) ?? currencies[0])?.code ?? '');
      this.branchId.set(this.branchList()[0]?.id ?? 0);
      this.pickBox();
    });
  }

  protected get purchase(): boolean {
    return this.mode() === 'purchase';
  }

  protected currencyChanged(code: string): void {
    this.currency.set(code);
    this.pickBox();
  }

  protected branchChanged(id: number): void {
    this.branchId.set(id);
    if (!this.boxList().some((box) => box.id === this.boxId && box.branch_id === id)) this.pickBox();
  }

  private pickBox(): void {
    const list = this.boxList();
    if (!list.some((box) => box.id === this.boxId)) this.boxId = list[0]?.id ?? 0;
  }

  /** quantity × unit cost, shown in the currency's own decimals. */
  protected total(): number {
    return amount(this.quantity) * amount(this.unitCost);
  }

  protected async submit(): Promise<void> {
    if (!this.name.trim() || !this.unitCost || !this.quantity || !this.currency() || !this.branchId()) return;
    if (this.purchase && !this.boxId) return this.warn('fasNoBoxForCurrency');
    const draft: AssetDraft = {
      name: this.name.trim(),
      category_id: this.categoryId || null,
      quantity: Number(this.quantity),
      unit_cost: Number(this.unitCost),
      currency: this.currency(),
      purchase_date: this.purchase ? this.purchaseDate : this.openingPurchaseDate || null,
      holder_employee_id: this.holderId || null,
      branch_id: this.branchId(),
      serial_no: this.serial.trim() || null,
      notes: this.notes.trim() || null,
    };
    if (this.purchase) {
      Object.assign(draft, { cash_box_id: this.boxId, approve: this.approve, reference_no: this.reference.trim() || null });
    } else {
      Object.assign(draft, { date: this.openingDate, status: this.status });
    }
    const result = await this.act(
      async () => {
        const done = this.purchase ? await this.assets.purchase(draft) : await this.assets.opening(draft);
        return { ...done, warnings: done.voucher?.warnings ?? [] };
      },
      this.purchase ? (this.approve ? 'fasBought' : 'fasBoughtDraft') : 'fasAdded',
    );
    if (result) this.saved.emit(result.asset?.id ?? null);
  }
}
