import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { FinParty } from '../../../../data/finance';
import { Custody, CustodyReport, CustodyStatus, FinAdvances } from '../../../../data/finance-advances';
import { FinanceBase } from '../../finance-base';

/** Custodies: the open balance per currency, which are overdue, and the list (open first, oldest first). */
@Component({
  selector: 'app-fin-custody-list',
  imports: [FormsModule],
  templateUrl: './custody-list.html',
  styleUrl: './custody-list.scss',
  host: { class: 'ledger' },
})
export class CustodyList extends FinanceBase implements OnInit {
  private readonly store = inject(FinAdvances);
  private readonly router = inject(Router);

  protected readonly rows = signal<Custody[]>([]);
  protected readonly report = signal<CustodyReport | null>(null);
  protected readonly page = signal(1);
  protected readonly pages = signal(1);
  protected readonly loading = signal(true);

  protected status: CustodyStatus | '' = 'open';
  protected overdue = false;
  protected holderId = 0;

  protected readonly holders = computed<FinParty[]>(() => this.finance.parties().slice().sort((a, b) => a.name.localeCompare(b.name)));
  protected readonly overdueCount = computed(() => this.report()?.custodies.filter((item) => item.overdue).length ?? 0);

  async ngOnInit(): Promise<void> {
    void this.quiet(() => Promise.all([this.finance.loadCurrencies(), this.finance.loadParties()]));
    void this.quiet(async () => this.report.set(await this.store.custodyReport()));
    await this.load(1);
  }

  protected async load(page: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.store.custodies(
        { status: this.status, overdue: this.overdue, holder_party_id: this.holderId, per_page: 25 },
        page,
      );
      this.rows.set(result.data);
      this.page.set(result.meta.current_page);
      this.pages.set(result.meta.last_page);
    });
    this.loading.set(false);
  }

  protected reset(): void {
    this.status = 'open';
    this.overdue = false;
    this.holderId = 0;
    void this.load(1);
  }

  protected open(row: Custody): void {
    void this.router.navigate([], { queryParams: { view: 'custody', id: row.id } });
  }

  protected statusWord(status: CustodyStatus): string {
    return this.t(status === 'open' ? 'fadCustodyOpen' : 'fadCustodyClosed');
  }
}
