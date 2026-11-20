import { Component, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { FinanceParties, PartyCard, PartyStatement, StatementLine, TrackingMode } from '../../../../data/finance-parties';
import { amount, Money } from '../../../../data/finance';
import { FinanceBase } from '../../finance-base';

export const trackingKey: Record<TrackingMode, CopyKey> = {
  agreement: 'fpaTrackingAgreement',
  payments_only: 'fpaTrackingPayments',
};

/**
 * One party's page: its facts, what stands between us per currency, and its statement (opening, every posted line
 * with the running balance, closing). Positive = the party owes us ("لنا"), negative = we owe it ("علينا").
 */
@Component({
  selector: 'app-fin-party-card',
  imports: [FormsModule, RouterLink],
  templateUrl: './party-card.html',
  styleUrl: './party-card.scss',
  host: { class: 'ledger' },
})
export class FinPartyCard extends FinanceBase {
  private readonly parties = inject(FinanceParties);

  readonly party = input.required<PartyCard>();
  readonly edit = output<void>();
  readonly changed = output<void>();

  protected readonly mayEdit = this.may('finance.vouchers');
  /** The project filter needs the project list, which the server serves under `finance.projects.view` only. */
  protected readonly mayProjects = this.may('finance.projects.view');

  protected readonly currency = signal('');
  protected readonly project = signal(0);
  protected readonly from = signal('');
  protected readonly to = signal('');
  protected readonly statement = signal<PartyStatement | null>(null);
  protected readonly loading = signal(true);

  constructor() {
    super();
    void this.finance.loadCurrencies();
    if (this.mayProjects) void this.finance.loadProjects();
    effect(() => {
      const id = this.party().id;
      const filters = {
        currency: this.currency(),
        project_id: this.project(),
        from: this.from(),
        to: this.to(),
      };
      untracked(() => void this.load(id, filters));
    });
  }

  private async load(id: number, filters: Parameters<FinanceParties['statement']>[1]): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => this.statement.set(await this.parties.statement(id, filters)));
    this.loading.set(false);
  }

  /** "1,000.00 USD" without its sign; the side word says who owes whom. */
  protected plain(value: Money, currency: string): string {
    return this.money(Math.abs(amount(value)), currency);
  }

  protected bare(value: Money, currency: string): string {
    return this.figure(Math.abs(amount(value)), currency);
  }

  protected employeeName(id: number | null): string {
    if (!id) return '';
    const person = this.office.person(String(id));
    return person ? this.text(person.name) : this.t('fpaEmployeeLinked');
  }

  protected tracking(card: PartyCard): string {
    const mode = this.t(trackingKey[card.effective_tracking_mode] ?? 'fpaTrackingAgreement');
    return card.contractor_tracking_mode ? mode : `${mode} (${this.t('fpaTrackingDefault')})`;
  }

  protected lineText(line: StatementLine): string {
    return line.description || line.memo || (line.voucher_type ? this.voucherKind(line.voucher_type, line.voucher_purpose) : '') || line.account_name || '';
  }

  protected positiveShare(value: Money | null | undefined): boolean {
    return value !== null && value !== undefined && amount(value) > 0;
  }

  protected filtered(): boolean {
    return !!(this.currency() || this.project() || this.from() || this.to());
  }

  protected clear(): void {
    this.currency.set('');
    this.project.set(0);
    this.from.set('');
    this.to.set('');
  }

  protected async deactivate(): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', tone: 'danger', confirm: 'confirmProceed' }))) return;
    const done = await this.act(() => this.parties.deactivate(this.party().id), 'fpaDeactivated');
    if (done) this.changed.emit();
  }

  protected async activate(): Promise<void> {
    const done = await this.act(() => this.parties.update(this.party().id, { is_active: true }), 'fpaActivated');
    if (done) this.changed.emit();
  }
}
