import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Permission } from '../../../core/access/accounts';
import { CopyKey } from '../../../core/copy';
import { FinSettingsApi, SetupStatus } from '../../../data/finance-settings';
import { Notice } from '../../../shared/notice/notice';
import { FinanceBase } from '../finance-base';
import { FstBranches } from './branches/branches';
import { FstCategories } from './categories/categories';
import { FstChart } from './chart/chart';
import { FstCurrencies } from './currencies/currencies';
import { FstPeriods } from './periods/periods';
import { FstSalaryComponents } from './salary-components/salary-components';
import { FstSetup } from './setup/setup';

export type SettingsTab = 'setup' | 'currencies' | 'branches' | 'chart' | 'categories' | 'components' | 'periods';

/** Each tab and the permission that shows it (null: everyone who reaches the page reads it). */
const tabs: readonly { id: SettingsTab; label: CopyKey; need: Permission | null }[] = [
  { id: 'setup', label: 'fstTabSetup', need: 'finance.view' },
  { id: 'currencies', label: 'fstTabCurrencies', need: null },
  { id: 'branches', label: 'fstTabBranches', need: 'finance.settings' },
  { id: 'chart', label: 'fstTabChart', need: 'finance.view' },
  { id: 'categories', label: 'fstTabCategories', need: 'finance.settings' },
  { id: 'components', label: 'fstTabComponents', need: 'finance.settings' },
  { id: 'periods', label: 'fstTabPeriods', need: 'finance.periods' },
];

/**
 * Finance settings: the setup checklist, currencies and the daily bulletin, branches, the chart of accounts,
 * categories, salary components and month closing — one tab each, shown only with its permission. The heading has no
 * action of its own; each tab carries its own.
 */
@Component({
  selector: 'app-fin-settings',
  imports: [RouterLink, Notice, FstSetup, FstCurrencies, FstBranches, FstChart, FstCategories, FstSalaryComponents, FstPeriods],
  templateUrl: './settings.html',
  styleUrl: './settings.scss',
})
export class FinSettingsPage extends FinanceBase implements OnInit {
  private readonly settings = inject(FinSettingsApi);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly status = signal<SetupStatus | null>(null);
  private readonly statusLoaded = signal(false);

  protected readonly allowed = computed(() =>
    (['finance.view', 'finance.settings', 'finance.rates', 'finance.periods'] as const).some((permission) => this.may(permission)),
  );

  protected readonly tabs = computed(() => tabs.filter((tab) => tab.need === null || this.may(tab.need)));

  /** The sub-part a link asks for (`?part=`), handed to the tab that has parts. */
  protected readonly part = computed(() => this.params()?.get('part') ?? '');

  /** The asked tab when it is shown; otherwise the setup while it is incomplete, else currencies and the bulletin. */
  protected readonly view = computed<SettingsTab | null>(() => {
    const asked = this.params()?.get('view');
    if (this.tabs().some((tab) => tab.id === asked)) return asked as SettingsTab;
    if (!this.may('finance.view')) return 'currencies';
    if (!this.statusLoaded()) return null;
    return this.status()?.completed === false ? 'setup' : 'currencies';
  });

  ngOnInit(): void {
    if (!this.allowed()) return;
    void this.finance.loadCurrencies();
    void this.finance.loadBranches();
    if (this.may('finance.view')) void this.loadStatus();
  }

  protected async loadStatus(): Promise<void> {
    await this.quiet(async () => this.status.set(await this.settings.setupStatus()));
    this.statusLoaded.set(true);
  }
}
