import { Component, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import {
  Attachment,
  Money,
  today,
  Voucher,
  VoucherLine,
  VoucherType,
} from '../../../../data/finance';
import { AssetRef, FinVoucherData } from '../../../../data/finance-vouchers';
import { FinanceBase } from '../../finance-base';

/** One plain fact about the voucher: a label, a value, and where it leads when it names something. */
interface Fact {
  key: CopyKey;
  value: string;
  money?: boolean;
  link?: { path: string; params: Record<string, string | number> };
}

/** Types the voucher form can edit as drafts; the rest come from their own screens. */
const editableTypes: readonly VoucherType[] = [
  'receipt',
  'payment',
  'transfer',
  'exchange',
  'expense',
  'manual_journal',
  'custody_issue',
  'custody_settlement',
  'custody_return',
  'advance_issue',
  'project_billing',
];

/**
 * One voucher in place above the list: its facts in plain words, its lines, attachments, the posted entry behind a
 * collapsed section, and the actions its state allows (draft: edit, submit, approve, delete; submitted: approve or
 * reject; approved: reverse).
 */
@Component({
  selector: 'app-voucher-view',
  imports: [FormsModule, RouterLink],
  templateUrl: './voucher-view.html',
  styleUrl: './voucher-view.scss',
  host: { class: 'ledger' },
})
export class VoucherView extends FinanceBase {
  private readonly data = inject(FinVoucherData);

  readonly id = input.required<number>();
  /** The voucher as the save returned it (with its warnings), to show without asking again. */
  readonly seed = input<Voucher | null>(null);
  readonly closed = output<void>();
  readonly edit = output<Voucher>();
  /** Fired after a change; null after a delete. */
  readonly changed = output<Voucher | null>();

  protected readonly voucher = signal<Voucher | null>(null);
  protected readonly panel = signal<'none' | 'reject' | 'reverse' | 'delete'>('none');
  protected readonly agreementName = signal('');
  protected readonly assets = signal<AssetRef[]>([]);
  protected reason = '';
  protected reverseDate = today();

  constructor() {
    super();
    effect(() => {
      const id = this.id();
      const seed = this.seed();
      untracked(() => {
        this.panel.set('none');
        if (seed?.id === id) this.show(seed);
        else void this.load(id);
      });
    });
  }

  private async load(id: number): Promise<void> {
    this.voucher.set(null);
    await this.quiet(async () => this.show(await this.finance.voucher(id)));
  }

  private show(voucher: Voucher): void {
    this.voucher.set(voucher);
    void this.extras(voucher);
  }

  /** Names that live in other areas: the agreement's scope and the assets the voucher touches. */
  private async extras(voucher: Voucher): Promise<void> {
    this.agreementName.set('');
    if (voucher.contractor_agreement_id && this.may('finance.projects.view')) {
      await this.quiet(async () =>
        this.agreementName.set(
          (await this.data.agreement(voucher.contractor_agreement_id!)).scope ?? '',
        ),
      );
    }
    const touchesAsset = voucher.asset_id || (voucher.lines ?? []).some((line) => line.asset_id);
    if (touchesAsset && !this.assets().length)
      await this.quiet(async () => this.assets.set(await this.data.assets()));
  }

  // ---------- Reading ----------

  protected facts(v: Voucher): Fact[] {
    const out: Fact[] = [];
    const add = (key: CopyKey, value: string | null | undefined, extra: Partial<Fact> = {}) => {
      if (value) out.push({ key, value, ...extra });
    };
    const boxLink = (id: number | null) =>
      id ? { path: '/modules/finBoxes', params: { id } } : undefined;
    const twoBoxes = v.type === 'transfer' || v.type === 'exchange';
    add(twoBoxes ? 'fvoFromBox' : 'finBox', this.finance.boxName(v.cash_box_id), {
      link: boxLink(v.cash_box_id),
    });
    if (twoBoxes)
      add('fvoToBox', this.finance.boxName(v.counter_cash_box_id), {
        link: boxLink(v.counter_cash_box_id),
      });
    if (v.type !== 'manual_journal' && v.amount !== null) {
      add(
        v.type === 'exchange' ? 'fvoAmountGiven' : 'finAmount',
        this.money(v.amount, v.currency_id),
        { money: true },
      );
    }
    if (v.type === 'exchange') {
      add(
        'fvoAmountReceived',
        v.counter_amount !== null ? this.money(v.counter_amount, v.counter_currency_id) : '',
        { money: true },
      );
      if (v.rate !== null)
        add(
          'finRate',
          `1 ${this.finance.code(v.currency_id)} = ${this.rateText(v.rate)} ${this.finance.code(v.counter_currency_id)}`,
          { money: true },
        );
    }
    if (v.fee_amount !== null && Number(v.fee_amount) > 0)
      add('fvoFee', this.money(v.fee_amount, v.fee_currency_id ?? v.currency_id), { money: true });
    const employee = v.type === 'advance_issue' || v.purpose === 'employee_advance';
    const partyKey: CopyKey = employee
      ? 'finEmployee'
      : v.type.startsWith('custody') || v.purpose === 'custody'
        ? 'fvoHolder'
        : 'finParty';
    const partyId =
      v.party_id ??
      (v.employee_id
        ? this.finance.parties().find((party) => party.employee_id === v.employee_id)?.id
        : null);
    add(partyKey, this.finance.partyName(partyId), {
      link: partyId ? { path: '/modules/finParties', params: { id: partyId } } : undefined,
    });
    if (v.debt_direction)
      add(
        'fvoDebtWhich',
        this.t(v.debt_direction === 'we_owe' ? 'fvoDebtWeOwe' : 'fvoDebtOwedToUs'),
      );
    if (v.classification)
      add(
        'fvoDrawingWhat',
        this.t(
          v.classification === 'partner_personal' ? 'fvoDrawingPersonal' : 'fvoDrawingCompany',
        ),
      );
    if (v.contractor_agreement_id) {
      add('fvoAgreement', this.agreementName() || this.t('fvoOpenAgreement'), {
        link: v.project_id
          ? { path: '/modules/finProjects', params: { id: v.project_id, view: 'contractors' } }
          : undefined,
      });
    }
    add('finProject', this.finance.projectName(v.project_id), {
      link: v.project_id
        ? { path: '/modules/finProjects', params: { id: v.project_id } }
        : undefined,
    });
    add('finCostCategory', this.finance.costCategoryName(v.project_cost_category_id));
    add('finExpenseCategory', this.finance.expenseCategoryName(v.expense_category_id));
    add(
      v.purpose === 'partner_deposit' ? 'fvoDepositTo' : 'finAccount',
      this.finance.accountLabel(v.account_id),
    );
    if (v.asset_id)
      add('fvoAsset', this.assetName(v.asset_id), {
        link: { path: '/modules/finAssets', params: { id: v.asset_id } },
      });
    if (v.custody_id)
      add('fvoCustody', this.t('fvoOpenCustody'), {
        link: { path: '/modules/finAdvances', params: { view: 'custody', id: v.custody_id } },
      });
    if (v.advance_deduction !== null && Number(v.advance_deduction) > 0)
      add('fvoAdvanceDeduction', this.money(v.advance_deduction, v.currency_id), { money: true });
    add('fvoOverrideReason', v.override_reason);
    const meta = (v.meta ?? {}) as Record<string, unknown>;
    if (meta['installments_count']) add('fvoInstallments', String(meta['installments_count']));
    if (typeof meta['start_month'] === 'string') add('fvoStartMonth', meta['start_month']);
    add('finDescription', v.description);
    add('finReference', v.reference_no);
    add(
      'finBranch',
      this.finance.branches().length > 1 ? this.finance.branchName(v.branch_id) : '',
    );
    add('fvoCreated', this.who(v.created_at, v.created_by));
    add('fvoSubmittedAt', v.submitted_at ? this.stamp(v.submitted_at) : '');
    add('fvoApprovedAt', v.approved_at ? this.who(v.approved_at, v.approved_by) : '');
    add('fvoReversedAt', v.reversed_at ? this.stamp(v.reversed_at) : '');
    return out;
  }

  private who(at: string | null, userId: number | null): string {
    const name = userId ? this.office.accounts().find((item) => item.userId === userId) : undefined;
    return [at ? this.stamp(at) : '', name ? this.text(name.name) : ''].filter(Boolean).join(' · ');
  }

  protected rateText(rate: Money): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 6 }).format(Number(rate) || 0);
  }

  protected assetName(id: number | null | undefined): string {
    const asset = this.assets().find((item) => item.id === id);
    return asset
      ? asset.code
        ? `${asset.code} · ${asset.name}`
        : asset.name
      : this.t('fvoOpenAsset');
  }

  /** What a voucher line went on, in words. */
  protected lineTarget(line: VoucherLine): string {
    const parts = [
      this.finance.accountLabel(line.account_id),
      this.finance.expenseCategoryName(line.expense_category_id),
      this.finance.projectName(line.project_id),
      this.finance.costCategoryName(line.project_cost_category_id),
      line.asset_id ? this.assetName(line.asset_id) : '',
    ];
    return parts.filter(Boolean).join(' · ') || '—';
  }

  protected isPayroll(v: Voucher): boolean {
    return v.type === 'payroll_accrual' || v.type === 'payroll_payment';
  }

  protected editable(v: Voucher): boolean {
    return v.status === 'draft' && editableTypes.includes(v.type) && this.may('finance.vouchers');
  }

  protected lineCurrency(line: VoucherLine): number | string | null {
    return line.currency_id ?? line.currency ?? null;
  }

  // ---------- Acting ----------

  private after(result: Voucher | null): void {
    if (!result) return;
    this.panel.set('none');
    this.reason = '';
    this.show(result);
    this.changed.emit(result);
  }

  protected async submit(v: Voucher): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    this.after(await this.act(() => this.finance.submitVoucher(v.id), 'fvoSubmittedDone'));
  }

  protected async approve(v: Voucher): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    this.after(await this.act(() => this.finance.approveVoucher(v.id), 'fvoApprovedDone'));
  }

  protected async reject(v: Voucher): Promise<void> {
    this.after(
      await this.act(() => this.finance.rejectVoucher(v.id, this.reason.trim()), 'fvoRejectedDone'),
    );
  }

  protected async reverse(v: Voucher): Promise<void> {
    this.after(
      await this.act(
        () => this.finance.reverseVoucher(v.id, this.reverseDate, this.reason.trim()),
        'fvoReversedDone',
      ),
    );
  }

  protected async remove(v: Voucher): Promise<void> {
    const done = await this.act(() => this.finance.deleteVoucher(v.id), 'finDeleted');
    if (done === null) return;
    this.changed.emit(null);
  }

  protected open(panel: 'reject' | 'reverse' | 'delete'): void {
    this.reason = '';
    this.reverseDate = today();
    this.panel.set(this.panel() === panel ? 'none' : panel);
  }

  protected mayAttach(v: Voucher): boolean {
    return this.may('finance.vouchers') && v.status !== 'rejected' && v.status !== 'reversed';
  }

  protected async attach(v: Voucher, event: Event): Promise<void> {
    const field = event.target as HTMLInputElement;
    const file = field.files?.[0];
    field.value = '';
    if (!file) return;
    if ((await this.act(() => this.finance.attachToVoucher(v.id, file), 'fvoAttached')) !== null)
      await this.load(v.id);
  }

  protected async download(v: Voucher, file: Attachment): Promise<void> {
    await this.quiet(async () =>
      this.saveFile(await this.finance.voucherAttachment(v.id, file.id), file.original_name),
    );
  }

  protected async detach(v: Voucher, file: Attachment): Promise<void> {
    if (!(await this.askDelete('confirmRemoveBody'))) return;
    if (
      (await this.act(() => this.finance.removeVoucherAttachment(v.id, file.id), 'fvoDetached')) !==
      null
    )
      await this.load(v.id);
  }

  protected size(bytes: number | null): string {
    if (!bytes) return '';
    return bytes >= 1048576
      ? `${(bytes / 1048576).toFixed(1)} MB`
      : `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }
}
