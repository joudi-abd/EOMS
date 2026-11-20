import { Component, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { amount, FinBox, Money, today } from '../../../../data/finance';
import {
  AssetCard,
  AssetCategory,
  AssetDisposalRow,
  AssetStatus,
  categoryOptions,
  deletable,
  DisposalType,
  disposalTypes,
  FinAssets,
  operable,
} from '../../../../data/finance-assets';
import { FinanceBase } from '../../finance-base';

type Panel = 'none' | 'edit' | 'transfer' | 'maintenance' | 'dispose';

const statusKey: Record<AssetStatus, CopyKey> = {
  in_service: 'fasStInService',
  under_maintenance: 'fasStUnderMaintenance',
  sold: 'fasStSold',
  scrapped: 'fasStScrapped',
  lost: 'fasStLost',
  cancelled: 'fasStCancelled',
};

const disposalKey: Record<DisposalType, CopyKey> = {
  sale: 'fasSale',
  scrap: 'fasScrap',
  loss: 'fasLoss',
};

/**
 * One asset, in place of the register: its facts, then custody, maintenance, linked expenses and disposal, each
 * with the one action that adds to it. Operations need the purchase posted and the asset still in service or under
 * maintenance; the server enforces the same rule.
 */
@Component({
  selector: 'app-fin-asset-card',
  imports: [FormsModule, RouterLink],
  templateUrl: './asset-card.html',
  styleUrl: './asset-card.scss',
  host: { class: 'ledger' },
})
export class AssetCardView extends FinanceBase {
  private readonly assets = inject(FinAssets);

  readonly assetId = input.required<number>();
  readonly categories = input<AssetCategory[]>([]);
  readonly closed = output<void>();
  readonly removed = output<void>();

  protected readonly disposalTypes = disposalTypes;
  protected readonly canAct = this.may('finance.assets');
  protected readonly people = this.assets.people;
  protected readonly categoryList = computed(() => categoryOptions(this.categories()));

  protected readonly card = signal<AssetCard | null>(null);
  protected readonly loading = signal(false);
  protected readonly panel = signal<Panel>('none');
  protected readonly confirmingDelete = signal(false);
  protected readonly more = signal(false);

  protected readonly operable = computed(() => {
    const card = this.card();
    return !!card && operable(card);
  });
  protected readonly deletable = computed(() => {
    const card = this.card();
    return !!card && deletable(card);
  });

  /** Expense totals as one row per currency: operating and maintenance side by side. */
  protected readonly expenseTotals = computed(() => {
    const totals = this.card()?.expenses.totals;
    if (!totals) return [];
    const codes = [...new Set([...Object.keys(totals.operating ?? {}), ...Object.keys(totals.maintenance ?? {})])].sort();
    return codes.map((code) => ({ currency: code, operating: totals.operating?.[code] ?? 0, maintenance: totals.maintenance?.[code] ?? 0 }));
  });

  // Edit
  protected editName = '';
  protected editCategory: number | null = null;
  protected editSerial = '';
  protected editNotes = '';

  // Transfer
  protected toEmployee: number | null = null;
  protected transferDate = today();
  protected transferNotes = '';

  // Maintenance
  protected readonly mBox = signal(0);
  protected mCurrency = '';
  protected mAmount: number | null = null;
  protected mDate = today();
  protected mDescription = '';
  protected mProject: number | null = null;
  protected mReference = '';
  protected mApprove = true;

  // Disposal
  protected dType: DisposalType = 'sale';
  protected dDate = today();
  protected dAmount: number | null = null;
  protected dBox = 0;
  protected dNotes = '';
  protected dApprove = true;

  protected readonly maintenanceBoxes = computed<FinBox[]>(() => this.finance.activeBoxes());
  protected readonly maintenanceCurrencies = computed(() => this.finance.box(this.mBox())?.currencies ?? []);

  constructor() {
    super();
    void this.quiet(() => this.finance.loadBoxes());
    void this.quiet(() => this.finance.loadCurrencies());
    if (this.may('finance.projects.view')) void this.quiet(() => this.finance.loadProjects());
    effect(() => {
      const id = this.assetId();
      untracked(() => void this.load(id));
    });
  }

  private async load(id: number): Promise<void> {
    this.panel.set('none');
    this.confirmingDelete.set(false);
    this.loading.set(true);
    await this.quiet(async () => this.card.set(await this.assets.card(id)));
    this.loading.set(false);
  }

  protected statusLabelOf(status: AssetStatus): string {
    return this.t(statusKey[status] ?? 'fasStInService');
  }

  protected disposalLabel(type: DisposalType): string {
    return this.t(disposalKey[type] ?? 'fasScrap');
  }

  protected projectLabel(projectId: number | null): string {
    return projectId ? this.finance.projectName(projectId) || '—' : '—';
  }

  /** Sale price less cost (no depreciation): positive is a gain. Scrap and loss lose the whole cost. */
  protected disposalResult(row: AssetDisposalRow): number {
    const cost = amount(this.card()?.total_cost);
    return row.type === 'sale' ? amount(row.sale_amount) - cost : -cost;
  }

  protected resultWord(value: number): CopyKey {
    return value > 0 ? 'fasGain' : value < 0 ? 'fasLossResult' : 'finEven';
  }

  protected abs(value: Money): number {
    return Math.abs(amount(value));
  }

  protected show(panel: Panel): void {
    const card = this.card();
    if (!card) return;
    this.confirmingDelete.set(false);
    if (this.panel() === panel) return this.panel.set('none');
    if (panel === 'edit') {
      this.editName = card.name;
      this.editCategory = card.category_id;
      this.editSerial = card.serial_no ?? '';
      this.editNotes = card.notes ?? '';
    }
    if (panel === 'transfer') {
      this.toEmployee = null;
      this.transferDate = today();
      this.transferNotes = '';
    }
    if (panel === 'maintenance') {
      const code = card.currency ?? '';
      const boxes = this.finance.boxesWith(code);
      const box = boxes.find((item) => item.branch_id === card.branch_id) ?? boxes[0] ?? this.maintenanceBoxes()[0];
      this.mBox.set(box?.id ?? 0);
      this.mCurrency = box?.currencies.some((item) => item.code === code) ? code : (box?.currencies[0]?.code ?? '');
      this.mAmount = null;
      this.mDate = today();
      this.mDescription = '';
      this.mProject = null;
      this.mReference = '';
      this.mApprove = true;
      this.more.set(false);
    }
    if (panel === 'dispose') {
      this.dType = 'sale';
      this.dDate = today();
      this.dAmount = null;
      const boxes = this.saleBoxes();
      this.dBox = (boxes.find((item) => item.branch_id === card.branch_id) ?? boxes[0])?.id ?? 0;
      this.dNotes = '';
      this.dApprove = true;
    }
    this.panel.set(panel);
  }

  protected maintenanceBoxChanged(id: number): void {
    this.mBox.set(id);
    const currencies = this.maintenanceCurrencies();
    if (!currencies.some((item) => item.code === this.mCurrency)) {
      const own = this.card()?.currency;
      this.mCurrency = currencies.find((item) => item.code === own)?.code ?? currencies[0]?.code ?? '';
    }
  }

  /** A sale is received in the asset's own currency, so only boxes holding it. */
  protected saleBoxes(): FinBox[] {
    return this.finance.boxesWith(this.card()?.currency ?? '');
  }

  protected async saveEdit(): Promise<void> {
    const card = this.card();
    if (!card || !this.editName.trim()) return;
    const result = await this.act(
      () =>
        this.assets.update(card.id, {
          name: this.editName.trim(),
          category_id: this.editCategory || null,
          serial_no: this.editSerial.trim() || null,
          notes: this.editNotes.trim() || null,
        }),
      'fasUpdated',
    );
    if (result) this.done(result);
  }

  protected async transfer(): Promise<void> {
    const card = this.card();
    if (!card || !this.toEmployee) return;
    const result = await this.act(() => this.assets.transfer(card.id, this.toEmployee!, this.transferDate, this.transferNotes.trim() || null), 'fasTransferred');
    if (result) this.done(result);
  }

  protected async maintain(): Promise<void> {
    const card = this.card();
    if (!card || !this.mBox() || !this.mCurrency || !this.mAmount || !this.mDescription.trim()) return;
    const result = await this.act(async () => {
      const done = await this.assets.maintain(card.id, {
        cash_box_id: this.mBox(),
        currency: this.mCurrency,
        amount: Number(this.mAmount),
        date: this.mDate,
        description: this.mDescription.trim(),
        project_id: this.mProject || null,
        reference_no: this.mReference.trim() || null,
        approve: this.mApprove,
      });
      return { ...done, warnings: done.voucher?.warnings ?? [] };
    }, this.mApprove ? 'fasMaintenanceSaved' : 'fasMaintenanceDraft');
    if (result?.asset) this.done(result.asset);
  }

  protected async dispose(): Promise<void> {
    const card = this.card();
    if (!card) return;
    const sale = this.dType === 'sale';
    if (sale && (!this.dAmount || !this.dBox)) return;
    const result = await this.act(async () => {
      const done = await this.assets.dispose(card.id, {
        type: this.dType,
        date: this.dDate,
        ...(sale ? { sale_amount: Number(this.dAmount), cash_box_id: this.dBox } : {}),
        notes: this.dNotes.trim() || null,
        approve: this.dApprove,
      });
      return { ...done, warnings: done.voucher?.warnings ?? [] };
    }, this.dApprove ? 'fasDisposed' : 'fasDisposalDraft');
    if (result?.asset) this.done(result.asset);
  }

  protected async toggleStatus(): Promise<void> {
    const card = this.card();
    if (!card) return;
    const next = card.status === 'in_service' ? 'under_maintenance' : 'in_service';
    const result = await this.act(() => this.assets.setStatus(card.id, next), 'fasStatusChanged');
    if (result) this.done(result);
  }

  protected async remove(): Promise<void> {
    const card = this.card();
    if (!card) return;
    const result = await this.act(() => this.assets.remove(card.id), 'finDeleted');
    if (result) this.removed.emit();
  }

  private done(card: AssetCard): void {
    this.card.set(card);
    this.panel.set('none');
  }
}
