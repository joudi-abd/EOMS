import { Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { FinCostCategory, FinExpenseCategory } from '../../../../data/finance';
import { AssetCategory, FinSettingsApi } from '../../../../data/finance-settings';
import { FinanceBase } from '../../finance-base';

type List = 'expense' | 'cost' | 'asset';

interface CategoryForm {
  list: List;
  id: number | null;
  name: string;
  parent_id: number | null;
  account_id: number | null;
  is_overhead_allocatable: boolean;
  is_billable: boolean;
  sort: number;
}

interface Row<T> {
  item: T;
  depth: number;
}

/**
 * The three category lists, one at a time: expense categories (a tree; each leaf posts to its expense account and
 * may be spread over projects as overhead), project cost categories (billable or not, in order), and asset
 * categories (a tree; deleted only when empty). All changes need `finance.settings`.
 */
@Component({
  selector: 'app-fst-categories',
  imports: [FormsModule],
  templateUrl: './categories.html',
  styleUrl: './categories.scss',
  host: { class: 'ledger' },
})
export class FstCategories extends FinanceBase implements OnInit {
  private readonly settings = inject(FinSettingsApi);

  readonly part = input('');

  protected readonly view = signal<List>('expense');
  protected readonly mayCost = this.may('finance.projects.view');
  protected readonly assets = signal<AssetCategory[]>([]);
  protected form: CategoryForm | null = null;
  protected readonly editing = signal(false);

  protected readonly expenseRows = computed(() => {
    const out: Row<FinExpenseCategory>[] = [];
    const walk = (nodes: FinExpenseCategory[], depth: number): void => {
      for (const node of nodes) {
        out.push({ item: node, depth });
        walk(node.children ?? [], depth + 1);
      }
    };
    walk(this.finance.expenseCategories(), 0);
    return out;
  });

  protected readonly costRows = computed(() => [...this.finance.costCategories()].sort((a, b) => a.sort - b.sort || a.name.localeCompare(b.name)));

  protected readonly assetRows = computed(() => {
    const all = this.assets();
    const out: Row<AssetCategory>[] = [];
    const walk = (parent: number | null, depth: number): void => {
      for (const node of all.filter((item) => item.parent_id === parent).sort((a, b) => a.name.localeCompare(b.name))) {
        out.push({ item: node, depth });
        walk(node.id, depth + 1);
      }
    };
    walk(null, 0);
    return out;
  });

  /** Active, postable expense accounts that are not boxes: what a leaf category posts to. */
  protected readonly expenseAccounts = computed(() =>
    this.finance.accounts().filter((item) => item.type === 'expense' && item.is_active && item.is_postable && !item.cash_box_id),
  );

  ngOnInit(): void {
    const part = this.part();
    if (part === 'cost' || part === 'asset') this.view.set(part);
    void this.load();
  }

  private async load(force = false): Promise<void> {
    await this.quiet(async () => {
      await Promise.all([
        this.finance.loadAccounts(),
        this.finance.loadExpenseCategories(force),
        this.mayCost ? this.finance.loadCostCategories(force) : undefined,
        (async () => this.assets.set(await this.settings.assetCategories()))(),
      ]);
    });
  }

  protected pick(list: List): void {
    this.view.set(list);
    this.close();
  }

  protected startNew(parent: number | null = null): void {
    this.form = { list: this.view(), id: null, name: '', parent_id: parent, account_id: null, is_overhead_allocatable: false, is_billable: true, sort: this.costRows().length + 1 };
    this.editing.set(true);
  }

  protected editExpense(item: FinExpenseCategory): void {
    this.form = { ...this.blank('expense', item.id, item.name), parent_id: item.parent_id, account_id: item.account_id, is_overhead_allocatable: item.is_overhead_allocatable };
    this.editing.set(true);
  }

  protected editCost(item: FinCostCategory): void {
    this.form = { ...this.blank('cost', item.id, item.name), is_billable: item.is_billable, sort: item.sort };
    this.editing.set(true);
  }

  protected editAsset(item: AssetCategory): void {
    this.form = { ...this.blank('asset', item.id, item.name), parent_id: item.parent_id };
    this.editing.set(true);
  }

  private blank(list: List, id: number, name: string): CategoryForm {
    return { list, id, name, parent_id: null, account_id: null, is_overhead_allocatable: false, is_billable: true, sort: 0 };
  }

  protected close(): void {
    this.form = null;
    this.editing.set(false);
  }

  /** Parents a category may move under: any other category of the same list (the server refuses a loop). */
  protected parents(list: List): { id: number; label: string }[] {
    const own = this.form?.id ?? null;
    const current = this.form?.parent_id ?? null;
    // An expense category goes only under an active one (the server refuses an inactive parent).
    const rows: Row<{ id: number; name: string; is_active?: boolean }>[] = list === 'expense' ? this.expenseRows() : this.assetRows();
    return rows
      .filter((row) => row.item.id !== own && (row.item.is_active !== false || row.item.id === current)).map((row) => ({ id: row.item.id, label: `${'— '.repeat(row.depth)}${row.item.name}` }));
  }

  protected async submit(): Promise<void> {
    const form = this.form;
    if (!form) return;
    const name = form.name.trim();
    let run: () => Promise<unknown>;
    if (form.list === 'expense') {
      const body = { name, parent_id: form.parent_id, account_id: form.account_id, is_overhead_allocatable: form.is_overhead_allocatable };
      run = () => (form.id ? this.settings.updateExpenseCategory(form.id, body) : this.settings.createExpenseCategory(body));
    } else if (form.list === 'cost') {
      const body = { name, is_billable: form.is_billable, sort: Number(form.sort) || 0 };
      run = () => (form.id ? this.settings.updateCostCategory(form.id, body) : this.settings.createCostCategory(body));
    } else {
      const body = { name, parent_id: form.parent_id };
      run = () => (form.id ? this.settings.updateAssetCategory(form.id, body) : this.settings.createAssetCategory(body));
    }
    const done = await this.act(run, 'finSaved');
    if (!done) return;
    this.close();
    await this.load(true);
  }

  protected async toggleExpense(item: FinExpenseCategory): Promise<void> {
    if (item.is_active && !(await this.askConfirm({ message: 'confirmSensitiveBody', tone: 'danger', confirm: 'confirmProceed' }))) return;
    const done = await this.act(
      () => (item.is_active ? this.settings.deactivateExpenseCategory(item.id) : this.settings.updateExpenseCategory(item.id, { is_active: true })),
      'finSaved',
    );
    if (done) await this.load(true);
  }

  protected async toggleCost(item: FinCostCategory): Promise<void> {
    if (item.is_active && !(await this.askConfirm({ message: 'confirmSensitiveBody', tone: 'danger', confirm: 'confirmProceed' }))) return;
    const done = await this.act(() => this.settings.updateCostCategory(item.id, { is_active: !item.is_active }), 'finSaved');
    if (done) await this.load(true);
  }

  protected async removeAsset(item: AssetCategory): Promise<void> {
    if (!(await this.askDelete())) return;
    const done = await this.act(() => this.settings.deleteAssetCategory(item.id), 'finDeleted');
    if (done) await this.load(true);
  }

  protected emptyAsset(item: AssetCategory): boolean {
    return !item.children_count && !item.assets_count;
  }
}
