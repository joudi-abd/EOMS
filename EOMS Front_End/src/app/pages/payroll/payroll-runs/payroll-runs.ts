import { Component, computed, inject, signal } from '@angular/core';
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
  totalsList,
} from '../../../data/payroll';
import { FinanceTab } from '../../finance/finance-tab';

type Panel = 'none' | 'line' | 'return' | 'new';
type LineFilter = 'all' | 'blocked' | 'unpaid' | 'paid';

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
 * Monthly pay runs on `/payroll`: finance prepares a draft from attendance and contracts, the company manager reviews
 * proposed deductions and approves, then finance records each payment (which posts a finance transaction).
 */
@Component({
  selector: 'app-payroll-runs',
  imports: [FormsModule, RouterLink],
  templateUrl: './payroll-runs.html',
  styleUrl: './payroll-runs.scss',
  host: { class: 'ledger' },
})
export class PayrollRuns extends FinanceTab {
  private readonly payroll = inject(Payroll);
  protected readonly totalsList = totalsList;

  protected readonly runs = signal<PayRunSummary[]>([]);
  protected readonly run = signal<PayRunDetail | null>(null);
  protected readonly line = signal<PayLineDetail | null>(null);
  protected readonly panel = signal<Panel>('none');
  protected readonly loading = signal(false);
  protected readonly filter = signal<LineFilter>('all');

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

  protected get mayManage(): boolean {
    return this.session.has('payroll.manage');
  }

  protected get mayApprove(): boolean {
    return this.session.has('payroll.approve');
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

  /** Boxes the chosen method and currency can pay from: cash boxes for cash, Sham Cash boxes for Sham Cash. */
  protected readonly payBoxes = computed(() => this.finance.boxes().filter((box) => box.active));

  constructor() {
    super();
    void this.start();
  }

  private async start(): Promise<void> {
    await this.loadRuns();
    const first = this.runs()[0];
    if (first) await this.open(first.id);
    // Paying needs the finance boxes; a user without finance access simply sees no boxes.
    try {
      await this.finance.loadBoxes();
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
      this.run.set(await this.payroll.run(id));
      this.line.set(null);
      if (this.panel() === 'line') this.panel.set('none');
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
      const open = this.line();
      if (open && this.panel() === 'line') this.line.set(await this.payroll.line(detail.id, open.id));
    });
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
    const ok = await this.act(() => this.payroll.recalculate(run.id), 'prRecalculated');
    if (ok) await this.refresh();
  }

  protected async submit(): Promise<void> {
    const run = this.run();
    if (!run) return;
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
    this.line.set(null);
    this.panel.set('none');
  }

  protected boxesFor(): { id: number; name: string; currency_code: string }[] {
    return this.payBoxes().filter((box) => box.currency_code === this.payCurrency && box.kind === this.payMethod);
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
    return item.origin === 'adjustment' && item.status === 'proposed' && this.run()?.status === 'draft' && this.mayManage;
  }

  protected canReview(item: PayRunItem): boolean {
    return item.origin === 'proposed' && this.run()?.status === 'submitted' && this.mayApprove;
  }

  protected hasPendingAdjustment(line: PayLineDetail): boolean {
    return line.items.some((item) => this.canDecideAdjustment(item));
  }

  protected proposedOf(item: PayRunItem): number {
    return amount(item.proposed_amount);
  }

  protected canPay(line: PayLineDetail): boolean {
    return this.run()?.status === 'approved' && this.mayManage && !line.payment && amount(line.net) > 0;
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
