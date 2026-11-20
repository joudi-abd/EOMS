import { Component, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CopyKey } from '../../../../core/copy';
import { BudgetLevel, BudgetLine, FinProjects, ProjectFile } from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';

const levelKey: Record<BudgetLevel, CopyKey> = { none: 'fpjLevelNone', warning: 'fpjLevelWarning', exceeded: 'fpjLevelExceeded' };

/** The budget per cost category and currency against what was actually spent, with a coloured state word. */
@Component({
  selector: 'app-fpj-project-budget',
  imports: [FormsModule],
  templateUrl: './project-budget.html',
  styleUrl: './project-budget.scss',
  host: { class: 'ledger' },
})
export class FpjProjectBudget extends FinanceBase {
  private readonly api = inject(FinProjects);

  readonly file = input.required<ProjectFile>();
  readonly changed = output<void>();

  protected readonly lines = signal<BudgetLine[]>([]);
  protected readonly loading = signal(true);
  /** null = closed, 0 = a new line, otherwise the line being changed. */
  protected readonly editing = signal<number | null>(null);
  protected readonly mayWrite = computed(() => this.may('finance.projects'));

  protected categoryId = 0;
  protected currencyId = 0;
  protected budgetAmount: number | null = null;
  protected notes = '';

  constructor() {
    super();
    effect(() => {
      const id = this.file().project_id;
      untracked(() => void this.load(id));
    });
  }

  private async load(id = this.file().project_id): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => this.lines.set(await this.api.budget(id)));
    this.loading.set(false);
  }

  /** none → green, warning → amber, exceeded → red; no rate → grey. */
  protected levelTone(level: BudgetLevel | null): string {
    return level === null ? 'mute' : level === 'none' ? 'ok' : this.tone(level);
  }

  protected levelLabel(level: BudgetLevel | null): string {
    return level === null ? this.t('fpjLevelNoRate') : this.t(levelKey[level]);
  }

  protected startNew(): void {
    this.categoryId = 0;
    this.currencyId = this.file().contract_currency_id ?? 0;
    this.budgetAmount = null;
    this.notes = '';
    this.editing.set(0);
  }

  protected startEdit(line: BudgetLine): void {
    this.categoryId = line.project_cost_category_id;
    this.currencyId = line.currency_id;
    this.budgetAmount = Number(line.budget_amount);
    this.notes = line.notes ?? '';
    this.editing.set(line.id);
  }

  protected async submit(): Promise<void> {
    const id = this.editing();
    if (id === null || this.budgetAmount === null) return;
    const notes = this.notes.trim() || null;
    const done = await this.act(
      () =>
        id
          ? this.api.updateBudgetLine(id, { budget_amount: this.budgetAmount!, notes })
          : this.api.addBudgetLine(this.file().project_id, {
              project_cost_category_id: this.categoryId,
              currency_id: this.currencyId,
              budget_amount: this.budgetAmount!,
              notes,
            }),
      'finSaved',
    );
    if (done) {
      this.editing.set(null);
      await this.load();
      this.changed.emit();
    }
  }

  protected async remove(line: BudgetLine): Promise<void> {
    if (!(await this.askDelete('fpjDeleteLineConfirm'))) return;
    const done = await this.act(() => this.api.deleteBudgetLine(line.id), 'finDeleted');
    if (done) {
      if (this.editing() === line.id) this.editing.set(null);
      await this.load();
      this.changed.emit();
    }
  }
}
