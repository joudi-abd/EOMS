import { Component, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  monthStart,
  Voucher,
  VoucherFilters,
  VoucherStatus,
  voucherStatuses,
  VoucherType,
  voucherTypes,
} from '../../../../data/finance';
import { FinVoucherData } from '../../../../data/finance-vouchers';
import { FinanceBase } from '../../finance-base';

interface Filters {
  type: VoucherType | '';
  status: VoucherStatus | '';
  box_id: number;
  from: string;
  to: string;
  search: string;
}

const blank = (): Filters => ({ type: '', status: '', box_id: 0, from: '', to: '', search: '' });

/** The voucher register: a figure strip of what waits, a small filter bar, and the vouchers newest first, 25 a page. */
@Component({
  selector: 'app-voucher-list',
  imports: [FormsModule],
  templateUrl: './voucher-list.html',
  styleUrl: './voucher-list.scss',
  host: { class: 'ledger' },
})
export class VoucherList extends FinanceBase {
  private readonly data = inject(FinVoucherData);

  /** The `?status=` query param. */
  readonly status = input('');
  /** The open voucher, marked in the table. */
  readonly picked = input(0);
  /** Bumped by the page after any change, to reload. */
  readonly refresh = input(0);
  readonly open = output<number>();
  readonly create = output<void>();

  protected readonly types = voucherTypes;
  protected readonly statuses = voucherStatuses;
  protected readonly rows = signal<Voucher[]>([]);
  protected readonly page = signal(1);
  protected readonly lastPage = signal(1);
  protected readonly loading = signal(true);
  protected readonly figures = signal<{ waiting: number; drafts: number; month: number } | null>(
    null,
  );
  protected filters: Filters = blank();
  private lastStatus: string | null = null;
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    super();
    effect(() => {
      const status = this.status();
      this.refresh();
      untracked(() => {
        let page = this.page();
        if (status !== this.lastStatus) {
          this.lastStatus = status;
          this.filters = {
            ...blank(),
            status: (voucherStatuses as readonly string[]).includes(status)
              ? (status as VoucherStatus)
              : '',
          };
          page = 1;
        }
        void this.load(page);
        void this.loadFigures();
      });
    });
  }

  protected async load(page = 1): Promise<void> {
    this.loading.set(true);
    const query: VoucherFilters = {
      ...this.filters,
      box_id: this.filters.box_id || undefined,
      search: this.filters.search.trim(),
      per_page: 25,
    };
    await this.quiet(async () => {
      const result = await this.finance.vouchers(query, page);
      this.rows.set(result.data);
      this.page.set(result.meta.current_page);
      this.lastPage.set(result.meta.last_page);
    });
    this.loading.set(false);
  }

  private async loadFigures(): Promise<void> {
    await this.quiet(async () => {
      const [waiting, drafts, month] = await Promise.all([
        this.data.count({ status: 'submitted' }),
        this.data.count({ status: 'draft' }),
        this.data.count({ status: 'approved', from: monthStart() }),
      ]);
      this.figures.set({ waiting, drafts, month });
    });
  }

  /** A figure's shortcut: show only those vouchers. */
  protected only(status: VoucherStatus, thisMonth = false): void {
    this.filters = { ...blank(), status, from: thisMonth ? monthStart() : '' };
    void this.load(1);
  }

  protected reload(): void {
    void this.load(1);
  }

  protected searchChanged(): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.reload(), 350);
  }

  protected reset(): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.filters = blank();
    void this.load(1);
  }

  protected filtered(): boolean {
    const f = this.filters;
    return !!(f.type || f.status || f.box_id || f.from || f.to || f.search.trim());
  }

  /** Where the money moved: the box (or both boxes) and the party. */
  protected where(voucher: Voucher): string {
    if (voucher.type === 'transfer' || voucher.type === 'exchange') {
      const from = this.finance.boxName(voucher.cash_box_id);
      const to = this.finance.boxName(voucher.counter_cash_box_id);
      return from && to ? `${from} ${this.t('finTo')} ${to}` : from || to;
    }
    return [this.finance.boxName(voucher.cash_box_id), this.finance.partyName(voucher.party_id)]
      .filter(Boolean)
      .join(' · ');
  }

  protected typeLabel(type: VoucherType): string {
    return this.voucherTypeLabel(type);
  }
}
