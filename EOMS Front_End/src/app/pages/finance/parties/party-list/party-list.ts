import { Component, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { FinanceParties } from '../../../../data/finance-parties';
import { FinParty, PartyRole } from '../../../../data/finance';
import { FinanceBase } from '../../finance-base';

/** The parties list: a role choice, a search box and an "inactive too" toggle over a 25-row sheet. */
@Component({
  selector: 'app-fin-parties-list',
  imports: [FormsModule, RouterLink],
  templateUrl: './party-list.html',
  styleUrl: './party-list.scss',
  host: { class: 'ledger' },
})
export class FinPartiesList extends FinanceBase {
  private readonly parties = inject(FinanceParties);

  /** Bumped by the page after a save or an import. */
  readonly version = input(0);
  readonly employee = input<number | null>(null);
  readonly create = output<void>();

  protected readonly roleChoices: { role: PartyRole | ''; label: CopyKey }[] = [
    { role: '', label: 'finAll' },
    { role: 'client', label: 'fpaClients' },
    { role: 'supplier', label: 'fpaSuppliers' },
    { role: 'contractor', label: 'fpaContractors' },
    { role: 'employee', label: 'fpaEmployees' },
    { role: 'partner', label: 'fpaPartners' },
    { role: 'other', label: 'fpaOthers' },
  ];

  protected readonly role = signal<PartyRole | ''>('');
  protected readonly search = signal('');
  protected readonly inactiveToo = signal(false);
  protected readonly page = signal(1);

  protected readonly rows = signal<FinParty[]>([]);
  protected readonly lastPage = signal(1);
  protected readonly total = signal(0);
  protected readonly loading = signal(true);

  protected readonly mayEdit = this.may('finance.vouchers');
  private typing: ReturnType<typeof setTimeout> | undefined;

  constructor() {
    super();
    effect(() => {
      const query = {
        role: this.role(),
        search: this.search().trim(),
        active: this.inactiveToo() ? undefined : true,
        employee_id: this.employee() ?? undefined,
      };
      const page = this.page();
      this.version();
      untracked(() => void this.load(query, page));
    });
  }

  private async load(query: Parameters<FinanceParties['list']>[0], page: number): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const result = await this.parties.list(query, page);
      this.rows.set(result.data);
      this.lastPage.set(result.meta.last_page || 1);
      this.total.set(result.meta.total ?? result.data.length);
    });
    this.loading.set(false);
  }

  protected pick(role: PartyRole | ''): void {
    this.role.set(role);
    this.page.set(1);
  }

  protected typed(value: string): void {
    clearTimeout(this.typing);
    this.typing = setTimeout(() => {
      this.search.set(value);
      this.page.set(1);
    }, 300);
  }

  protected showInactive(value: boolean): void {
    this.inactiveToo.set(value);
    this.page.set(1);
  }

  protected filtered(): boolean {
    return !!this.role() || !!this.search().trim() || this.inactiveToo() || !!this.employee();
  }
}
