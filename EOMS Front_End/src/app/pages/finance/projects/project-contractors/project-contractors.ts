import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { Agreement, AgreementCard, AgreementDraft, AgreementMove, AgreementStatus, agreementStatuses, FinProjects, ProjectFile } from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';

const statusKey: Record<AgreementStatus, CopyKey> = { open: 'fpjAgOpen', completed: 'fpjAgCompleted', cancelled: 'fpjAgCancelled' };

/**
 * Contractor agreements on the project: what was agreed, paid and is left. A row opens its payments and advances;
 * a payment or an advance is made on the voucher screen, prefilled from here.
 */
@Component({
  selector: 'app-fpj-project-contractors',
  imports: [FormsModule, RouterLink],
  templateUrl: './project-contractors.html',
  styleUrl: './project-contractors.scss',
  host: { class: 'ledger' },
})
export class FpjProjectContractors extends FinanceBase {
  private readonly api = inject(FinProjects);

  readonly file = input.required<ProjectFile>();

  protected readonly statuses = agreementStatuses;
  protected readonly rows = signal<Agreement[]>([]);
  protected readonly loading = signal(true);
  protected readonly open = signal<AgreementCard | null>(null);
  /** null = closed, 0 = a new agreement, otherwise the agreement being changed. */
  protected readonly editing = signal<number | null>(null);
  protected readonly more = signal(false);
  protected readonly mayWrite = computed(() => this.may('finance.projects'));
  protected readonly mayPay = computed(() => this.may('finance.vouchers'));
  protected readonly contractors = computed(() => this.finance.partiesWith('contractor'));

  protected partyId = 0;
  protected scope = '';
  protected categoryId = 0;
  protected currencyId = 0;
  protected agreed: number | null = null;
  protected discount: number | null = null;
  protected status: AgreementStatus = 'open';
  protected notes = '';
  private editedName = '';

  constructor() {
    super();
    effect(() => {
      const id = this.file().project_id;
      untracked(() => void this.load(id));
    });
  }

  private async load(id = this.file().project_id): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => this.rows.set(await this.api.agreements(id)));
    this.loading.set(false);
  }

  protected statusName(status: AgreementStatus): string {
    return this.t(statusKey[status] ?? 'fpjAgOpen');
  }

  protected agTone(status: AgreementStatus): string {
    return status === 'open' ? 'wait' : status === 'completed' ? 'ok' : 'mute';
  }

  protected code(row: Agreement): string {
    return this.finance.code(row.currency_id);
  }

  protected async toggle(row: Agreement): Promise<void> {
    if (this.open()?.id === row.id) {
      this.open.set(null);
      return;
    }
    await this.quiet(async () => this.open.set(await this.api.agreement(row.id)));
  }

  protected moveGroups(card: AgreementCard): { label: string; rows: AgreementMove[] }[] {
    return [
      { label: this.t('fpjPayments'), rows: card.payments },
      { label: this.t('fpjAdvances'), rows: card.advances },
    ];
  }

  /** The voucher form, prefilled for a contractor payment or advance on this agreement. */
  protected voucherLink(row: Agreement, purpose: 'contractor_payment' | 'contractor_advance'): Record<string, string | number> {
    return {
      new: 'payment',
      purpose,
      party: row.contractor_party_id,
      project: row.project_id,
      agreement: row.id,
      currency: this.code(row),
    };
  }

  protected startNew(): void {
    this.partyId = 0;
    this.scope = '';
    this.categoryId = 0;
    this.currencyId = this.file().contract_currency_id ?? 0;
    this.agreed = null;
    this.discount = null;
    this.status = 'open';
    this.notes = '';
    this.more.set(false);
    this.editing.set(0);
  }

  protected startEdit(row: Agreement): void {
    this.partyId = row.contractor_party_id;
    this.editedName = row.contractor?.name ?? '';
    this.scope = row.scope;
    this.categoryId = row.project_cost_category_id ?? 0;
    this.currencyId = row.currency_id;
    this.agreed = Number(row.agreed_value);
    this.discount = Number(row.discount) || null;
    this.status = row.status;
    this.notes = row.notes ?? '';
    this.more.set(!!(this.notes || this.categoryId));
    this.editing.set(row.id);
  }

  protected partyLabel(): string {
    return this.editedName;
  }

  protected net(): number {
    return Math.max(0, (this.agreed ?? 0) - (this.discount ?? 0));
  }

  protected async submit(): Promise<void> {
    const id = this.editing();
    if (id === null || this.agreed === null) return;
    const draft: AgreementDraft = {
      contractor_party_id: this.partyId,
      scope: this.scope.trim(),
      currency_id: this.currencyId,
      agreed_value: this.agreed,
      discount: this.discount ?? 0,
      notes: this.notes.trim() || null,
    };
    if (this.categoryId) draft.project_cost_category_id = this.categoryId;
    if (id) draft.status = this.status;
    else draft.project_id = this.file().project_id;
    const done = await this.act(() => (id ? this.api.updateAgreement(id, draft) : this.api.createAgreement(draft)), 'finSaved');
    if (done) {
      this.editing.set(null);
      if (this.open()?.id === id) this.open.set(null);
      await this.load();
    }
  }

  protected async cancelAgreement(): Promise<void> {
    const id = this.editing();
    if (!id || !(await this.askConfirm({ message: 'fpjCancelAgreementConfirm', tone: 'danger', confirm: 'confirmProceed' }))) return;
    const done = await this.act(() => this.api.updateAgreement(id, { status: 'cancelled' }), 'fpjAgreementCancelled');
    if (done) {
      this.editing.set(null);
      await this.load();
    }
  }

  protected async remove(): Promise<void> {
    const id = this.editing();
    if (!id || !(await this.askDelete('fpjDeleteAgreementConfirm'))) return;
    const done = await this.act(() => this.api.deleteAgreement(id), 'finDeleted');
    if (done) {
      this.editing.set(null);
      if (this.open()?.id === id) this.open.set(null);
      await this.load();
    }
  }
}
