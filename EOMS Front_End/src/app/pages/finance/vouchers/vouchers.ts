import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { Voucher } from '../../../data/finance';
import { Notice } from '../../../shared/notice/notice';
import { FinanceBase } from '../finance-base';
import { VoucherForm, VoucherPrefill } from './voucher-form/voucher-form';
import { VoucherList } from './voucher-list/voucher-list';
import { VoucherView } from './voucher-view/voucher-view';

/** Query params a deep link may carry to start a prefilled voucher (`?new=<type>&purpose=…`). */
const startParams = [
  'new',
  'purpose',
  'box',
  'party',
  'project',
  'currency',
  'custody',
  'agreement',
] as const;

/**
 * Finance area: vouchers. Every money movement is a voucher. The list is the default view (`?status=` filters it);
 * a voucher opens in place above it (`?id=`), and the new-voucher sheet opens from the heading or from a deep link
 * sent by another area (`?new=<type>&purpose&box&party&project&currency&custody&agreement`).
 */
@Component({
  selector: 'app-fin-vouchers',
  imports: [Notice, VoucherList, VoucherForm, VoucherView],
  templateUrl: './vouchers.html',
  styleUrl: './vouchers.scss',
})
export class FinVouchersPage extends FinanceBase {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly params = toSignal(this.route.queryParamMap);

  protected readonly openId = computed(() => Number(this.params()?.get('id') ?? 0) || 0);
  protected readonly statusParam = computed(() => this.params()?.get('status') ?? '');
  protected readonly prefill = computed<VoucherPrefill | null>(() => {
    const params = this.params();
    const type = params?.get('new');
    if (!type || !this.may('finance.vouchers')) return null;
    const id = (key: string) => Number(params?.get(key) ?? 0) || 0;
    return {
      type,
      purpose: params?.get('purpose') ?? '',
      box: id('box'),
      party: id('party'),
      project: id('project'),
      currency: (params?.get('currency') ?? '').toUpperCase(),
      custody: id('custody'),
      agreement: id('agreement'),
    };
  });
  /** A changed deep link starts a fresh form. */
  protected readonly prefillKey = computed(() => JSON.stringify(this.prefill()));

  /** The draft being edited (opened from its view). */
  protected readonly editing = signal<Voucher | null>(null);
  /** The voucher just saved, shown with its warnings without asking for it again. */
  protected readonly fresh = signal<Voucher | null>(null);
  protected readonly refresh = signal(0);

  constructor() {
    super();
    if (this.may('finance.view')) void this.quiet(() => this.loadLookups());
  }

  private loadLookups(): Promise<unknown> {
    const projects = this.may('finance.projects.view');
    return Promise.all([
      this.finance.loadCurrencies(),
      this.finance.loadBranches(),
      this.finance.loadBoxes(),
      this.finance.loadAccounts(),
      this.finance.loadParties(),
      this.finance.loadExpenseCategories(),
      projects ? this.finance.loadProjects() : undefined,
      projects ? this.finance.loadCostCategories() : undefined,
    ]);
  }

  private go(params: Record<string, string | number | null>): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: params,
      queryParamsHandling: 'merge',
    });
  }

  private cleared(): Record<string, null> {
    return Object.fromEntries(startParams.map((key) => [key, null]));
  }

  protected startNew(): void {
    this.editing.set(null);
    this.go({ ...this.cleared(), id: null, new: 'receipt' });
  }

  protected open(id: number): void {
    this.editing.set(null);
    this.go({ ...this.cleared(), id });
  }

  protected closeForm(): void {
    this.editing.set(null);
    this.go(this.cleared());
  }

  protected closeView(): void {
    this.fresh.set(null);
    this.go({ id: null });
  }

  protected saved(voucher: Voucher): void {
    this.editing.set(null);
    this.fresh.set(voucher);
    this.refresh.update((n) => n + 1);
    this.go({ ...this.cleared(), id: voucher.id });
  }

  protected changed(voucher: Voucher | null): void {
    this.fresh.set(voucher);
    this.refresh.update((n) => n + 1);
    if (!voucher) this.go({ id: null });
  }
}
