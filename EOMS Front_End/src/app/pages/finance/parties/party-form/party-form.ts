import { Component, computed, inject, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { FinanceParties, PartyCard, PartyDraft, TrackingMode } from '../../../../data/finance-parties';
import { PartyRole, partyRoles } from '../../../../data/finance';
import { FinanceBase } from '../../finance-base';

/**
 * Create or edit a party as a working sheet: name, roles (at least one) and phone up front; the rest behind
 * "تفاصيل إضافية". The contractor's tracking mode shows only when the contractor role is ticked (empty = company default).
 */
@Component({
  selector: 'app-fin-party-form',
  imports: [FormsModule],
  templateUrl: './party-form.html',
  styleUrl: './party-form.scss',
  host: { class: 'ledger' },
})
export class FinPartyForm extends FinanceBase implements OnInit {
  private readonly parties = inject(FinanceParties);

  /** The party to edit; null creates one. */
  readonly party = input<PartyCard | null>(null);
  readonly saved = output<PartyCard>();
  readonly closed = output<void>();

  protected readonly roles = partyRoles;
  protected readonly more = signal(false);
  protected readonly picked = signal<PartyRole[]>([]);
  protected readonly contractor = computed(() => this.picked().includes('contractor'));

  protected name = '';
  protected phone = '';
  protected address = '';
  protected notes = '';
  protected trade = '';
  protected employeeId = 0;
  protected wallet = '';
  protected walletName = '';
  protected tracking: TrackingMode | '' = '';

  /** Employees still in service, plus the one already linked. */
  protected readonly employees = computed(() => {
    const linked = String(this.party()?.employee_id ?? '');
    return this.office
      .employees()
      .filter((item) => item.status !== 'ended' || item.id === linked)
      .map((item) => ({ id: Number(item.id), name: this.text(item.name) }))
      .filter((item) => Number.isInteger(item.id) && item.id > 0)
      .sort((a, b) => a.name.localeCompare(b.name, 'ar'));
  });

  ngOnInit(): void {
    const party = this.party();
    if (!party) return;
    this.name = party.name;
    this.picked.set([...party.roles]);
    this.phone = party.phone ?? '';
    this.address = party.address ?? '';
    this.notes = party.notes ?? '';
    this.trade = party.trade ?? '';
    this.employeeId = party.employee_id ?? 0;
    this.wallet = party.sham_cash_wallet ?? '';
    this.walletName = party.sham_cash_name ?? '';
    this.tracking = party.contractor_tracking_mode ?? '';
    this.more.set(!!(party.address || party.notes || party.trade || party.employee_id || party.sham_cash_wallet || party.sham_cash_name || party.contractor_tracking_mode));
  }

  protected has(role: PartyRole): boolean {
    return this.picked().includes(role);
  }

  protected flip(role: PartyRole, on: boolean): void {
    this.picked.update((list) => (on ? [...list.filter((item) => item !== role), role] : list.filter((item) => item !== role)));
  }

  protected async submit(): Promise<void> {
    if (!this.name.trim()) return this.warn('fpaNeedName');
    if (!this.picked().length) return this.warn('fpaNeedRole');
    const clean = (value: string) => value.trim() || null;
    const draft: PartyDraft = {
      name: this.name.trim(),
      roles: partyRoles.filter((role) => this.picked().includes(role)),
      phone: clean(this.phone),
      address: clean(this.address),
      notes: clean(this.notes),
      trade: clean(this.trade),
      employee_id: this.employeeId || null,
      sham_cash_wallet: clean(this.wallet),
      sham_cash_name: clean(this.walletName),
      contractor_tracking_mode: this.contractor() && this.tracking ? this.tracking : null,
    };
    const party = this.party();
    const result = party
      ? await this.act(() => this.parties.update(party.id, draft), 'finSaved')
      : await this.act(() => this.parties.create(draft), 'fpaCreated');
    if (result) this.saved.emit(result);
  }
}
