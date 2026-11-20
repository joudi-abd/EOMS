import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CalcMethod, ComponentDraft, ComponentKind, FinSettingsApi, PercentageBase, SalaryComponent } from '../../../../data/finance-settings';
import { FinanceBase } from '../../finance-base';

interface ComponentForm {
  id: number | null;
  is_system: boolean;
  code: string;
  name: string;
  kind: ComponentKind;
  calc_method: CalcMethod;
  percentage_base: PercentageBase;
  percentage: number | null;
  is_project_linked: boolean;
  is_recurring: boolean;
  expense_account_id: number | null;
  is_active: boolean;
}

/**
 * Salary components (`/payroll/components`): what a pay line can be — earning or deduction, a fixed amount or a
 * percentage of the basic salary or a project fee, tied to a project or not, recurring or not, and the account it
 * posts to. Finance defines them (`finance.settings`); system components change only their name and account.
 */
@Component({
  selector: 'app-fst-salary-components',
  imports: [FormsModule],
  templateUrl: './salary-components.html',
  styleUrl: './salary-components.scss',
  host: { class: 'ledger' },
})
export class FstSalaryComponents extends FinanceBase implements OnInit {
  private readonly settings = inject(FinSettingsApi);

  /** Reading the list needs `payroll.view` on the server. */
  protected readonly mayRead = this.may('payroll.view');
  protected readonly items = signal<SalaryComponent[]>([]);
  protected readonly loaded = signal(false);
  protected form: ComponentForm | null = null;
  protected readonly editing = signal(false);

  /** An earning posts to an expense account; a deduction to an expense, liability or revenue account. */
  protected readonly accountsFor = computed(() => {
    const usable = this.finance.accounts().filter((item) => item.is_active && item.is_postable && !item.is_system && !item.cash_box_id);
    return {
      earning: usable.filter((item) => item.type === 'expense'),
      deduction: usable.filter((item) => ['expense', 'liability', 'revenue'].includes(item.type)),
    };
  });

  ngOnInit(): void {
    void this.load();
  }

  private async load(): Promise<void> {
    await this.quiet(async () => {
      await this.finance.loadAccounts();
      if (this.mayRead) this.items.set(await this.settings.components());
    });
    this.loaded.set(true);
  }

  protected method(item: SalaryComponent): string {
    if (item.calc_method !== 'percentage') return this.t('fstFixed');
    const base = item.percentage_base === 'project_fee' ? this.t('fstBaseProjectFee') : this.t('fstBaseSalary');
    return item.percentage ? `${this.qty(item.percentage)}% ${this.t('fstOf')} ${base}` : `${this.t('fstPercentage')} · ${base}`;
  }

  protected startNew(): void {
    this.form = {
      id: null,
      is_system: false,
      code: '',
      name: '',
      kind: 'earning',
      calc_method: 'fixed',
      percentage_base: 'basic_salary',
      percentage: null,
      is_project_linked: false,
      is_recurring: false,
      expense_account_id: null,
      is_active: true,
    };
    this.editing.set(true);
  }

  protected edit(item: SalaryComponent): void {
    this.form = {
      id: item.id,
      is_system: item.is_system,
      code: item.code,
      name: item.name,
      kind: item.kind,
      calc_method: item.calc_method,
      percentage_base: item.percentage_base ?? 'basic_salary',
      percentage: item.percentage === null ? null : Number(item.percentage),
      is_project_linked: item.is_project_linked,
      is_recurring: item.is_recurring,
      expense_account_id: item.expense_account_id,
      is_active: item.is_active,
    };
    this.editing.set(true);
  }

  protected close(): void {
    this.form = null;
    this.editing.set(false);
  }

  /** A share of a project fee belongs to that project and is entered on the run each month. */
  protected pickBase(form: ComponentForm, base: PercentageBase): void {
    form.percentage_base = base;
    if (base === 'project_fee') {
      form.is_project_linked = true;
      form.is_recurring = false;
    }
  }

  protected async submit(): Promise<void> {
    const form = this.form;
    if (!form) return;
    let body: ComponentDraft;
    if (form.is_system) {
      body = { name: form.name.trim(), expense_account_id: form.expense_account_id };
    } else {
      const percentage = form.calc_method === 'percentage';
      body = {
        name: form.name.trim(),
        kind: form.kind,
        calc_method: form.calc_method,
        percentage_base: percentage ? form.percentage_base : null,
        percentage: percentage && form.percentage ? Number(form.percentage) : null,
        is_project_linked: form.is_project_linked,
        is_recurring: form.is_recurring,
        expense_account_id: form.expense_account_id,
        is_active: form.is_active,
      };
    }
    const done = await this.act(
      () => (form.id ? this.settings.updateComponent(form.id, body) : this.settings.createComponent({ ...body, code: form.code.trim() })),
      'finSaved',
    );
    if (!done) return;
    this.close();
    await this.load();
  }
}
