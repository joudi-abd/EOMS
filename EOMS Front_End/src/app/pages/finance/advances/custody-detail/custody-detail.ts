import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { CustodyDetail as CustodyRecord, CustodyKind, CustodyLine, FinAdvances } from '../../../../data/finance-advances';
import { FinanceBase } from '../../finance-base';

export const custodyKindKey: Record<CustodyKind, CopyKey> = {
  issue: 'vtCustodyIssue',
  settlement: 'vtCustodySettlement',
  return: 'vtCustodyReturn',
};

/** One custody: its totals and every voucher that moved it; settling, returning and topping up open the voucher form. */
@Component({
  selector: 'app-fin-custody-detail',
  imports: [RouterLink],
  templateUrl: './custody-detail.html',
  styleUrl: './custody-detail.scss',
  host: { class: 'ledger' },
})
export class CustodyDetail extends FinanceBase {
  private readonly store = inject(FinAdvances);

  readonly id = input.required<number>();

  protected readonly custody = signal<CustodyRecord | null>(null);
  protected readonly loading = signal(true);
  protected readonly mayAct = computed(() => this.may('finance.vouchers') && this.custody()?.status === 'open');

  constructor() {
    super();
    void this.quiet(() => this.finance.loadCurrencies());
    // Line names (project, category, supplier) come from lookups the account may read.
    if (this.may('finance.view')) void this.quiet(() => Promise.all([this.finance.loadParties(), this.finance.loadExpenseCategories()]));
    if (this.may('finance.projects.view')) void this.quiet(() => Promise.all([this.finance.loadProjects(), this.finance.loadCostCategories()]));
    effect(() => {
      const id = this.id();
      untracked(() => void this.load(id));
    });
  }

  private async load(id: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => this.custody.set(await this.store.custody(id)));
    this.loading.set(false);
  }

  protected kind(kind: CustodyKind): string {
    return this.t(custodyKindKey[kind] ?? 'vtCustodyIssue');
  }

  /** What a settlement line paid for, in words: project and cost category, or the expense category; then the supplier and memo. */
  protected lineLabel(line: CustodyLine, custody: CustodyRecord): string {
    const parts: string[] = [];
    if (line.project_id) {
      const project = this.finance.projectName(line.project_id) || (line.project_id === custody.project_id ? (custody.project_name ?? '') : '');
      const category = this.finance.costCategoryName(line.project_cost_category_id);
      parts.push([project, category].filter(Boolean).join(' · ') || this.t('finProject'));
    } else if (line.expense_category_id) {
      parts.push(this.finance.expenseCategoryName(line.expense_category_id) || this.t('finExpenseCategory'));
    }
    const supplier = this.finance.partyName(line.party_id);
    if (supplier) parts.push(supplier);
    if (line.memo) parts.push(line.memo);
    return parts.join(' — ');
  }

  /** Extra issue to the same holder, project and currency (R15 asks to confirm adding to this custody). */
  protected topUp(custody: CustodyRecord): Record<string, string | number> {
    const params: Record<string, string | number> = { new: 'custody_issue', party: custody.holder_party_id, currency: custody.currency };
    if (custody.project_id) params['project'] = custody.project_id;
    return params;
  }
}
