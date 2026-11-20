import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, ParamMap, Router, RouterLink } from '@angular/router';
import { CopyKey } from '../../../core/copy';
import { Reporter } from '../../../core/reports/reporter';
import { FinAccount, monthStart, today } from '../../../data/finance';
import {
  FinanceReports,
  PageMeta,
  RateUsed,
  ReportDef,
  ReportFilter,
  ReportPicker,
  ReportQuery,
  reportCatalogue,
  reportGroups,
} from '../../../data/finance-reports';
import { Notice } from '../../../shared/notice/notice';
import { FinanceBase } from '../finance-base';
import { buildView, Fmt, plainRows, ReportView } from './report-views';
import { ReportTables } from './report-tables/report-tables';

/** The filters of the open report, as typed in the filter bar. URL parameters carry the backend names. */
interface Draft {
  from: string;
  to: string;
  as_of: string;
  branch_id: number;
  currency: string;
  box_id: number;
  party_id: number;
  account_id: number;
  project_id: number;
  side: string;
  status: string;
  months: number;
  variant: string;
  group_by: string;
  search: string;
  action: string;
  by_branch: boolean;
  view: 'separate' | 'consolidated';
}

/** The URL (and backend) parameters behind each filter. */
const filterParams: Record<ReportFilter, (keyof Draft)[]> = {
  period: ['from', 'to'],
  asOf: ['as_of'],
  branch: ['branch_id'],
  currency: ['currency'],
  box: ['box_id'],
  party: ['party_id'],
  account: ['account_id'],
  project: ['project_id'],
  side: ['side'],
  countStatus: ['status'],
  projectStatus: ['status'],
  months: ['months'],
  variant: ['variant'],
  groupBy: ['group_by'],
  search: ['search'],
  action: ['action'],
  byBranch: ['by_branch'],
};

const numeric: (keyof Draft)[] = ['branch_id', 'box_id', 'party_id', 'account_id', 'project_id', 'months'];
const pickerParam: Record<ReportPicker, keyof Draft> = { box: 'box_id', party: 'party_id', account: 'account_id', project: 'project_id' };
const pickerHint: Record<ReportPicker, CopyKey> = { box: 'frpPickBox', party: 'frpPickParty', account: 'frpPickAccount', project: 'frpPickProject' };
const assetStatusKey: Record<string, CopyKey> = {
  in_service: 'frpAsInService',
  under_maintenance: 'frpAsMaintenance',
  sold: 'frpAsSold',
  scrapped: 'frpAsScrapped',
  lost: 'frpAsLost',
  cancelled: 'frpAsCancelled',
};

function defaults(): Draft {
  return {
    from: monthStart(),
    to: today(),
    as_of: today(),
    branch_id: 0,
    currency: '',
    box_id: 0,
    party_id: 0,
    account_id: 0,
    project_id: 0,
    side: '',
    status: '',
    months: 3,
    variant: 'internal',
    group_by: 'status',
    search: '',
    action: '',
    by_branch: false,
    view: 'separate',
  };
}

/** The draft as URL parameters: only the open report's filters, empty ones left out. */
function urlParams(def: ReportDef, draft: Draft): Record<string, string> {
  const out: Record<string, string> = { report: def.key };
  for (const filter of def.filters) {
    for (const key of filterParams[filter]) {
      const value = draft[key];
      if (value === '' || value === 0 || value === false || value === null || value === undefined) continue;
      out[key] = value === true ? '1' : String(value);
    }
  }
  if (def.view && draft.view === 'consolidated') out['view'] = 'consolidated';
  return out;
}

/**
 * Finance reports (T1–T23). The page opens on a short catalogue grouped in plain words; choosing a report shows only
 * that report's filters, the "each currency apart / consolidated in dollars" choice where it applies, and the result as
 * one table per currency. The URL carries the report and its filters, so other areas open a report prefilled
 * (`?report=box-statement&box_id=3&from=…`). Excel downloads the server's CSV; PDF prints the same tables as the A4
 * report document the attendance and payroll reports use.
 */
@Component({
  selector: 'app-fin-reports',
  imports: [Notice, FormsModule, RouterLink, ReportTables],
  templateUrl: './reports.html',
  styleUrl: './reports.scss',
})
export class FinReportsPage extends FinanceBase {
  private readonly reports = inject(FinanceReports);
  private readonly reporter = inject(Reporter);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected draft: Draft = defaults();
  protected readonly exporting = signal(false);
  private readonly loaded = signal<{ def: ReportDef; data: unknown; meta: PageMeta | null; query: ReportQuery } | null>(null);
  private sequence = 0;
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  /** The account opens at least one report. */
  protected readonly reachable = computed(() => reportCatalogue.some((def) => this.opens(def)));

  protected readonly groups = computed(() =>
    reportGroups
      .map((group) => ({ ...group, reports: reportCatalogue.filter((def) => def.group === group.key && this.opens(def)) }))
      .filter((group) => group.reports.length),
  );

  /** The requested report key, whether or not the account may open it. */
  protected readonly requested = computed(() => this.params()?.get('report') ?? '');

  protected readonly def = computed(() => {
    const def = reportCatalogue.find((item) => item.key === this.requested());
    return def && this.opens(def) ? def : null;
  });

  protected readonly meta = computed(() => this.loaded()?.meta ?? null);

  protected readonly view = computed<ReportView | null>(() => {
    const loaded = this.loaded();
    if (!loaded || loaded.data === null || loaded.data === undefined) return null;
    this.language.lang();
    return buildView(loaded.def.key, loaded.data, this.fmt);
  });

  protected readonly empty = computed(() => {
    const view = this.view();
    return !!view && !view.tables.length && !view.figures.length;
  });

  /** "TRY 40 (10 Nov) · SYP 13,000 (10 Nov)", or the historical-rate sentence. */
  protected readonly ratesLine = computed(() => {
    const view = this.view();
    const loaded = this.loaded();
    if (!view?.rates?.length || !loaded) return '';
    if (loaded.def.key !== 'project-profitability' && loaded.query['view'] !== 'consolidated') return '';
    if (view.rates.every((rate) => rate.rate === null)) return this.t('frpHistoricalRates');
    return this.rateText(view.rates);
  });

  private readonly depths = computed(() => {
    const out = new Map<number, number>();
    const walk = (nodes: FinAccount[], depth: number): void => {
      for (const node of nodes) {
        out.set(node.id, depth);
        walk(node.children ?? [], depth + 1);
      }
    };
    walk(this.finance.accountTree(), 0);
    return out;
  });

  protected readonly accountOptions = computed(() =>
    this.finance.accounts().map((account) => ({ id: account.id, label: `${'  '.repeat(this.depths().get(account.id) ?? 0)}${account.code} · ${account.name}` })),
  );

  protected readonly partyOptions = computed(() => {
    const roles = this.def()?.roles;
    return this.finance.parties().filter((party) => !roles?.length || party.roles.some((role) => roles.includes(role)));
  });

  protected readonly projectOptions = computed(() => this.finance.projects().map((project) => ({ id: project.project_id, label: this.finance.projectName(project.project_id) })));

  protected readonly showBranches = computed(() => this.finance.branches().length > 1);

  /** The first required picker still empty, as a hint. Recomputed on every change of the draft through `check()`. */
  protected readonly missing = signal<CopyKey | null>(null);

  protected readonly countStatuses = ['draft', 'submitted', 'approved', 'rejected'];
  protected readonly projectStatuses = ['planned', 'active', 'on_hold', 'completed', 'closed'];

  private readonly fmt: Fmt = {
    t: (key) => this.t(key),
    figure: (value, currency) => this.figure(value, currency),
    money: (value, currency) => this.money(value, currency),
    date: (iso) => (iso ? this.short(iso) : ''),
    stamp: (iso) => this.stamp(iso ?? null),
    qty: (value) => this.qty(value),
    side: (value) => this.t(this.side(value)),
    kind: (type, purpose) => (type ? this.voucherKind(type, purpose) : ''),
    status: (status) => this.statusLabel(status),
    projectStatus: (status) => this.projectStatusLabel(status),
    boxType: (type) => this.boxTypeLabel(type),
    assetStatus: (status) => (assetStatusKey[status] ? this.t(assetStatusKey[status]) : status),
    depth: (id) => this.depths().get(id) ?? 0,
    base: () => this.finance.currencies().find((currency) => currency.is_base)?.code ?? 'USD',
  };

  constructor() {
    super();
    effect(() => {
      const params = this.params();
      const def = this.def();
      untracked(() => void this.open(def, params ?? null));
    });
  }

  protected has(filter: ReportFilter): boolean {
    return !!this.def()?.filters.includes(filter);
  }

  /** A picker is offered only when the account may read its list. */
  protected picker(kind: ReportPicker): boolean {
    if (!this.has(kind)) return false;
    return kind === 'project' ? this.may('finance.projects.view') : this.may('finance.view');
  }

  protected required(kind: ReportPicker): boolean {
    return !!this.def()?.required?.includes(kind);
  }

  protected apply(): void {
    const def = this.def();
    if (!def) return;
    const next = urlParams(def, this.draft);
    const params = this.params();
    const current: Record<string, string> = {};
    for (const key of params?.keys ?? []) if (key !== 'page') current[key] = params?.get(key) ?? '';
    if (sameParams(current, next) && !params?.get('page')) {
      void this.run(def, 1);
      return;
    }
    void this.router.navigate([], { queryParams: next });
  }

  /** Applies filters as soon as a control changes; waits when a required picker is empty. */
  protected filterChanged(): void {
    this.check();
    if (this.missing()) return;
    this.apply();
  }

  protected searchChanged(): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.filterChanged(), 350);
  }

  protected setView(view: Draft['view']): void {
    if (this.draft.view === view) return;
    this.draft.view = view;
    this.apply();
  }

  protected go(page: number): void {
    void this.router.navigate([], { queryParams: { page }, queryParamsHandling: 'merge' });
  }

  protected async excel(): Promise<void> {
    const loaded = this.loaded();
    if (!loaded) return;
    this.exporting.set(true);
    try {
      this.saveFile(await this.reports.csv(loaded.def.key, loaded.query), `${loaded.def.key}-${today()}.csv`);
    } catch (error) {
      this.office.fail(error);
    } finally {
      this.exporting.set(false);
    }
  }

  protected pdf(): void {
    const loaded = this.loaded();
    const view = this.view();
    if (!loaded || !view) return;
    const def = loaded.def;
    const title = this.t(def.title);
    this.reporter.pdf({
      file: `finance-${def.key}-${today()}`,
      title,
      subtitle: this.t(def.ask),
      meta: this.metaLines(def, view, title),
      figures: view.figures.map((figure) => ({ label: figure.label, value: figure.note ? `${figure.value} · ${figure.note}` : figure.value })),
      tables: view.tables
        .filter((table) => table.rows.length)
        .map((table) => ({ name: table.title, columns: table.columns.map((header) => ({ header })), rows: plainRows(table) })),
      landscape: view.tables.some((table) => table.columns.length > 6),
    });
  }

  /** Re-checks the required pickers after a change in the filter bar. */
  protected check(): void {
    const def = this.def();
    const empty = def?.required?.find((kind) => !this.draft[pickerParam[kind]]);
    this.missing.set(empty ? pickerHint[empty] : null);
  }

  private opens(def: ReportDef): boolean {
    if (!this.may(def.permission)) return false;
    // A required box, party or account comes from a list only finance.view reads.
    return !def.required?.some((kind) => kind !== 'project' && !this.may('finance.view'));
  }

  private async open(def: ReportDef | null, params: ParamMap | null): Promise<void> {
    const ticket = ++this.sequence;
    this.loaded.set(null);
    this.busy.set(false);
    this.missing.set(null);
    if (!def) return;
    const draft = defaults();
    for (const key of Object.keys(draft) as (keyof Draft)[]) {
      const value = params?.get(key);
      if (value === null || value === undefined || value === '') continue;
      if (key === 'by_branch') draft.by_branch = value === '1' || value === 'true';
      else if (key === 'view') draft.view = value === 'consolidated' ? 'consolidated' : 'separate';
      else if (numeric.includes(key)) (draft as unknown as Record<string, number>)[key] = Number(value) || 0;
      else (draft as unknown as Record<string, string>)[key] = value;
    }
    this.draft = draft;
    await this.quiet(() => this.lookups(def));
    if (ticket !== this.sequence) return;
    if (def.required?.includes('box') && !draft.box_id && this.finance.activeBoxes().length === 1) draft.box_id = this.finance.activeBoxes()[0].id;
    this.check();
    if (this.missing()) return;
    await this.run(def, Number(params?.get('page')) || 1);
  }

  private async run(def: ReportDef, page: number): Promise<void> {
    this.check();
    if (this.missing()) return;
    const query = this.query(def, page);
    const ticket = ++this.sequence;
    this.busy.set(true);
    try {
      const result = await this.reports.run(def.key, query);
      if (ticket === this.sequence) this.loaded.set({ def, data: result.data, meta: result.meta, query });
    } catch (error) {
      if (ticket === this.sequence) this.office.fail(error);
    } finally {
      if (ticket === this.sequence) this.busy.set(false);
    }
  }

  /** The backend query: the URL parameters, the project statement's day as `date`, and the page of a paged report. */
  private query(def: ReportDef, page: number): ReportQuery {
    const { report: _report, ...params } = urlParams(def, this.draft);
    const query: ReportQuery = { ...params };
    if (def.dayParam === 'date' && query['as_of']) {
      query['date'] = query['as_of'];
      delete query['as_of'];
    }
    if (def.paged) {
      query['page'] = page;
      query['per_page'] = 25;
    }
    return query;
  }

  private async lookups(def: ReportDef): Promise<void> {
    const jobs: Promise<void>[] = [this.finance.loadCurrencies(), this.finance.loadBranches()];
    const reads = this.may('finance.view');
    if (reads && def.filters.includes('box')) jobs.push(this.finance.loadBoxes());
    if (reads && def.filters.includes('party')) jobs.push(this.finance.loadParties());
    // Accounts also give the ledger and the trial balance their tree depth.
    if (reads && (def.filters.includes('account') || def.key === 'trial-balance')) jobs.push(this.finance.loadAccounts());
    if (def.filters.includes('project') && this.may('finance.projects.view')) jobs.push(this.finance.loadProjects());
    await Promise.all(jobs);
  }

  private rateText(rates: RateUsed[]): string {
    const base = this.fmt.base();
    return rates
      .filter((rate) => rate.rate !== null && rate.currency !== base)
      .map((rate) => `${rate.currency} ${this.qty(rate.rate)}${rate.date ? ` (${this.short(rate.date)})` : ''}`)
      .join(' · ');
  }

  private metaLines(def: ReportDef, view: ReportView, title: string): [string, string][] {
    const lines: [string, string][] = [[this.t('frpReport'), title]];
    if (view.subtitle) lines.push([this.t('frpSubject'), view.subtitle]);
    if (def.filters.includes('period')) lines.push([this.t('frpPeriod'), `${this.short(this.draft.from)} – ${this.short(this.draft.to)}`]);
    if (def.filters.includes('asOf')) lines.push([this.t('finAsOf'), this.short(this.draft.as_of)]);
    if (def.filters.includes('branch')) lines.push([this.t('finBranch'), this.finance.branchName(this.draft.branch_id) || this.t('finAll')]);
    if (def.view) lines.push([this.t('frpShown'), this.t(this.draft.view === 'consolidated' ? 'frpViewConsolidated' : 'frpViewSeparate')]);
    if (this.ratesLine()) lines.push([this.t('frpRatesUsed'), this.ratesLine()]);
    return lines;
  }
}

function sameParams(a: Record<string, string>, b: Record<string, string>): boolean {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
  return [...keys].every((key) => (a[key] ?? '') === (b[key] ?? ''));
}
