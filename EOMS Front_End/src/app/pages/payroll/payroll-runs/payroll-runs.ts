import { Component, computed, effect, ElementRef, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { saveFile, revealed } from '../../../core/api/api';
import { CopyKey } from '../../../core/copy';
import { amount, Money, today } from '../../../data/finance';
import {
  ItemStatus,
  PayLineDetail,
  PayMethod,
  PayRunDetail,
  PayRunItem,
  PayRunLine,
  PayRunSummary,
  Payroll,
  RunStatus,
  ShamExport,
  ShamStatus,
  totalsList,
} from '../../../data/payroll';
import { FinanceBase } from '../../finance/finance-base';

type Panel = 'none' | 'line' | 'return' | 'new';
type LineFilter = 'all' | 'blocked' | 'unpaid' | 'paid';
type PayDialog = 'none' | 'cash' | 'sham';

const kindKey: Record<string, CopyKey> = {
  salary: 'prKindSalary',
  days_worked: 'prKindDays',
  hours_worked: 'prKindHours',
  paid_leave: 'prKindPaidLeave',
  holiday_pay: 'prKindHoliday',
  overtime: 'prKindOvertime',
  bonus: 'prKindBonus',
  unpaid_leave: 'prKindUnpaidLeave',
  partial_pay_leave: 'prKindPartialLeave',
  late: 'prKindLate',
  early_exit: 'prKindEarly',
  absence: 'prKindAbsence',
  manual_deduction: 'prKindManualDeduction',
  adjustment: 'prKindAdjustment',
};

const itemStatusKey: Record<ItemStatus, CopyKey> = {
  applied: 'prItemApplied',
  proposed: 'prItemProposed',
  approved: 'prItemApproved',
  modified: 'prItemModified',
  waived: 'prItemWaived',
  rejected: 'prItemRejected',
};

const runStatusKey: Record<RunStatus, CopyKey> = {
  draft: 'prStatusDraft',
  submitted: 'prStatusSubmitted',
  approved: 'prStatusApproved',
};

function thisMonth(): string {
  return today().slice(0, 7);
}

/**
 * Monthly pay runs on `/payroll`: HR prepares a draft and sends it to finance, finance reviews and approves,
 * then finance pays each line or confirms a Sham Cash file.
 */
@Component({
  selector: 'app-payroll-runs',
  imports: [FormsModule, RouterLink],
  templateUrl: './payroll-runs.html',
  styleUrl: './payroll-runs.scss',
  host: { class: 'ledger' },
})
export class PayrollRuns extends FinanceBase {
  private readonly payroll = inject(Payroll);
  protected readonly totalsList = totalsList;

  protected readonly runs = signal<PayRunSummary[]>([]);
  protected readonly run = signal<PayRunDetail | null>(null);
  protected readonly line = signal<PayLineDetail | null>(null);
  protected readonly panel = signal<Panel>('none');
  /** True while the line card is playing its exit slide. */
  protected readonly closing = signal(false);
  protected readonly loading = signal(false);
  protected readonly filter = signal<LineFilter>('all');
  protected readonly sham = signal<ShamExport[]>([]);
  /** Line ids selected inside the open pay dialog (cash or Sham Cash). */
  protected readonly selectedPay = signal<ReadonlySet<number>>(new Set());
  protected readonly payDialog = signal<PayDialog>('none');
  private readonly cashDlg = viewChild<ElementRef<HTMLDialogElement>>('cashDlg');
  private readonly shamDlg = viewChild<ElementRef<HTMLDialogElement>>('shamDlg');

  protected newMonth = thisMonth();
  protected returnNote = '';

  // Line actions
  protected itemCategory: 'earning' | 'deduction' = 'earning';
  protected itemAmount: number | null = null;
  protected itemReason = '';
  protected reviewing: PayRunItem | null = null;
  protected reviewDecision: 'approve' | 'modify' | 'waive' = 'approve';
  protected reviewAmount: number | null = null;
  protected reviewReason = '';
  protected adjustReason = '';

  // Payment
  protected payCurrency = 'SYP';
  protected payMethod: PayMethod = 'cash';
  protected payBox = 0;
  protected payDate = today();
  protected payReference = '';
  protected payNote = '';
  protected voidDate = today();
  protected voidReason = '';

  // Bulk cash payment (shared box/date for every selected unpaid line).
  protected bulkCurrency = 'SYP';
  protected bulkBox = 0;
  protected bulkDate = today();
  protected bulkNote = '';

  // Sham Cash file, after the run is approved.
  protected shamCurrency = 'SYP';
  protected shamDate = today();
  protected shamSkip = false;
  protected shamBox: Record<number, number> = {};

  protected get mayManage(): boolean {
    return this.session.has('payroll.manage');
  }

  protected get mayApprove(): boolean {
    return this.session.has('payroll.approve');
  }

  /** The server refuses to let the account that prepared a run approve it. */
  protected ownRun(run: { prepared_by: number }): boolean {
    return run.prepared_by === this.session.account().userId;
  }

  protected get mayPay(): boolean {
    return this.session.has('payroll.pay');
  }

  protected readonly lines = computed(() => {
    const rows = this.run()?.lines ?? [];
    switch (this.filter()) {
      case 'blocked':
        return rows.filter((row) => this.blocked(row));
      case 'unpaid':
        return rows.filter((row) => !row.paid && amount(row.net) > 0);
      case 'paid':
        return rows.filter((row) => row.paid);
      default:
        return rows;
    }
  });

  /** Unpaid, unblocked lines that can be paid (cash or Sham Cash file). */
  protected readonly payPayable = computed(() => {
    const run = this.run();
    if (!run || run.status !== 'approved' || !this.mayPay) return [];
    return run.lines.filter((row) => this.canSelectPay(row));
  });

  protected readonly paySelectedCount = computed(() => this.selectedPay().size);

  protected readonly allPaySelected = computed(() => {
    const rows = this.payPayable();
    if (!rows.length) return false;
    const selected = this.selectedPay();
    return rows.every((row) => selected.has(row.id));
  });

  protected readonly somePaySelected = computed(() => {
    const rows = this.payPayable();
    const selected = this.selectedPay();
    return rows.some((row) => selected.has(row.id)) && !this.allPaySelected();
  });

  /** The payroll currencies the server accepts (`is_payroll_currency`, active), from the currency settings. */
  protected readonly payCurrencies = computed(() => {
    const codes = this.finance.activeCurrencies().filter((item) => item.is_payroll_currency).map((item) => item.code).sort();
    return codes.length ? codes : ['SYP', 'USD'];
  });

  /** Active finance boxes; the pay currency must be enabled on the box (backend 422 on `box_id` otherwise). */
  protected readonly payBoxes = computed(() => this.finance.boxes().filter((box) => box.is_active));

  constructor() {
    super();
    effect(() => {
      const mode = this.payDialog();
      const cash = this.cashDlg()?.nativeElement;
      const sham = this.shamDlg()?.nativeElement;
      if (cash) {
        if (mode === 'cash' && !cash.open) cash.showModal();
        if (mode !== 'cash' && cash.open) cash.close();
      }
      if (sham) {
        if (mode === 'sham' && !sham.open) sham.showModal();
        if (mode !== 'sham' && sham.open) sham.close();
      }
    });
    void this.start();
  }

  protected openCashDialog(): void {
    this.selectedPay.set(new Set(this.payPayable().map((row) => row.id)));
    this.payDialog.set('cash');
  }

  protected openShamDialog(): void {
    this.selectedPay.set(new Set(this.payPayable().map((row) => row.id)));
    this.payDialog.set('sham');
  }

  protected closePayDialog(): void {
    this.payDialog.set('none');
  }

  protected personInitials(name: string | null): string {
    const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '—';
    return parts
      .slice(0, 2)
      .map((part) => part[0] ?? '')
      .join('');
  }

  private async start(): Promise<void> {
    await this.loadRuns();
    const first = this.runs()[0];
    if (first) await this.open(first.id);
    // Paying needs the finance boxes; a user without finance access simply sees no boxes.
    try {
      await Promise.all([this.finance.loadBoxes(), this.finance.loadCurrencies()]);
    } catch {
      /* no finance access */
    }
  }

  protected async loadRuns(): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => this.runs.set((await this.payroll.runs()).data));
    this.loading.set(false);
  }

  protected async open(id: number): Promise<void> {
    await this.quiet(async () => {
      const detail = await this.payroll.run(id);
      this.run.set(detail);
      this.line.set(null);
      this.selectedPay.set(new Set());
      this.payDialog.set('none');
      if (this.panel() === 'line') this.panel.set('none');
      await this.loadSham(detail);
    });
  }

  /** Reloads the run and its summary row after any change, and the open line if one is shown. */
  private async refresh(): Promise<void> {
    const current = this.run();
    if (!current) return;
    await this.quiet(async () => {
      const detail = await this.payroll.run(current.id);
      this.run.set(detail);
      this.runs.update((rows) => rows.map((row) => (row.id === detail.id ? detail : row)));
      this.prunePaySelection(detail);
      const open = this.line();
      if (open && this.panel() === 'line') this.line.set(await this.payroll.line(detail.id, open.id));
      await this.loadSham(detail);
    });
  }

  private prunePaySelection(detail: PayRunDetail): void {
    const ok = new Set(detail.lines.filter((row) => this.canSelectPay(row)).map((row) => row.id));
    this.selectedPay.update((selected) => new Set([...selected].filter((id) => ok.has(id))));
  }

  protected canSelectPay(row: PayRunLine): boolean {
    return !row.paid && amount(row.net) > 0 && !this.blocked(row);
  }

  protected payPicked(id: number): boolean {
    return this.selectedPay().has(id);
  }

  protected togglePay(row: PayRunLine, on: boolean): void {
    if (!this.canSelectPay(row)) return;
    this.selectedPay.update((selected) => {
      const next = new Set(selected);
      if (on) next.add(row.id);
      else next.delete(row.id);
      return next;
    });
  }

  protected toggleAllPay(on: boolean): void {
    if (!on) {
      this.selectedPay.set(new Set());
      return;
    }
    this.selectedPay.set(new Set(this.payPayable().map((row) => row.id)));
  }

  /** Cash boxes for the bulk form (never e-wallet unless that is all that holds the currency). */
  protected bulkBoxes(): { id: number; name: string; currency_code: string }[] {
    const holding = this.finance.boxesWith(this.bulkCurrency);
    const cash = holding.filter((box) => box.type !== 'e_wallet');
    return (cash.length ? cash : holding).map((box) => ({ id: box.id, name: box.name, currency_code: this.bulkCurrency }));
  }

  private async loadSham(run: PayRunDetail): Promise<void> {
    if (!this.mayPay || run.status !== 'approved') {
      this.sham.set([]);
      return;
    }
    try {
      const rows = await this.payroll.shamExports(run.id);
      this.sham.set(rows);
      for (const row of rows) if (this.shamBox[row.id] == null) this.shamBox[row.id] = 0;
    } catch {
      this.sham.set([]);
    }
  }

  /** Boxes a Sham Cash file can be paid from: e-wallet boxes holding the currency, or any box holding it if none is an e-wallet. */
  protected shamBoxes(currency: string): { id: number; name: string; currency_code: string }[] {
    const holding = this.finance.boxesWith(currency);
    const wallets = holding.filter((box) => box.type === 'e_wallet');
    return (wallets.length ? wallets : holding).map((box) => ({ id: box.id, name: box.name, currency_code: currency }));
  }

  protected shamLabel(status: ShamStatus): string {
    const key: Record<ShamStatus, CopyKey> = { open: 'prShamOpen', confirmed: 'prShamConfirmed', cancelled: 'prShamCancelled' };
    return this.t(key[status]);
  }

  protected shamTone(status: ShamStatus): string {
    if (status === 'confirmed') return 'ok';
    if (status === 'open') return 'wait';
    return 'mute';
  }

  protected async issueSham(): Promise<void> {
    const run = this.run();
    const ids = [...this.selectedPay()];
    if (!run) return;
    if (!ids.length) {
      this.warn('prNoneSelected');
      return;
    }
    this.busy.set(true);
    try {
      const file = await this.payroll.shamCash(run.id, {
        currency: this.shamCurrency,
        date: this.shamDate,
        skip_missing: this.shamSkip,
        line_ids: ids,
      });
      saveFile(file.blob, `shamcash_${file.docId ?? run.month}.xlsx`);
      this.office.done('prShamIssued');
      await this.loadSham(run);
      await this.refresh();
    } catch (error) {
      this.office.fail(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async downloadSham(file: ShamExport): Promise<void> {
    this.busy.set(true);
    try {
      saveFile(await this.payroll.shamFile(file.id), `shamcash_${file.doc_id}.xlsx`);
    } catch (error) {
      this.office.fail(await revealed(error));
    } finally {
      this.busy.set(false);
    }
  }

  protected async confirmSham(file: ShamExport): Promise<void> {
    const box = this.shamBox[file.id] ?? 0;
    if (!box) {
      this.warn('prPayIncomplete');
      return;
    }
    const run = this.run();
    const ok = await this.act(() => this.payroll.confirmSham(file.id, box), 'prShamConfirmedDone');
    if (ok && run) {
      await this.refresh();
    }
  }

  protected async cancelSham(file: ShamExport): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', tone: 'danger', confirm: 'confirmProceed' }))) return;
    const run = this.run();
    const ok = await this.act(() => this.payroll.cancelSham(file.id), 'prShamCancelledDone');
    if (ok && run) await this.loadSham(run);
  }

  protected runLabel(status: RunStatus): string {
    return this.t(runStatusKey[status]);
  }

  protected itemKind(kind: string): string {
    const key = kindKey[kind];
    return key ? this.t(key) : kind;
  }

  protected itemStatus(status: ItemStatus): string {
    return this.t(itemStatusKey[status]);
  }

  protected runTone(status: RunStatus): string {
    return status === 'approved' ? 'ok' : status === 'submitted' ? 'wait' : 'mute';
  }

  protected stepDone(step: RunStatus): boolean {
    const order: RunStatus[] = ['draft', 'submitted', 'approved'];
    const run = this.run();
    return !!run && order.indexOf(run.status) > order.indexOf(step);
  }

  protected blocked(row: PayRunLine): boolean {
    return !row.attendance_ready || row.issues.some((issue) => issue.blocking);
  }

  protected payTypeLabel(type: string | null): string {
    if (type === 'monthly') return this.t('prPayMonthly');
    if (type === 'daily') return this.t('prPayDaily');
    if (type === 'hourly') return this.t('prPayHourly');
    return '—';
  }

  protected withCurrency(value: Money | null | undefined, currency: string | null): string {
    return `${this.money(value)} ${currency ?? ''}`.trim();
  }

  // Run workflow

  protected async create(): Promise<void> {
    if (!/^\d{4}-\d{2}$/.test(this.newMonth)) return;
    let created: PayRunDetail | null = null;
    const ok = await this.act(async () => {
      created = await this.payroll.create(this.newMonth);
    }, 'prCreated');
    if (!ok) return;
    this.panel.set('none');
    await this.loadRuns();
    const made = created as PayRunDetail | null;
    if (made) this.run.set(made);
  }

  protected async recalculate(): Promise<void> {
    const run = this.run();
    if (!run) return;
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    const ok = await this.act(() => this.payroll.recalculate(run.id), 'prRecalculated');
    if (ok) await this.refresh();
  }

  protected async submit(): Promise<void> {
    const run = this.run();
    if (!run) return;
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    const ok = await this.act(() => this.payroll.submit(run.id), 'prSubmitted');
    if (ok) await this.refresh();
  }

  protected startReturn(): void {
    this.returnNote = '';
    this.panel.set('return');
  }

  protected async returnRun(): Promise<void> {
    const run = this.run();
    if (!run || this.returnNote.trim().length < 3) {
      this.warn('prReturnNoteNeeded');
      return;
    }
    const ok = await this.act(() => this.payroll.returnToDraft(run.id, this.returnNote.trim()), 'prReturned');
    if (!ok) return;
    this.panel.set('none');
    await this.refresh();
  }

  protected async approve(): Promise<void> {
    const run = this.run();
    if (!run) return;
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    const ok = await this.act(() => this.payroll.approve(run.id), 'prApproved');
    if (ok) await this.refresh();
  }

  protected async exportRun(): Promise<void> {
    const run = this.run();
    if (!run) return;
    this.busy.set(true);
    try {
      saveFile(await this.payroll.exportRun(run.id), `payroll-${run.month}.csv`);
    } catch (error) {
      this.office.fail(await revealed(error));
    } finally {
      this.busy.set(false);
    }
  }

  // One employee's line

  protected async openLine(row: PayRunLine): Promise<void> {
    const run = this.run();
    if (!run) return;
    this.closing.set(false);
    await this.quiet(async () => {
      const detail = await this.payroll.line(run.id, row.id);
      this.line.set(detail);
      this.reviewing = null;
      this.itemAmount = null;
      this.itemReason = '';
      this.adjustReason = '';
      this.payCurrency = detail.currency_code ?? 'SYP';
      this.payMethod = detail.sham_cash_wallet ? 'sham_cash' : 'cash';
      this.payBox = 0;
      this.payDate = today();
      this.payReference = '';
      this.payNote = '';
      this.voidReason = '';
      this.panel.set('line');
    });
  }

  protected closeLine(): void {
    if (!this.line() || this.closing()) return;
    if (typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches) {
      this.line.set(null);
      this.panel.set('none');
      return;
    }
    this.closing.set(true);
  }

  protected finishClose(event?: AnimationEvent): void {
    // Angular emulates view encapsulation by prefixing keyframe names.
    if (event && !String(event.animationName).includes('pr-card-out')) return;
    if (!this.closing()) return;
    this.closing.set(false);
    this.line.set(null);
    this.panel.set('none');
  }

  /** Boxes that hold the pay currency: e-wallets for Sham Cash, the other boxes for cash (any holder when that leaves none). */
  protected boxesFor(): { id: number; name: string; currency_code: string }[] {
    const holding = this.finance.boxesWith(this.payCurrency);
    const wallets = holding.filter((box) => box.type === 'e_wallet');
    const fitting = this.payMethod === 'sham_cash' ? wallets : holding.filter((box) => box.type !== 'e_wallet');
    return (fitting.length ? fitting : holding).map((box) => ({ id: box.id, name: box.name, currency_code: this.payCurrency }));
  }

  protected async addItem(): Promise<void> {
    const run = this.run();
    const line = this.line();
    if (!run || !line || amount(this.itemAmount) <= 0 || this.itemReason.trim().length < 3) {
      this.warn('prItemIncomplete');
      return;
    }
    const ok = await this.act(
      () => this.payroll.addItem(run.id, { employee_id: line.employee_id, category: this.itemCategory, amount: amount(this.itemAmount), reason: this.itemReason.trim() }),
      'prItemAdded',
    );
    if (!ok) return;
    this.itemAmount = null;
    this.itemReason = '';
    await this.refresh();
  }

  protected async removeItem(item: PayRunItem): Promise<void> {
    if (!(await this.askDelete())) return;
    const ok = await this.act(() => this.payroll.removeItem(item.id), 'prItemRemoved');
    if (ok) await this.refresh();
  }

  protected async decideAdjustment(item: PayRunItem, decision: 'accept' | 'reject'): Promise<void> {
    if (this.adjustReason.trim().length < 3) {
      this.warn('prReasonNeeded');
      return;
    }
    const ok = await this.act(() => this.payroll.decideAdjustment(item.id, decision, this.adjustReason.trim()), 'prItemDecided');
    if (!ok) return;
    this.adjustReason = '';
    await this.refresh();
  }

  protected startReview(item: PayRunItem): void {
    this.reviewing = item;
    this.reviewDecision = 'approve';
    this.reviewAmount = amount(item.proposed_amount);
    this.reviewReason = '';
  }

  protected async saveReview(): Promise<void> {
    const item = this.reviewing;
    if (!item) return;
    const decision = this.reviewDecision;
    if (decision !== 'approve' && this.reviewReason.trim().length < 3) {
      this.warn('prReasonNeeded');
      return;
    }
    if (decision === 'modify' && (this.reviewAmount === null || amount(this.reviewAmount) < 0 || amount(this.reviewAmount) > amount(item.proposed_amount))) {
      this.warn('prModifyRange');
      return;
    }
    const ok = await this.act(
      () => this.payroll.reviewDeduction(item.id, decision, decision === 'modify' ? amount(this.reviewAmount) : null, this.reviewReason.trim() || null),
      'prItemDecided',
    );
    if (!ok) return;
    this.reviewing = null;
    await this.refresh();
  }

  protected canRemove(item: PayRunItem): boolean {
    return item.origin === 'manual' && this.run()?.status === 'draft' && this.mayManage;
  }

  protected canDecideAdjustment(item: PayRunItem): boolean {
    return item.origin === 'adjustment' && item.status === 'proposed' && this.run()?.status === 'submitted' && this.mayApprove;
  }

  protected canReview(item: PayRunItem): boolean {
    return (item.origin === 'proposed' || item.origin === 'manual') && item.status === 'proposed' && this.run()?.status === 'submitted' && this.mayApprove;
  }

  protected hasPendingAdjustment(line: PayLineDetail): boolean {
    return line.items.some((item) => this.canDecideAdjustment(item));
  }

  protected proposedOf(item: PayRunItem): number {
    return amount(item.proposed_amount);
  }

  protected canPay(line: PayLineDetail): boolean {
    return this.run()?.status === 'approved' && this.mayPay && !line.payment && amount(line.net) > 0;
  }

  protected async pay(): Promise<void> {
    const line = this.line();
    if (!line || !this.payBox) {
      this.warn('prPayIncomplete');
      return;
    }
    if (this.payMethod === 'sham_cash' && !this.payReference.trim()) {
      this.warn('prReferenceNeeded');
      return;
    }
    const ok = await this.act(
      () =>
        this.payroll.pay(line.id, {
          currency: this.payCurrency,
          method: this.payMethod,
          box_id: this.payBox,
          paid_at: this.payDate,
          reference: this.payReference.trim() || null,
          note: this.payNote.trim() || null,
        }),
      'prPaid',
    );
    if (!ok) return;
    await Promise.all([this.refresh(), this.finance.loadBoxes().catch(() => undefined)]);
  }

  /** Records cash payment for every selected unpaid line with the shared box and date. */
  protected async paySelectedCash(): Promise<void> {
    const ids = [...this.selectedPay()];
    if (!ids.length) {
      this.warn('prNoneSelected');
      return;
    }
    if (!this.bulkBox) {
      this.warn('prPayIncomplete');
      return;
    }
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;

    this.busy.set(true);
    try {
      for (const id of ids) {
        await this.payroll.pay(id, {
          currency: this.bulkCurrency,
          method: 'cash',
          box_id: this.bulkBox,
          paid_at: this.bulkDate,
          reference: null,
          note: this.bulkNote.trim() || null,
        });
      }
      this.office.done('prBulkPaid');
      this.closePayDialog();
    } catch (error) {
      this.office.fail(error);
    } finally {
      this.busy.set(false);
      await Promise.all([this.refresh(), this.finance.loadBoxes().catch(() => undefined)]);
    }
  }

  protected async voidPayment(): Promise<void> {
    const payment = this.line()?.payment;
    if (!payment || this.voidReason.trim().length < 3) {
      this.warn('prReasonNeeded');
      return;
    }
    const ok = await this.act(() => this.payroll.voidPayment(payment.id, this.voidDate, this.voidReason.trim()), 'prVoided');
    if (!ok) return;
    this.voidReason = '';
    await Promise.all([this.refresh(), this.finance.loadBoxes().catch(() => undefined)]);
  }
}
