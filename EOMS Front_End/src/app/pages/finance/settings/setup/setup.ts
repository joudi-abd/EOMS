import { Component, inject, input, OnInit, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { FinSettingsApi, OpeningCheck, OpeningResult, plainMessage, SetupKey, SetupStatus, SetupStep } from '../../../../data/finance-settings';
import { FinanceBase } from '../../finance-base';
import { FstOpening } from '../opening/opening';

const stepTitle: Record<SetupKey, CopyKey> = {
  currencies: 'fstStepCurrencies',
  branches: 'fstStepBranches',
  chart: 'fstStepChart',
  boxes: 'fstStepBoxes',
  parties: 'fstStepParties',
  projects: 'fstStepProjects',
  opening_balances: 'fstStepOpening',
  opening_check: 'fstStepCheck',
};

/** Where each step is fixed: a settings tab (with its part) or another finance area. */
interface StepLink {
  path: string;
  params: Record<string, string> | null;
  label: CopyKey;
}

/**
 * The setup tab: the eight steps of `GET /finance/setup/status` as a checklist — done, or what is still missing in
 * plain words with a link to where it is fixed. Step 3 loads the starter chart, step 7 opens the opening-balances
 * sheet in place, step 8 shows the opening check.
 */
@Component({
  selector: 'app-fst-setup',
  imports: [RouterLink, FstOpening],
  templateUrl: './setup.html',
  styleUrl: './setup.scss',
  host: { class: 'ledger' },
})
export class FstSetup extends FinanceBase implements OnInit {
  private readonly settings = inject(FinSettingsApi);

  readonly status = input<SetupStatus | null>(null);
  readonly changed = output<void>();

  protected readonly opening = signal(false);
  protected readonly check = signal<OpeningCheck | null>(null);
  protected readonly checking = signal(false);
  protected readonly plain = plainMessage;

  ngOnInit(): void {
    // Other tabs may have fixed a step since the page opened.
    if (this.status()) this.changed.emit();
  }

  protected title(step: SetupStep): string {
    return this.t(stepTitle[step.key] ?? 'fstTabSetup');
  }

  protected link(step: SetupStep): StepLink | null {
    const settings = (view: string, part: string | null, label: CopyKey, need: 'finance.settings' | null = 'finance.settings'): StepLink | null =>
      need && !this.may(need) ? null : { path: '/modules/finSettings', params: part ? { view, part } : { view }, label };
    switch (step.key) {
      case 'currencies':
        return settings('currencies', step.details.without_rate?.length ? 'rates' : 'list', 'fstGoCurrencies', null);
      case 'branches':
        return settings('branches', null, 'fstGoBranches');
      case 'chart':
        return step.details.starter_chart_available ? null : settings('chart', 'roles', 'fstGoChart', null);
      case 'boxes':
        return { path: '/modules/finBoxes', params: null, label: 'fstGoBoxes' };
      case 'parties':
        return { path: '/modules/finParties', params: null, label: 'fstGoParties' };
      case 'projects':
        return this.may('finance.projects.view') || this.may('finance.projects')
          ? { path: '/modules/finProjects', params: null, label: 'fstGoProjects' }
          : null;
      default:
        return null;
    }
  }

  protected startDate(step: SetupStep): string {
    return (step.details.start_date as string | null | undefined) ?? '';
  }

  protected async starterChart(): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', tone: 'danger', confirm: 'confirmProceed' }))) return;
    const done = await this.act(() => this.settings.starterChart(), 'fstStarterLoaded');
    if (!done) return;
    await this.finance.loadAccounts(true);
    await this.finance.loadExpenseCategories(true);
    await this.finance.loadCostCategories(true);
    this.changed.emit();
  }

  protected async runCheck(): Promise<void> {
    this.checking.set(true);
    await this.quiet(async () => this.check.set(await this.settings.openingCheck()));
    this.checking.set(false);
  }

  protected saved(result: OpeningResult): void {
    this.opening.set(false);
    this.check.set(result.check);
    this.changed.emit();
  }
}
