import { Component, computed, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { amount, FinItem, FinStockMove, today } from '../../../data/finance';
import { FinanceTab } from '../finance-tab';

type Panel = 'none' | 'item' | 'adjust' | 'moves';

/** Stock items: quantity and moving average cost, updated by purchases, sales and manual adjustments. */
@Component({
  selector: 'app-finance-items',
  imports: [FormsModule],
  templateUrl: './finance-items.html',
  styleUrl: '../finance-shared.scss',
})
export class FinanceItems extends FinanceTab {
  protected readonly panel = signal<Panel>('none');
  protected readonly picked = signal<FinItem | null>(null);
  protected readonly moves = signal<FinStockMove[]>([]);

  // Item form
  protected editing: FinItem | null = null;
  protected itemName = '';
  protected itemUnit = '';
  protected itemCurrency = 'SYP';
  protected itemRevenue = 0;

  // Adjustment
  protected adjDate = today();
  protected adjQuantity: number | null = null;
  protected adjCost: number | null = null;
  protected adjAccount = 0;
  protected adjReason = '';

  protected readonly query = signal('');
  protected readonly listed = computed(() => {
    const q = this.query().trim();
    return this.finance.items().filter((item) => !q || item.name.includes(q));
  });

  /** Stock value per currency: currencies are never added together. */
  protected readonly valueByCurrency = computed(() => {
    const totals = new Map<string, number>();
    for (const item of this.finance.items()) totals.set(item.currency_code, (totals.get(item.currency_code) ?? 0) + item.stock_value);
    return [...totals.entries()].map(([currency, value]) => ({ currency, value }));
  });

  protected readonly emptyCount = computed(() => this.finance.items().filter((item) => item.active && amount(item.quantity) <= 0).length);

  protected open(panel: Panel): void {
    this.panel.set(this.panel() === panel ? 'none' : panel);
  }

  protected startNew(): void {
    this.editing = null;
    this.itemName = '';
    this.itemUnit = '';
    this.itemCurrency = this.records.currencies()[0]?.code ?? 'SYP';
    this.itemRevenue = 0;
    this.panel.set('item');
  }

  protected startEdit(item: FinItem): void {
    this.editing = item;
    this.itemName = item.name;
    this.itemUnit = item.unit;
    this.itemCurrency = item.currency_code;
    this.itemRevenue = item.revenue_account_id ?? 0;
    this.panel.set('item');
  }

  protected async saveItem(): Promise<void> {
    if (!this.itemName.trim() || !this.itemUnit.trim()) return;
    const editing = this.editing;
    const fields = { name: this.itemName.trim(), unit: this.itemUnit.trim(), revenue_account_id: this.itemRevenue || null };
    const ok = await this.act(
      () => (editing ? this.finance.updateItem(editing.id, fields) : this.finance.createItem({ ...fields, currency_code: this.itemCurrency })),
      'finItemSaved',
    );
    if (!ok) return;
    this.panel.set('none');
    await this.finance.loadItems();
  }

  protected async toggle(item: FinItem): Promise<void> {
    const ok = await this.act(() => this.finance.updateItem(item.id, { active: !item.active }), 'finItemSaved');
    if (ok) await this.finance.loadItems();
  }

  protected startAdjust(item: FinItem): void {
    this.picked.set(item);
    this.adjDate = today();
    this.adjQuantity = null;
    this.adjCost = null;
    this.adjAccount = 0;
    this.adjReason = '';
    this.panel.set('adjust');
  }

  /** A count above the book quantity adds stock at a unit cost; below it removes stock at the average cost. */
  protected adding(): boolean {
    return amount(this.adjQuantity) > 0;
  }

  protected async saveAdjust(): Promise<void> {
    const item = this.picked();
    if (!item || !amount(this.adjQuantity) || !this.adjAccount || this.adjReason.trim().length < 3) return;
    const ok = await this.act(
      () =>
        this.finance.adjustStock(item.id, {
          date: this.adjDate,
          quantity: amount(this.adjQuantity),
          unit_cost: this.adding() && this.adjCost !== null ? amount(this.adjCost) : null,
          account_id: this.adjAccount,
          reason: this.adjReason.trim(),
        }),
      'finStockAdjusted',
    );
    if (!ok) return;
    this.panel.set('none');
    await this.finance.loadItems();
  }

  protected async showMoves(item: FinItem): Promise<void> {
    this.picked.set(item);
    await this.quiet(async () => {
      this.moves.set((await this.finance.stockMoves(item.id)).data);
      this.panel.set('moves');
    });
  }

  protected sourceLabel(source: string): string {
    if (source.includes('invoice')) return this.t('finSourceInvoice');
    if (source.includes('adjust')) return this.t('finSourceAdjust');
    return source;
  }
}
