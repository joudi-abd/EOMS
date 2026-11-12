import { NgTemplateOutlet } from '@angular/common';
import { Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { revealed, saveFile } from '../../../core/api/api';
import { amount, cleanQuery, FinAttachment, FinInvoice, FinParty, InvoiceInput, InvoiceStatus, InvoiceType, PartyKind, today, unitsFor } from '../../../data/finance';
import { FinanceTab } from '../finance-tab';

interface DraftLine {
  item_id: number;
  account_id: number;
  description: string;
  unit: string;
  quantity: number | null;
  unit_price: number | null;
}

type Panel = 'none' | 'editor' | 'view' | 'cancel' | 'party';

function blankLine(): DraftLine {
  return { item_id: 0, account_id: 0, description: '', unit: '', quantity: 1, unit_price: null };
}

function plusDays(iso: string, days: number): string {
  const date = new Date(`${iso}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

/** Sale and purchase invoices, their approval, payments and cancellation, plus the customers and suppliers they name. */
@Component({
  selector: 'app-finance-invoices',
  imports: [FormsModule, NgTemplateOutlet],
  templateUrl: './finance-invoices.html',
  styleUrl: './finance-invoices.scss',
  host: { class: 'ledger' },
})
export class FinanceInvoices extends FinanceTab {
  protected readonly unitsFor = unitsFor;
  protected readonly statuses: InvoiceStatus[] = ['draft', 'pending', 'posted', 'cancelled'];
  protected readonly rows = signal<FinInvoice[]>([]);
  protected readonly page = signal(1);
  protected readonly lastPage = signal(1);
  protected readonly loading = signal(false);
  protected readonly panel = signal<Panel>('none');
  protected readonly current = signal<FinInvoice | null>(null);

  protected filterType = '';
  protected filterStatus = '';
  protected filterParty = 0;
  protected filterOverdue = false;

  // Editor
  protected editingId: number | null = null;
  protected editingVersion = 0;
  protected readonly type = signal<InvoiceType>('sale');
  protected party = 0;
  protected invDate = today();
  protected dueDate = plusDays(today(), 30);
  protected currency = 'SYP';
  protected notes = '';
  protected readonly lines = signal<DraftLine[]>([blankLine()]);

  // Decision and cancel
  protected decisionNote = '';
  protected cancelDate = today();
  protected cancelReason = '';

  // Parties
  protected readonly partyView = signal<PartyKind>('customer');
  protected partyEditing: FinParty | null = null;
  protected partyName = '';
  protected partyPhone = '';
  protected partyNotes = '';
  protected partyWallet = '';
  protected partyWalletName = '';
  private readonly previews = signal<Record<number, string>>({});
  private previewTicket = 0;

  constructor() {
    super();
    inject(DestroyRef).onDestroy(() => this.clearPreviews());
    void this.load(1);
  }

  protected readonly partiesOfType = computed(() => {
    const kind: PartyKind = this.type() === 'sale' ? 'customer' : 'supplier';
    return this.finance.parties().filter((item) => item.kind === kind && item.active);
  });

  protected readonly partiesListed = computed(() => this.finance.parties().filter((item) => item.kind === this.partyView()));

  protected async load(page: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.finance.invoices(
        cleanQuery({ type: this.filterType, status: this.filterStatus, party_id: this.filterParty, overdue: this.filterOverdue }),
        page,
      );
      this.rows.set(result.data);
      this.page.set(result.meta.current_page);
      this.lastPage.set(result.meta.last_page);
    });
    this.loading.set(false);
  }

  protected resetFilters(): void {
    this.filterType = '';
    this.filterStatus = '';
    this.filterParty = 0;
    this.filterOverdue = false;
    void this.load(1);
  }

  protected close(): void {
    this.clearPreviews();
    this.panel.set('none');
  }

  protected typeLabel(type: InvoiceType): string {
    return type === 'sale' ? this.t('finSale') : this.t('finPurchase');
  }

  protected paymentLabel(row: FinInvoice): string {
    if (row.overdue) return this.t('finOverdue');
    if (row.payment_status === 'paid') return this.t('finPaid');
    if (row.payment_status === 'partial') return this.t('finPartial');
    if (row.payment_status === 'unpaid') return this.t('finUnpaid');
    return '';
  }

  protected paymentTone(row: FinInvoice): string {
    return this.tone(row.overdue ? 'overdue' : (row.payment_status ?? ''));
  }

  // Viewing one invoice

  protected async show(row: FinInvoice): Promise<void> {
    await this.quiet(async () => {
      const invoice = await this.finance.invoice(row.id);
      this.current.set(invoice);
      this.decisionNote = '';
      this.panel.set('view');
      await this.loadPreviews(invoice);
    });
  }

  protected lineLabel(line: { item_id: number | null; account_id: number | null; description: string | null }): string {
    if (line.description) return line.description;
    if (line.item_id) return this.finance.items().find((item) => item.id === line.item_id)?.name ?? `#${line.item_id}`;
    return this.finance.accountName(line.account_id);
  }

  // Editor

  protected startNew(type: InvoiceType): void {
    this.editingId = null;
    this.editingVersion = 0;
    this.type.set(type);
    this.party = 0;
    this.invDate = today();
    this.dueDate = plusDays(today(), 30);
    this.currency = this.records.currencies()[0]?.code ?? 'SYP';
    this.notes = '';
    this.lines.set([blankLine()]);
    this.current.set(null);
    this.clearPreviews();
    this.panel.set('editor');
  }

  protected async startEdit(invoice: FinInvoice): Promise<void> {
    this.editingId = invoice.id;
    this.editingVersion = invoice.version;
    this.type.set(invoice.type);
    this.party = invoice.party_id;
    this.invDate = invoice.date;
    this.dueDate = invoice.due_date;
    this.currency = invoice.currency_code;
    this.notes = invoice.notes ?? '';
    this.lines.set(
      (invoice.lines ?? []).map((line) => ({
        item_id: line.item_id ?? 0,
        account_id: line.account_id ?? 0,
        description: line.description ?? '',
        unit: line.unit ?? '',
        quantity: amount(line.quantity),
        unit_price: amount(line.unit_price),
      })),
    );
    if (!this.lines().length) this.lines.set([blankLine()]);
    this.panel.set('editor');
    await this.quiet(async () => {
      const full = await this.finance.invoice(invoice.id);
      this.current.set(full);
      this.editingVersion = full.version;
      await this.loadPreviews(full);
    });
  }

  protected addLine(): void {
    this.lines.update((rows) => [...rows, blankLine()]);
  }

  protected removeLine(index: number): void {
    this.lines.update((rows) => (rows.length > 1 ? rows.filter((_, i) => i !== index) : rows));
  }

  /** A line names a stock item or an account. Purchases of an item must be in its valuation currency. */
  protected itemsForLine() {
    const list = this.finance.items().filter((item) => item.active);
    return this.type() === 'purchase' ? list.filter((item) => item.currency_code === this.currency) : list;
  }

  protected accountsForLine() {
    return this.type() === 'sale' ? this.finance.postable('revenue') : [...this.finance.postable('expense'), ...this.finance.postable('asset')];
  }

  protected itemPicked(line: DraftLine): void {
    const item = this.finance.items().find((row) => row.id === line.item_id);
    if (item) {
      if (this.type() === 'purchase') line.account_id = 0;
      if (!line.description) line.description = item.name;
      line.unit = item.unit;
    }
    this.lines.update((rows) => [...rows]);
  }

  protected itemUnit(line: DraftLine): string {
    return this.finance.items().find((row) => row.id === line.item_id)?.unit ?? line.unit;
  }

  protected lineAmount(line: DraftLine): number {
    return Math.round(amount(line.quantity) * amount(line.unit_price) * 100) / 100;
  }

  protected total(): number {
    return this.lines().reduce((sum, line) => sum + this.lineAmount(line), 0);
  }

  protected aboveLimit(): boolean {
    const found = this.finance.limits().find((item) => item.currency_code === this.currency);
    return !!found && this.total() > amount(found.amount);
  }

  private body(): InvoiceInput | null {
    const lines = this.lines()
      .filter((line) => amount(line.quantity) > 0 && (line.item_id || line.account_id))
      .map((line) => ({
        item_id: line.item_id || null,
        account_id: line.account_id || null,
        description: line.description.trim() || null,
        ...(line.item_id ? {} : { unit: line.unit.trim() || null }),
        quantity: amount(line.quantity),
        unit_price: amount(line.unit_price),
      }));
    if (!this.party || !lines.length) {
      this.warn('finInvoiceIncomplete');
      return null;
    }
    const body: InvoiceInput = {
      party_id: this.party,
      date: this.invDate,
      due_date: this.dueDate || null,
      currency_code: this.currency,
      notes: this.notes.trim() || null,
      lines,
    };
    if (this.editingId) body.version = this.editingVersion;
    else body.type = this.type();
    return body;
  }

  /** Saves the draft; with `submit`, also sends it on (it posts at once when under the approval limit). */
  protected async save(submit: boolean): Promise<void> {
    const body = this.body();
    if (!body) return;
    let saved: FinInvoice | null = null;
    const ok = await this.act(async () => {
      saved = (await this.finance.saveInvoice(body, this.editingId ?? undefined)).data;
      if (submit && saved) saved = (await this.finance.submitInvoice(saved.id)).data;
    }, submit ? 'finInvoiceSubmitted' : 'finInvoiceSaved');
    const kept = saved as FinInvoice | null;
    if (!ok) {
      // A save that went through before a refused submit leaves a draft with a new version: follow it.
      if (kept) {
        this.editingId = kept.id;
        this.editingVersion = kept.version;
        this.current.set(kept);
      }
      return;
    }
    if (kept) await this.refreshCurrent(kept.id);
  }

  protected async submit(invoice: FinInvoice): Promise<void> {
    const ok = await this.act(() => this.finance.submitInvoice(invoice.id), 'finInvoiceSubmitted');
    if (ok) await this.refreshCurrent(invoice.id);
  }

  protected async decide(invoice: FinInvoice, decision: 'approve' | 'reject'): Promise<void> {
    if (decision === 'reject' && !this.decisionNote.trim()) {
      this.warn('finRejectReason');
      return;
    }
    const note = this.decisionNote.trim() || null;
    const ok = await this.act(() => this.finance.decideInvoice(invoice.id, decision, note), decision === 'approve' ? 'finApproved' : 'finRejected');
    if (ok) await this.refreshCurrent(invoice.id);
  }

  /** The server cancels only a posted invoice with nothing paid; payments are reversed first. */
  protected canCancel(invoice: FinInvoice): boolean {
    return invoice.status === 'posted' && this.canManage && amount(invoice.paid) === 0;
  }

  protected startCancel(): void {
    this.cancelDate = today();
    this.cancelReason = '';
    this.panel.set('cancel');
  }

  protected async cancel(): Promise<void> {
    const invoice = this.current();
    if (!invoice || this.cancelReason.trim().length < 3) return;
    const ok = await this.act(() => this.finance.cancelInvoice(invoice.id, this.cancelDate, this.cancelReason.trim()), 'finInvoiceCancelled');
    if (ok) await this.refreshCurrent(invoice.id);
  }

  private async refreshCurrent(id: number): Promise<void> {
    await this.quiet(async () => {
      const invoice = await this.finance.invoice(id);
      this.current.set(invoice);
      this.panel.set('view');
      await this.loadPreviews(invoice);
    });
    await Promise.all([this.load(this.page()), this.finance.loadItems()]);
  }

  // Parties

  protected openParties(): void {
    this.partyEditing = null;
    this.partyName = '';
    this.partyPhone = '';
    this.partyNotes = '';
    this.partyWallet = '';
    this.partyWalletName = '';
    this.panel.set(this.panel() === 'party' ? 'none' : 'party');
  }

  protected editParty(party: FinParty): void {
    this.partyEditing = party;
    this.partyName = party.name;
    this.partyPhone = party.phone ?? '';
    this.partyNotes = party.notes ?? '';
    this.partyWallet = party.sham_cash_wallet ?? '';
    this.partyWalletName = party.sham_cash_name ?? '';
  }

  protected async saveParty(): Promise<void> {
    if (!this.partyName.trim()) return;
    const wallet = this.partyWallet.replace(/\s/g, '');
    if (wallet && !/^\d{16}$/.test(wallet)) {
      this.warn('finWalletHint');
      return;
    }
    const editing = this.partyEditing;
    const fields = {
      name: this.partyName.trim(),
      phone: this.partyPhone.trim() || null,
      notes: this.partyNotes.trim() || null,
      sham_cash_wallet: wallet || null,
      sham_cash_name: this.partyWalletName.trim() || null,
    };
    const ok = await this.act(
      () => (editing ? this.finance.updateParty(editing.id, fields) : this.finance.createParty({ kind: this.partyView(), ...fields })),
      'finPartySaved',
    );
    if (!ok) return;
    this.partyEditing = null;
    this.partyName = '';
    this.partyPhone = '';
    this.partyNotes = '';
    this.partyWallet = '';
    this.partyWalletName = '';
    await this.finance.loadParties();
  }

  protected fileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  protected async onAttachment(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    const invoice = this.current();
    if (!file || !invoice) return;
    if (file.size > 10 * 1024 * 1024) {
      this.warn('finAttachHint');
      return;
    }
    const ok = await this.act(() => this.finance.uploadAttachment(invoice.id, file), 'finAttachmentAdded');
    if (ok) await this.reloadAttachments(invoice.id);
  }

  protected async downloadAttachment(file: FinAttachment): Promise<void> {
    const invoice = this.current();
    if (!invoice) return;
    this.busy.set(true);
    try {
      saveFile(await this.finance.attachmentFile(invoice.id, file.id), file.original_name);
    } catch (error) {
      this.office.fail(await revealed(error));
    } finally {
      this.busy.set(false);
    }
  }

  protected async deleteAttachment(file: FinAttachment): Promise<void> {
    const invoice = this.current();
    if (!invoice) return;
    const ok = await this.act(() => this.finance.deleteAttachment(invoice.id, file.id), 'finAttachmentDeleted');
    if (ok) await this.reloadAttachments(invoice.id);
  }

  protected preview(id: number): string {
    return this.previews()[id] ?? '';
  }

  private async reloadAttachments(id: number): Promise<void> {
    await this.quiet(async () => {
      const invoice = await this.finance.invoice(id);
      this.current.set(invoice);
      await this.loadPreviews(invoice);
    });
  }

  private async loadPreviews(invoice: FinInvoice): Promise<void> {
    const ticket = ++this.previewTicket;
    this.dropPreviewUrls();
    const next: Record<number, string> = {};
    await Promise.all(
      (invoice.attachments ?? []).filter((file) => this.isImage(file)).map(async (file) => {
        try {
          next[file.id] = URL.createObjectURL(await this.finance.attachmentFile(invoice.id, file.id));
        } catch {
          /* The name still shows when the preview cannot be fetched. */
        }
      }),
    );
    if (ticket !== this.previewTicket) {
      for (const url of Object.values(next)) URL.revokeObjectURL(url);
      return;
    }
    this.previews.set(next);
  }

  private clearPreviews(): void {
    this.previewTicket += 1;
    this.dropPreviewUrls();
  }

  private dropPreviewUrls(): void {
    for (const url of Object.values(this.previews())) URL.revokeObjectURL(url);
    this.previews.set({});
  }

  private isImage(file: FinAttachment): boolean {
    return (file.mime ?? '').startsWith('image/') || /\.(jpe?g|png|webp)$/i.test(file.original_name);
  }

  protected async toggleParty(party: FinParty): Promise<void> {
    const ok = await this.act(() => this.finance.updateParty(party.id, { active: !party.active }), 'finPartySaved');
    if (ok) await this.finance.loadParties();
  }
}
