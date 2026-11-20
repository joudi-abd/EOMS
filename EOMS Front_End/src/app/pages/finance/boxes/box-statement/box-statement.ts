import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { FinBox, monthStart, today } from '../../../../data/finance';
import { BoxStatement, FinBoxesApi, loginPeople } from '../../../../data/finance-boxes';
import { FinanceBase } from '../../finance-base';

/**
 * One box opened in place: its balances, quick receipt / payment / transfer links (`finance.vouchers`), and its
 * statement (`finance.reports`, T2) for one currency over a period (this month by default): opening, each movement
 * with its running balance, totals and closing.
 */
@Component({
  selector: 'app-fin-box-statement',
  imports: [FormsModule, RouterLink],
  templateUrl: './box-statement.html',
  styleUrl: './box-statement.scss',
})
export class BoxStatementView extends FinanceBase {
  readonly box = input.required<FinBox>();

  private readonly boxesApi = inject(FinBoxesApi);
  protected readonly statement = signal<BoxStatement | null>(null);
  protected readonly loading = signal(false);

  protected currencyId = 0;
  protected from = monthStart();
  protected to = today();

  protected readonly block = computed(() => this.statement()?.currencies[0] ?? null);
  protected readonly code = computed(() => this.box().currencies.find((row) => row.currency_id === this.picked())?.code ?? '');
  private readonly picked = signal(0);

  protected readonly custodian = computed(() => {
    const id = this.box().custodian_user_id;
    return id ? (loginPeople(this.office).find((item) => item.userId === id)?.name ?? null) : null;
  });

  constructor() {
    super();
    effect(() => {
      const box = this.box();
      untracked(() => void this.open(box));
    });
  }

  /** Opens a box on the currency already chosen, else the base currency (USD) when it holds it, else its first. */
  private async open(box: FinBox): Promise<void> {
    if (!box.currencies.some((row) => row.currency_id === this.currencyId)) {
      await this.finance.loadCurrencies().catch(() => undefined);
      const base = box.currencies.find((item) => this.finance.currency(item.currency_id)?.is_base);
      this.currencyId = (base ?? box.currencies[0])?.currency_id ?? 0;
    }
    await this.load();
  }

  protected pick(currencyId: number): void {
    this.currencyId = currencyId;
    void this.load();
  }

  protected async load(): Promise<void> {
    this.picked.set(this.currencyId);
    if (!this.may('finance.reports') || !this.currencyId || !this.from || !this.to) {
      this.statement.set(null);
      return;
    }
    this.loading.set(true);
    try {
      this.statement.set(await this.boxesApi.statement(this.box().id, this.currencyId, this.from, this.to));
    } catch (error) {
      this.statement.set(null);
      this.office.fail(error);
    } finally {
      this.loading.set(false);
    }
  }
}
