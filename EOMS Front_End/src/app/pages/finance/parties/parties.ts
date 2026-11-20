import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { statusOf } from '../../../core/api/api';
import { FinanceParties, PartyCard } from '../../../data/finance-parties';
import { PartyRole, VoucherPurpose } from '../../../data/finance';
import { Notice } from '../../../shared/notice/notice';
import { FinanceBase } from '../finance-base';
import { FinPartiesList } from './party-list/party-list';
import { FinPartyCard } from './party-card/party-card';
import { FinPartyForm } from './party-form/party-form';
import { FinPartyImport } from './party-import/party-import';
import { FinPartners } from './partners/partners';

type Panel = 'none' | 'new' | 'edit' | 'import';

/**
 * Finance area: parties (جهات التعامل). Two tabs: the parties list (a row opens the party's page in place, `?id=`)
 * and the partners with their shares (`?view=partners`). The party form and the CSV import open as working sheets.
 */
@Component({
  selector: 'app-fin-parties',
  imports: [Notice, RouterLink, FinPartiesList, FinPartyCard, FinPartyForm, FinPartyImport, FinPartners],
  templateUrl: './parties.html',
  styleUrl: './parties.scss',
})
export class FinPartiesPage extends FinanceBase {
  private readonly parties = inject(FinanceParties);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly view = computed<'parties' | 'partners'>(() => (this.params()?.get('view') === 'partners' ? 'partners' : 'parties'));
  protected readonly id = computed(() => {
    const value = Number(this.params()?.get('id') ?? 0);
    return this.view() === 'parties' && Number.isInteger(value) && value > 0 ? value : null;
  });
  /** `?employee=<id>` narrows the list to the party linked to that employee file. */
  protected readonly employee = computed(() => Number(this.params()?.get('employee') ?? 0) || null);

  protected readonly panel = signal<Panel>('none');
  protected readonly card = signal<PartyCard | null>(null);
  protected readonly missing = signal(false);
  /** Bumped after an import or a save so the list reloads. */
  protected readonly listVersion = signal(0);
  protected readonly partnerSheet = signal(false);

  protected readonly mayView = this.may('finance.view');
  protected readonly mayEdit = this.may('finance.vouchers');

  constructor() {
    super();
    effect(() => {
      const id = this.id();
      untracked(() => {
        this.panel.set('none');
        this.card.set(null);
        this.missing.set(false);
        if (id && this.mayView) void this.loadCard(id);
      });
    });
  }

  protected async loadCard(id: number): Promise<void> {
    try {
      const card = await this.parties.card(id);
      if (this.id() === id) this.card.set(card);
    } catch (error) {
      if (statusOf(error) === 404) this.missing.set(true);
      else this.office.fail(error);
    }
  }

  protected roleWords(roles: PartyRole[]): string {
    return roles.map((role) => this.roleLabel(role)).join('، ');
  }

  /** The voucher deep link for "receive from" / "pay to", with the purpose that fits the party's role. */
  protected voucherLink(card: PartyCard, type: 'receipt' | 'payment'): Record<string, string | number> {
    const has = (role: PartyRole) => card.roles.includes(role);
    let purpose: VoucherPurpose = 'debt';
    if (type === 'receipt' && has('client')) purpose = 'client_payment';
    if (type === 'payment' && has('supplier')) purpose = 'supplier_payment';
    else if (type === 'payment' && has('contractor')) purpose = 'contractor_payment';
    return { new: type, purpose, party: card.id };
  }

  protected toggle(panel: Panel): void {
    this.panel.set(this.panel() === panel ? 'none' : panel);
  }

  protected async saved(card: PartyCard): Promise<void> {
    const wasNew = this.panel() === 'new';
    this.panel.set('none');
    this.listVersion.update((n) => n + 1);
    void this.finance.loadParties(true);
    if (wasNew) {
      await this.router.navigate([], { queryParams: { id: card.id } });
    } else {
      await this.loadCard(card.id);
    }
  }

  protected imported(): void {
    this.listVersion.update((n) => n + 1);
    void this.finance.loadParties(true);
  }

  protected changed(): void {
    const id = this.id();
    this.listVersion.update((n) => n + 1);
    void this.finance.loadParties(true);
    if (id) void this.loadCard(id);
  }
}
