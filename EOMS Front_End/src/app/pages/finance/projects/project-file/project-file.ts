import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ProjectStatus } from '../../../../data/finance';
import { FinProjects, ProjectFile, projectStatuses } from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';
import { FpjProjectBilling } from '../project-billing/project-billing';
import { FpjProjectBudget } from '../project-budget/project-budget';
import { FpjProjectContractors } from '../project-contractors/project-contractors';
import { FpjProjectForm } from '../project-form/project-form';
import { FpjProjectStatement } from '../project-statement/project-statement';
import { FpjProjectSummary } from '../project-summary/project-summary';

export type ProjectView = 'summary' | 'budget' | 'contractors' | 'billings' | 'statement';

/** One project's finance file: its contract at the top, then its summary, budget, contractors, billings and statement. */
@Component({
  selector: 'app-fpj-project-file',
  imports: [
    FormsModule,
    RouterLink,
    FpjProjectForm,
    FpjProjectSummary,
    FpjProjectBudget,
    FpjProjectContractors,
    FpjProjectBilling,
    FpjProjectStatement,
  ],
  templateUrl: './project-file.html',
  styleUrl: './project-file.scss',
  host: { class: 'ledger' },
})
export class FpjProjectFile extends FinanceBase {
  private readonly api = inject(FinProjects);
  private readonly router = inject(Router);

  readonly projectId = input.required<number>();
  readonly view = input<ProjectView>('summary');

  protected readonly statuses = projectStatuses;
  protected readonly file = signal<ProjectFile | null>(null);
  protected readonly missing = signal(false);
  protected readonly editing = signal(false);
  protected status: ProjectStatus = 'planned';

  protected readonly mayWrite = computed(() => this.may('finance.projects'));
  protected readonly views = computed<ProjectView[]>(() =>
    this.file()?.contract_type === 'internal' ? ['summary', 'budget', 'contractors', 'statement'] : ['summary', 'budget', 'contractors', 'billings', 'statement'],
  );
  protected readonly shown = computed<ProjectView>(() => (this.views().includes(this.view()) ? this.view() : 'summary'));

  constructor() {
    super();
    effect(() => {
      const id = this.projectId();
      untracked(() => {
        this.editing.set(false);
        void this.load(id);
      });
    });
  }

  protected async load(id = this.projectId()): Promise<void> {
    this.missing.set(false);
    try {
      const file = await this.api.project(id);
      this.file.set(file);
      this.status = file.status;
    } catch (error) {
      this.file.set(null);
      this.missing.set(true);
      this.office.fail(error);
    }
  }

  protected viewLabel(view: ProjectView): string {
    return this.t(
      ({ summary: 'fpjViewSummary', budget: 'fpjViewBudget', contractors: 'fpjViewContractors', billings: 'fpjViewBillings', statement: 'fpjViewStatement' } as const)[view],
    );
  }

  protected go(view: ProjectView): void {
    void this.router.navigate([], { queryParams: { id: this.projectId(), view: view === 'summary' ? null : view } });
  }

  protected async saveStatus(): Promise<void> {
    const file = this.file();
    if (!file || this.status === file.status) return;
    const done = await this.act(() => this.api.setStatus(file.project_id, this.status), 'fpjStatusSaved');
    if (done) {
      void this.finance.loadProjects(true);
      await this.load();
    }
  }

  protected async edited(): Promise<void> {
    this.editing.set(false);
    void this.finance.loadProjects(true);
    await this.load();
  }

  /** The contract in one line: "مقطوعية · 50,000.00 USD" or "تكلفة + نسبة · 10% · USD". */
  protected contract(file: ProjectFile): string {
    const type = this.contractTypeLabel(file.contract_type);
    const currency = file.contract_currency ?? '';
    if (file.contract_type === 'lump_sum') return `${type} · ${this.money(file.contract_value, currency)}`;
    if (file.fee_pct !== null && file.fee_pct !== undefined) return `${type} · ${this.qty(file.fee_pct)}% · ${currency}`;
    return `${type} · ${currency}`;
  }

  /** The quick "receive a client payment" link into the voucher form. */
  protected receiptLink(file: ProjectFile): Record<string, string | number> {
    return {
      new: 'receipt',
      purpose: 'client_payment',
      party: file.client_party_id ?? '',
      project: file.project_id,
      currency: file.contract_currency ?? '',
    };
  }
}
