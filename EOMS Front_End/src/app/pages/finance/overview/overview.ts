import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BarChart } from '../../../shared/charts/bar-chart/bar-chart';
import { ChartDatum, ChartSeries, tone as chartTone } from '../../../shared/charts/chart';
import { Notice } from '../../../shared/notice/notice';
import { amount, Money, monthStart, today } from '../../../data/finance';
import { Dashboard, FinOverviewApi, SetupStatus } from '../../../data/finance-overview';
import { FinanceBase } from '../finance-base';
import { BoxesSummary } from './boxes-summary/boxes-summary';

/**
 * Finance area: overview — the general manager's dashboard (`GET /finance/dashboard`, `finance.reports`): liquidity,
 * what needs attention, money in and out this month against last month, the most profitable projects, what is owed
 * both ways and partner drawings. Every figure links to the area that explains it. Without `finance.reports` the page
 * falls back to the boxes summary (`finance.view`). An unfinished setup shows one calm line on top.
 */
@Component({
  selector: 'app-fin-overview',
  imports: [Notice, RouterLink, BarChart, BoxesSummary],
  templateUrl: './overview.html',
  styleUrl: './overview.scss',
})
export class FinOverviewPage extends FinanceBase {
  private readonly overviewApi = inject(FinOverviewApi);

  protected readonly board = signal<Dashboard | null>(null);
  protected readonly setup = signal<SetupStatus | null>(null);
  protected readonly loading = signal(false);
  protected readonly monthFrom = monthStart();
  protected readonly monthTo = today();

  protected readonly base = computed(() => this.board()?.consolidation.base_currency ?? 'USD');

  /** Everything that wants a look, in one count (empty = all clear). */
  protected readonly attention = computed(() => {
    const d = this.board();
    if (!d) return 0;
    return d.box_alerts.length + d.cash_counts_pending.items.length + (d.custodies.count > 0 ? 1 : 0) + d.projects.over_budget.length;
  });

  protected readonly flowSeries = computed<ChartSeries[]>(() => [
    { key: 'in', label: this.t('finIn'), color: chartTone.blue },
    { key: 'out', label: this.t('finOut'), color: chartTone.slate },
  ]);

  /** Consolidated in the base currency, so both months share one scale. */
  protected readonly flowData = computed<ChartDatum[]>(() => {
    const flow = this.board()?.cash_flow.consolidated;
    if (!flow) return [];
    return [
      { label: this.t('fovLastMonth'), values: { in: amount(flow.last_month.in), out: amount(flow.last_month.out) } },
      { label: this.t('fovThisMonth'), values: { in: amount(flow.this_month.in), out: amount(flow.this_month.out) } },
    ];
  });

  protected readonly hasFlow = computed(() => this.flowData().some((item) => item.values['in'] || item.values['out']));

  /** Rates other than the base currency's own, as "1 USD = 13,000 SYP · date". */
  protected readonly rates = computed(() => (this.board()?.consolidation.rates_used ?? []).filter((item) => item.currency !== this.base()));
  protected readonly historical = computed(() => this.board()?.consolidation.rate_mode === 'historical');

  constructor() {
    super();
    void this.load();
  }

  protected async load(): Promise<void> {
    this.loading.set(true);
    await Promise.all([
      this.finance.loadCurrencies().catch(() => undefined),
      this.may('finance.view') ? this.quiet(async () => this.setup.set(await this.overviewApi.setupStatus())) : null,
      this.may('finance.reports') ? this.quiet(async () => this.board.set(await this.overviewApi.dashboard())) : null,
    ]);
    this.loading.set(false);
  }

  protected rate(value: Money | null, code: string): string {
    return value === null ? '' : this.money(value, code);
  }
}
