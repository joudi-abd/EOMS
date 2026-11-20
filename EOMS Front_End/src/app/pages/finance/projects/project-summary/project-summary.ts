import { Component, effect, inject, input, signal, untracked } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { amount, Money } from '../../../../data/finance';
import { FinProjects, Profitability, ProjectFile } from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';

/** Where the project stands in plain words: what came in, what was billed, what it cost, the fee and the margin. */
@Component({
  selector: 'app-fpj-project-summary',
  imports: [RouterLink],
  templateUrl: './project-summary.html',
  styleUrl: './project-summary.scss',
  host: { class: 'ledger' },
})
export class FpjProjectSummary extends FinanceBase {
  private readonly api = inject(FinProjects);

  readonly file = input.required<ProjectFile>();
  protected readonly profit = signal<Profitability | null>(null);
  /** The server's reason when profitability cannot be measured (usually a missing rate). */
  protected readonly problem = signal('');

  constructor() {
    super();
    effect(() => {
      const id = this.file().project_id;
      untracked(() => void this.load(id));
    });
  }

  private async load(id: number): Promise<void> {
    this.problem.set('');
    try {
      this.profit.set(await this.api.profitability(id));
    } catch (error) {
      this.profit.set(null);
      const fallback = this.file().summary?.profitability;
      this.problem.set(fallback && 'error' in fallback ? fallback.error : '');
      if (!this.problem()) this.office.fail(error);
    }
  }

  /** receipts − billed: positive means the client paid ahead (credit with us), negative means the client owes. */
  protected balanceWord(value: Money): CopyKey {
    const n = amount(value);
    return n > 0 ? 'fpjClientCredit' : n < 0 ? 'fpjClientOwes' : 'finEven';
  }

  protected budgetAlerts(): number {
    const budget = this.file().summary?.budget;
    return budget ? budget.warning + budget.exceeded : 0;
  }
}
