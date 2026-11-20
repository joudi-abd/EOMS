import { Component, inject, input, OnInit, signal } from '@angular/core';
import { Router } from '@angular/router';
import { FinSite, MyCustody, PaymentSource, SiteRequest } from '../../../../data/finance-site';
import { FinanceBase } from '../../finance-base';
import { SiteRequestForm } from '../site-request-form/site-request-form';

/** "طلباتي": the supervisor's own requests as a plain list (phone first), with the request sheet above it. */
@Component({
  selector: 'app-fin-site-mine',
  imports: [SiteRequestForm],
  templateUrl: './site-mine.html',
  styleUrl: './site-mine.scss',
  host: { class: 'ledger' },
})
export class SiteMine extends FinanceBase implements OnInit {
  private readonly store = inject(FinSite);
  private readonly router = inject(Router);

  readonly composing = input(false);
  readonly myCustody = input<MyCustody | null>(null);

  protected readonly rows = signal<SiteRequest[]>([]);
  protected readonly page = signal(1);
  protected readonly pages = signal(1);
  protected readonly loading = signal(true);

  async ngOnInit(): Promise<void> {
    void this.quiet(() => this.finance.loadCurrencies());
    await this.load(1);
  }

  protected async load(page: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.store.mine({ per_page: 25 }, page);
      this.rows.set(result.data);
      this.page.set(result.meta.current_page);
      this.pages.set(result.meta.last_page);
    });
    this.loading.set(false);
  }

  protected close(): void {
    void this.router.navigate([], { queryParams: { new: null }, queryParamsHandling: 'merge' });
  }

  protected compose(): void {
    void this.router.navigate([], { queryParams: { new: 1 }, queryParamsHandling: 'merge' });
  }

  protected saved(): void {
    this.close();
    void this.load(1);
  }

  protected source(value: PaymentSource): string {
    return this.t(value === 'custody' ? 'fsrFromCustody' : 'fsrFromOwn');
  }
}
