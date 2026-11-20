import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { FinSite, PaymentSource, SiteRequest, SiteRequestStatus } from '../../../../data/finance-site';
import { FinanceBase } from '../../finance-base';

/** Finance inbox: every site request in the account's branches, pending first. */
@Component({
  selector: 'app-fin-site-inbox',
  imports: [FormsModule],
  templateUrl: './site-inbox.html',
  styleUrl: './site-inbox.scss',
  host: { class: 'ledger' },
})
export class SiteInbox extends FinanceBase implements OnInit {
  private readonly store = inject(FinSite);
  private readonly router = inject(Router);

  protected readonly rows = signal<SiteRequest[]>([]);
  protected readonly page = signal(1);
  protected readonly pages = signal(1);
  protected readonly loading = signal(true);
  protected readonly waiting = signal(0);

  protected status: SiteRequestStatus | '' = '';
  protected source: PaymentSource | '' = '';
  protected projectId = 0;
  protected from = '';
  protected to = '';

  protected readonly statuses: SiteRequestStatus[] = ['submitted', 'approved', 'rejected'];

  async ngOnInit(): Promise<void> {
    void this.quiet(() => this.finance.loadCurrencies());
    if (this.may('finance.projects.view')) void this.quiet(() => this.finance.loadProjects());
    void this.quiet(async () => this.waiting.set((await this.store.inbox({ status: 'submitted', per_page: 1 })).meta.total));
    await this.load(1);
  }

  protected async load(page: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.store.inbox(
        { status: this.status, payment_source: this.source, project_id: this.projectId, from: this.from, to: this.to, per_page: 25 },
        page,
      );
      this.rows.set(result.data);
      this.page.set(result.meta.current_page);
      this.pages.set(result.meta.last_page);
    });
    this.loading.set(false);
  }

  protected filtered(): boolean {
    return !!(this.status || this.source || this.projectId || this.from || this.to);
  }

  protected reset(): void {
    this.status = '';
    this.source = '';
    this.projectId = 0;
    this.from = '';
    this.to = '';
    void this.load(1);
  }

  protected open(row: SiteRequest): void {
    void this.router.navigate([], { queryParams: { view: 'inbox', id: row.id } });
  }

  protected sourceLabel(value: PaymentSource): string {
    return this.t(value === 'custody' ? 'fsrSourceCustody' : 'fsrSourceOwn');
  }
}
