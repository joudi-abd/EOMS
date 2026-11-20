import { Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { FinCurrency, today } from '../../../../data/finance';
import { Bulletin, CurrencyDraft, FinSettingsApi, RateHistoryRow } from '../../../../data/finance-settings';
import { FinanceBase } from '../../finance-base';

interface CurrencyForm {
  id: number | null;
  code: string;
  name: string;
  symbol: string;
  decimals: number;
  rounding_step: number | null;
  is_payroll_currency: boolean;
  is_active: boolean;
  is_base: boolean;
}

/**
 * Currencies and the daily bulletin. The bulletin (what people do every day) leads: "1 USD = … X" for every active
 * currency on a day, the missing ones highlighted, saved together; a currency's last 30 rates open beside it.
 * The currencies themselves sit in the second part, with add and edit. Reading is open to everyone on the page;
 * rates need `finance.rates`, currencies `finance.settings`.
 */
@Component({
  selector: 'app-fst-currencies',
  imports: [FormsModule],
  templateUrl: './currencies.html',
  styleUrl: './currencies.scss',
  host: { class: 'ledger' },
})
export class FstCurrencies extends FinanceBase implements OnInit {
  private readonly settings = inject(FinSettingsApi);

  readonly part = input('');

  protected readonly view = signal<'rates' | 'list'>('rates');
  protected readonly mayRates = this.may('finance.rates');
  protected readonly mayEdit = this.may('finance.settings');

  // Bulletin
  protected readonly day = signal(today());
  protected readonly bulletin = signal<Bulletin | null>(null);
  protected readonly values = signal<Record<string, number | null>>({});
  protected readonly historyOf = signal<FinCurrency | null>(null);
  protected readonly history = signal<RateHistoryRow[]>([]);

  /** Every active currency but the base, in code order, with its rate that day or a gap. */
  protected readonly rows = computed(() => {
    const entered = new Map((this.bulletin()?.rates ?? []).map((item) => [item.code, item]));
    return this.finance
      .activeCurrencies()
      .filter((item) => !item.is_base)
      .sort((a, b) => a.code.localeCompare(b.code))
      .map((currency) => ({ currency, entered: entered.get(currency.code) ?? null }));
  });
  protected readonly base = computed(() => this.bulletin()?.base ?? this.finance.currencies().find((item) => item.is_base)?.code ?? 'USD');
  protected readonly missing = computed(() => this.bulletin()?.missing ?? []);

  // Currencies
  protected form: CurrencyForm | null = null;
  protected readonly editing = signal(false);

  ngOnInit(): void {
    if (this.part() === 'list') this.view.set('list');
    void this.loadBulletin();
  }

  protected async loadBulletin(): Promise<void> {
    await this.quiet(async () => {
      await this.finance.loadCurrencies();
      const bulletin = await this.settings.bulletin(this.day());
      this.fill(bulletin);
    });
  }

  private fill(bulletin: Bulletin): void {
    this.bulletin.set(bulletin);
    const values: Record<string, number | null> = {};
    for (const item of bulletin.rates) values[item.code] = Number(item.rate_to_base);
    this.values.set(values);
  }

  protected pickDay(day: string): void {
    if (!day) return;
    this.day.set(day);
    void this.loadBulletin();
  }

  protected setValue(code: string, value: number | null): void {
    this.values.set({ ...this.values(), [code]: value });
  }

  protected async saveRates(): Promise<void> {
    const rates: Record<string, number> = {};
    for (const [code, value] of Object.entries(this.values())) {
      if (value !== null && value !== undefined && `${value}` !== '' && Number(value) > 0) rates[code] = Number(value);
    }
    if (!Object.keys(rates).length) {
      this.warn('fstRatesEmpty');
      return;
    }
    const saved = await this.act(() => this.settings.saveRates(this.day(), rates), 'fstRatesSaved');
    if (saved) this.fill(saved);
    if (saved && this.historyOf()) await this.openHistory(this.historyOf()!);
  }

  protected async copyPrevious(): Promise<void> {
    const copied = await this.act(() => this.settings.copyPrevious(this.day()), 'fstRatesCopied');
    if (copied) this.fill(copied);
  }

  protected async openHistory(currency: FinCurrency): Promise<void> {
    this.historyOf.set(currency);
    this.history.set([]);
    await this.quiet(async () => this.history.set(await this.settings.history(currency.code, 30)));
  }

  /** A rate with up to eight places and no padding. */
  protected rate(value: string | number | null | undefined): string {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 8 }).format(Number(value ?? 0) || 0);
  }

  // ----- Currencies -----

  protected startNew(): void {
    this.form = { id: null, code: '', name: '', symbol: '', decimals: 2, rounding_step: 1, is_payroll_currency: false, is_active: true, is_base: false };
    this.editing.set(true);
  }

  protected edit(item: FinCurrency): void {
    this.form = {
      id: item.id,
      code: item.code,
      name: item.name,
      symbol: item.symbol ?? '',
      decimals: item.decimals,
      rounding_step: item.rounding_step === null ? null : Number(item.rounding_step),
      is_payroll_currency: item.is_payroll_currency,
      is_active: item.is_active,
      is_base: item.is_base,
    };
    this.editing.set(true);
  }

  protected close(): void {
    this.form = null;
    this.editing.set(false);
  }

  protected async saveCurrency(): Promise<void> {
    const form = this.form;
    if (!form) return;
    // The server takes a positive rounding step or none at all; an empty field is left out, never sent as null.
    const body: CurrencyDraft = {
      name: form.name.trim(),
      symbol: form.symbol.trim(),
      decimals: Number(form.decimals),
      is_payroll_currency: form.is_payroll_currency,
      is_active: form.is_active,
    };
    if (form.rounding_step !== null && `${form.rounding_step}` !== '') body.rounding_step = Number(form.rounding_step);
    const done = await this.act(
      () => (form.id ? this.settings.updateCurrency(form.id, body) : this.settings.createCurrency({ ...body, code: form.code.trim().toUpperCase() })),
      'finSaved',
    );
    if (!done) return;
    this.close();
    await this.finance.loadCurrencies(true);
    await this.loadBulletin();
  }
}
