import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, serverMessage } from '../../core/api/api';
import { ApiItem } from '../../core/api/types';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import { Office } from '../../data/office';
import { Records } from '../../data/records';
import { ExchangeRateRow, Server } from '../../data/server';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';

/** The setting groups the backend seeds (`sys_settings.setting_group`). */
const groups = ['company', 'attendance', 'finance', 'payroll'] as const;
type Group = (typeof groups)[number];

interface SettingRow {
  key: string;
  value: string;
  saved: string;
}

/**
 * Currencies, exchange rates and system settings. Rates are read and recorded through `/settings/exchange-rates`;
 * settings by group through `/settings/config/{group}` (settings.view) and saved one key at a time (settings.manage).
 */
@Component({
  selector: 'app-rates',
  imports: [FormsModule, RouterLink, Notice],
  templateUrl: './rates.html',
  styleUrl: './rates.scss',
})
export class Rates extends Ui {
  protected readonly chrome = inject(Chrome);
  protected readonly session = inject(Session);
  protected readonly records = inject(Records);
  private readonly server = inject(Server);
  private readonly api = inject(Api);
  private readonly office = inject(Office);
  protected readonly groups = groups;

  protected from = 'USD';
  protected to = 'SYP';
  protected rate: number | null = null;
  protected rateDate = new Date().toISOString().slice(0, 10);
  protected readonly latest = signal<ExchangeRateRow | null | undefined>(undefined);
  protected readonly shownPair = signal('USD → SYP');
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  protected readonly group = signal<Group>('company');
  protected readonly settings = signal<SettingRow[]>([]);
  protected readonly settingsLoading = signal(false);
  protected readonly defaultCurrency = signal('');

  constructor() {
    super();
    void this.records.loadCurrencies().then(() => this.lookup());
    if (this.session.has('settings.view')) {
      void this.loadGroup('company');
      void this.loadDefaultCurrency();
    }
  }

  /** A date column can arrive as a UTC instant, so the calendar day is taken in local time. */
  protected rateDay(value: string): string {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return this.date(value);
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    const local = new Date(parsed.getTime() - parsed.getTimezoneOffset() * 60_000).toISOString().slice(0, 10);
    return this.date(local);
  }

  protected rateText(row: ExchangeRateRow): string {
    const value = Number(row.rate);
    return Number.isFinite(value) ? new Intl.NumberFormat('en-US', { maximumFractionDigits: 6 }).format(value) : String(row.rate);
  }

  protected swap(): void {
    [this.from, this.to] = [this.to, this.from];
    void this.lookup();
  }

  protected async lookup(): Promise<void> {
    this.shownPair.set(`${this.from} → ${this.to}`);
    if (this.from === this.to) {
      this.error.set(this.t('exchangeNone'));
      this.latest.set(null);
      return;
    }
    this.busy.set(true);
    this.error.set('');
    try {
      const result = await this.server.latestRate(this.from, this.to);
      this.latest.set(result.data);
    } catch (error) {
      this.error.set(this.failure(error));
    } finally {
      this.busy.set(false);
    }
  }

  protected async save(): Promise<void> {
    const value = Number(this.rate);
    if (!this.rateDate || !Number.isFinite(value) || value <= 0 || this.from === this.to) return;
    this.busy.set(true);
    this.error.set('');
    const saved = await this.server.saveRate(this.from, this.to, value, this.rateDate);
    this.busy.set(false);
    if (saved) {
      this.latest.set(saved);
      this.shownPair.set(`${this.from} → ${this.to}`);
      this.rate = null;
    }
  }

  // System settings

  protected groupLabel(group: Group): string {
    return this.t(group === 'company' ? 'setGroupCompany' : group === 'attendance' ? 'setGroupAttendance' : group === 'finance' ? 'setGroupFinance' : 'setGroupPayroll');
  }

  /** The seeded keys get a readable name; any other key shows as stored. */
  protected keyLabel(key: string): string {
    const known: Record<string, CopyKey> = {
      'company.name': 'setKeyCompanyName',
      'attendance.work_start': 'setKeyWorkStart',
      'attendance.work_end': 'setKeyWorkEnd',
      'attendance.grace_minutes': 'setKeyGrace',
      'currency.default': 'setKeyDefaultCurrency',
      'payroll.rounding_decimals': 'setKeyRounding',
    };
    const label = known[key];
    return label ? this.t(label) : key;
  }

  protected async loadGroup(group: Group): Promise<void> {
    this.group.set(group);
    this.settingsLoading.set(true);
    try {
      const result = await this.api.get<ApiItem<Record<string, string> | unknown[]>>(`/settings/config/${group}`);
      const data = Array.isArray(result.data) ? {} : result.data;
      this.settings.set(Object.entries(data).map(([key, value]) => ({ key, value: String(value ?? ''), saved: String(value ?? '') })));
    } catch (error) {
      this.settings.set([]);
      this.office.fail(error);
    } finally {
      this.settingsLoading.set(false);
    }
  }

  private async loadDefaultCurrency(): Promise<void> {
    try {
      const result = await this.api.get<ApiItem<Record<string, string> | unknown[]>>('/settings/config/finance');
      const data = Array.isArray(result.data) ? {} : result.data;
      this.defaultCurrency.set(data['currency.default'] ?? '');
    } catch {
      /* the currencies list still shows without the default mark */
    }
  }

  protected async saveSetting(row: SettingRow): Promise<void> {
    if (row.value === row.saved) return;
    this.busy.set(true);
    try {
      await this.api.put(`/settings/config/${row.key}`, { value: row.value, group: this.group() });
      row.saved = row.value;
      this.settings.update((rows) => [...rows]);
      if (row.key === 'currency.default') this.defaultCurrency.set(row.value);
      this.office.done('setSaved');
    } catch (error) {
      this.office.fail(error);
    } finally {
      this.busy.set(false);
    }
  }

  /** Debug exceptions from the server are not something to print on the form. */
  private failure(error: unknown): string {
    const message = serverMessage(error);
    if (!message || message.length > 180 || message.includes('\\') || message.includes('Stack trace')) return this.t('requestFailed');
    return message;
  }
}
