import { Component, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { amount, Money, today, Voucher } from '../../../../data/finance';
import { BillingSuggestion, FinProjects, ProjectFile } from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';

/** Billing the client: how much to bill now (and how that was worked out), a new billing, and the billings so far. */
@Component({
  selector: 'app-fpj-project-billing',
  imports: [FormsModule, RouterLink],
  templateUrl: './project-billing.html',
  styleUrl: './project-billing.scss',
  host: { class: 'ledger' },
})
export class FpjProjectBilling extends FinanceBase {
  private readonly api = inject(FinProjects);

  readonly file = input.required<ProjectFile>();
  readonly changed = output<void>();

  protected readonly suggestion = signal<BillingSuggestion | null>(null);
  protected readonly billings = signal<Voucher[]>([]);
  protected readonly loading = signal(true);
  protected readonly creating = signal(false);
  protected readonly more = signal(false);
  protected readonly mayWrite = computed(() => this.may('finance.projects'));

  protected billAmount: number | null = null;
  protected billDate = today();
  protected description = '';
  protected reference = '';
  protected approve = true;

  constructor() {
    super();
    effect(() => {
      const id = this.file().project_id;
      untracked(() => void this.load(id));
    });
  }

  private async load(id = this.file().project_id): Promise<void> {
    this.loading.set(true);
    // A missing rate refuses the suggestion (422); the list still shows.
    await Promise.all([
      this.quiet(async () => this.suggestion.set(await this.api.suggestion(id))),
      this.quiet(async () => this.billings.set(await this.api.billings(id))),
    ]);
    this.loading.set(false);
  }

  /** How the suggested amount was worked out, in one sentence for the contract type. */
  protected explain(s: BillingSuggestion): string {
    const m = (value: Money | null) => this.money(value, s.currency);
    const billed = amount(s.already_billed) ? ` ${this.t('fpjExplainMinus')} ${m(s.already_billed)}` : '';
    switch (s.contract_type) {
      case 'cost_plus':
        return `${this.t('fpjExplainCosts')} ${m(s.billable_costs)} + ${this.t('fpjFee')} ${this.qty(s.fee_pct)}% ${this.t('fpjExplainOn')} ${m(s.fee_base_costs)} (${m(s.fee)})${billed} = ${m(s.suggested)}.`;
      case 'percentage_of_receipts':
        return `${this.t('fpjExplainCosts')} ${m(s.billable_costs)} + ${this.qty(s.fee_pct)}% ${this.t('fpjExplainOfReceipts')} ${m(s.receipts)} (${m(s.fee)})${billed} = ${m(s.suggested)}.`;
      default:
        return `${this.t('fpjExplainLump')} ${m(s.max)}.`;
    }
  }

  protected startNew(): void {
    const s = this.suggestion();
    this.billAmount = s?.suggested !== null && s?.suggested !== undefined && amount(s.suggested) > 0 ? amount(s.suggested) : null;
    this.billDate = today();
    this.description = '';
    this.reference = '';
    this.approve = true;
    this.more.set(false);
    this.creating.set(true);
  }

  protected async submit(): Promise<void> {
    if (this.billAmount === null) return;
    const done = await this.act(
      () =>
        this.api.bill(this.file().project_id, {
          amount: this.billAmount!,
          voucher_date: this.billDate,
          description: this.description.trim() || null,
          reference_no: this.reference.trim() || null,
          approve: this.approve,
        }),
      'fpjBillingIssued',
    );
    if (done) {
      this.creating.set(false);
      await this.load();
      this.changed.emit();
    }
  }
}
