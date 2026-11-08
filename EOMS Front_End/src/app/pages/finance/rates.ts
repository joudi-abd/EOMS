import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { serverMessage } from '../../core/api/api';
import { Session } from '../../core/access/session';
import { Ui } from '../../core/ui';
import { Records } from '../../data/records';
import { ExchangeRateRow, Server } from '../../data/server';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';

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
  protected from = 'USD';
  protected to = 'SYP';
  protected rate = '';
  protected rateDate = new Date().toISOString().slice(0, 10);
  protected readonly latest = signal<ExchangeRateRow | null | undefined>(undefined);
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  constructor() {
    super();
    void this.records.loadCurrencies().then(() => this.lookup());
  }

  /** A date column can arrive as a UTC instant, so the calendar day is taken in local time. */
  protected rateDay(value: string): string {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return this.date(value);
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    const local = new Date(parsed.getTime() - parsed.getTimezoneOffset() * 60_000).toISOString().slice(0, 10);
    return this.date(local);
  }

  protected async lookup(): Promise<void> {
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
    if (saved) this.latest.set(saved);
  }

  /** Debug exceptions from the server are not something to print on the form. */
  private failure(error: unknown): string {
    const message = serverMessage(error);
    if (!message || message.length > 180 || message.includes('\\') || message.includes('Stack trace')) return this.t('requestFailed');
    return message;
  }
}
