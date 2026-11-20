import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { AdvanceInstallment, AdvanceStatus, EmployeeAdvanceDetail, FinAdvances, InstallmentStatus } from '../../../../data/finance-advances';
import { FinanceBase } from '../../finance-base';
import { advanceStatusKey } from '../advance-list/advance-list';

const installmentStatusKey: Record<InstallmentStatus, CopyKey> = {
  pending: 'fadInstPending',
  deducted: 'fadInstDeducted',
  postponed: 'fadInstPostponed',
};

/** One advance: its facts, its monthly installments, and postponing a pending one (R18). */
@Component({
  selector: 'app-fin-advance-detail',
  imports: [FormsModule, RouterLink],
  templateUrl: './advance-detail.html',
  styleUrl: './advance-detail.scss',
  host: { class: 'ledger' },
})
export class AdvanceDetail extends FinanceBase {
  private readonly store = inject(FinAdvances);

  readonly id = input.required<number>();

  protected readonly advance = signal<EmployeeAdvanceDetail | null>(null);
  protected readonly loading = signal(true);
  /** The installment being postponed (its sheet is open). */
  protected readonly postponing = signal<AdvanceInstallment | null>(null);
  protected reason = '';

  protected readonly mayPostpone = computed(() => this.may('finance.vouchers') && this.advance()?.status === 'active');
  protected readonly code = computed(() => {
    const advance = this.advance();
    return advance ? (advance.currency?.code ?? this.finance.code(advance.currency_id)) : '';
  });

  constructor() {
    super();
    void this.quiet(() => this.finance.loadCurrencies());
    effect(() => {
      const id = this.id();
      untracked(() => void this.load(id));
    });
  }

  private async load(id: number): Promise<void> {
    this.loading.set(true);
    this.postponing.set(null);
    await this.quiet(async () => this.advance.set(await this.store.advance(id)));
    this.loading.set(false);
  }

  protected startPostpone(item: AdvanceInstallment): void {
    this.reason = '';
    this.postponing.set(item);
  }

  protected async postpone(): Promise<void> {
    const item = this.postponing();
    if (!item) return;
    if (this.reason.trim().length < 3) {
      this.warn('fadReasonNeeded');
      return;
    }
    const result = await this.act(() => this.store.postpone(item.id, this.reason.trim()), 'fadPostponed');
    if (result) {
      this.advance.set(result);
      this.postponing.set(null);
    }
  }

  protected advanceStatus(status: AdvanceStatus): string {
    return this.t(advanceStatusKey[status] ?? 'fadStatusActive');
  }

  protected installmentStatus(status: InstallmentStatus): string {
    return this.t(installmentStatusKey[status] ?? 'fadInstPending');
  }
}
