import { Component, computed, effect, inject, model, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { FinanceParties, Partner, PartnerDraft } from '../../../../data/finance-parties';
import { amount, today } from '../../../../data/finance';
import { FinanceBase } from '../../finance-base';

type ShareState = 'empty' | 'ok' | 'bad';

/** Round to the four places the server keeps, so 33.3333 × 3 + 0.0001 reads as 100. */
function round4(value: number): number {
  return Math.round(value * 10_000) / 10_000;
}

/**
 * Partners and their shares (D16). Rule: the active partners' shares add up to exactly 100%, or are all empty.
 * The sheet lets the user change the other partners' shares in the same save (`other_shares`), so the rule never
 * blocks adding, editing or retiring a partner.
 */
@Component({
  selector: 'app-fin-partners',
  imports: [FormsModule, RouterLink],
  templateUrl: './partners.html',
  styleUrl: './partners.scss',
  host: { class: 'ledger' },
})
export class FinPartners extends FinanceBase {
  private readonly parties = inject(FinanceParties);

  /** The page's "add partner" button opens the sheet through this. */
  readonly adding = model(false);
  readonly changed = output<void>();

  protected readonly mayEdit = this.may('finance.vouchers');
  protected readonly rows = signal<Partner[]>([]);
  protected readonly loading = signal(true);
  protected readonly editing = signal<Partner | null>(null);
  protected readonly open = computed(() => this.mayEdit && (this.adding() || this.editing() !== null));

  // Sheet fields
  protected partyId = 0;
  protected readonly share = signal<number | null>(null);
  protected joined = '';
  protected readonly active = signal(true);
  protected readonly others = signal<Record<number, number | null>>({});

  /** Active parties not yet registered as partners; those already holding the partner role first. */
  protected readonly candidates = computed(() => {
    const taken = new Set(this.rows().map((row) => row.party_id));
    return this.finance
      .partiesWith()
      .filter((party) => !taken.has(party.id))
      .sort((a, b) => Number(b.roles.includes('partner')) - Number(a.roles.includes('partner')) || a.name.localeCompare(b.name, 'ar'));
  });

  /** The active partners other than the one in the sheet: their shares can be changed in the same save. */
  protected readonly otherRows = computed(() => {
    const self = this.editing()?.id;
    return this.rows().filter((row) => row.is_active && row.id !== self);
  });

  protected readonly total = computed(() => this.sum(this.rows().filter((row) => row.is_active).map((row) => this.shareOf(row.share_pct))));

  /** The total the server will check after this save. */
  protected readonly after = computed(() => {
    const others = this.otherRows().map((row) => this.otherShare(row));
    const self = this.active() ? [this.share()] : [];
    return this.sum([...self, ...others]);
  });

  constructor() {
    super();
    void this.load();
    if (this.mayEdit) void this.finance.loadParties();
    effect(() => {
      if (this.adding()) untracked(() => this.blank());
    });
  }

  private async load(): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => this.rows.set(await this.parties.partners()));
    this.loading.set(false);
  }

  private shareOf(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null;
    const n = amount(value as string);
    return n > 0 ? n : null;
  }

  private sum(shares: (number | null)[]): { state: ShareState; value: number } {
    const filled = shares.filter((item): item is number => item !== null);
    if (!filled.length) return { state: 'empty', value: 0 };
    const value = round4(filled.reduce((a, b) => a + b, 0));
    return { state: filled.length === shares.length && value === 100 ? 'ok' : 'bad', value };
  }

  protected otherShare(row: Partner): number | null {
    const changed = this.others();
    return row.id in changed ? changed[row.id] : this.shareOf(row.share_pct);
  }

  protected setOther(row: Partner, value: number | null | string): void {
    const share = value === '' || value === null ? null : Number(value);
    this.others.update((map) => ({ ...map, [row.id]: share }));
  }

  protected setShare(value: number | null | string): void {
    this.share.set(value === '' || value === null ? null : Number(value));
  }

  protected partyLabel(row: Partner): string {
    return row.party?.name ?? this.finance.partyName(row.party_id);
  }

  protected shareText(value: unknown): string {
    const share = this.shareOf(value);
    return share === null ? this.t('fpaShareEmpty') : `${this.qty(share)}%`;
  }

  /** A blank sheet for a new partner (the page heading's button sets `adding`). */
  private blank(): void {
    this.editing.set(null);
    this.partyId = 0;
    this.share.set(null);
    this.joined = today();
    this.active.set(true);
    this.others.set({});
  }

  protected startEdit(row: Partner): void {
    this.adding.set(false);
    this.editing.set(row);
    this.share.set(this.shareOf(row.share_pct));
    this.joined = row.joined_at ?? '';
    this.active.set(row.is_active);
    this.others.set({});
  }

  protected close(): void {
    this.adding.set(false);
    this.editing.set(null);
  }

  protected async submit(): Promise<void> {
    const editing = this.editing();
    if (!editing && !this.partyId) return this.warn('fpaNeedParty');
    const otherShares: Record<number, number | null> = {};
    for (const [id, share] of Object.entries(this.others())) {
      const row = this.rows().find((item) => item.id === Number(id));
      if (row && this.shareOf(row.share_pct) !== share) otherShares[row.id] = share;
    }
    const draft: PartnerDraft = {
      share_pct: this.share(),
      joined_at: this.joined || null,
      is_active: this.active(),
      ...(Object.keys(otherShares).length ? { other_shares: otherShares } : {}),
    };
    const result = editing
      ? await this.act(() => this.parties.updatePartner(editing.id, draft), 'fpaPartnerSaved')
      : await this.act(() => this.parties.createPartner({ ...draft, party_id: this.partyId }), 'fpaPartnerSaved');
    if (!result) return;
    this.close();
    await this.load();
    void this.finance.loadParties(true);
    this.changed.emit();
  }
}
