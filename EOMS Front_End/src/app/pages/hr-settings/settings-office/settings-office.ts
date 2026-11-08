import { Component, inject, signal } from '@angular/core';
import { serverMessage } from '../../../core/api/api';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Server } from '../../../data/server';

@Component({
  selector: 'app-settings-office',
  templateUrl: './settings-office.html',
  styleUrl: './settings-office.scss',
})
export class SettingsOffice extends Ui {
  protected readonly session = inject(Session);
  private readonly server = inject(Server);
  protected readonly rows = signal<[string, string][]>([]);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected name = '';

  constructor() {
    super();
    void this.load();
  }

  protected async load(): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      const result = await this.server.companySettings();
      const entries = Object.entries(result.data ?? {});
      this.rows.set(entries);
      this.name = result.data?.['company.name'] ?? '';
    } catch (error) {
      this.error.set(serverMessage(error) || this.t('requestFailed'));
    } finally {
      this.busy.set(false);
    }
  }

  protected missingName(): boolean {
    return !this.rows().some((row) => row[0] === 'company.name');
  }

  protected async save(event: Event, key = 'company.name'): Promise<void> {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const value = String(new FormData(form).get('value') ?? '').trim();
    if (!value) return;
    this.busy.set(true);
    const saved = await this.server.saveSetting(key, value);
    this.busy.set(false);
    if (saved) await this.load();
  }
}
