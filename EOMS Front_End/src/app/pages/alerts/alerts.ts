import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { serverMessage } from '../../core/api/api';
import { Ui } from '../../core/ui';
import { NoticeItem, Server } from '../../data/server';

@Component({
  selector: 'app-alerts',
  imports: [RouterLink],
  templateUrl: './alerts.html',
  styleUrl: './alerts.scss',
})
export class Alerts extends Ui {
  protected readonly server = inject(Server);
  protected readonly rows = signal<NoticeItem[]>([]);
  protected readonly page = signal(1);
  protected readonly lastPage = signal(1);
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  constructor() {
    super();
    void this.open(1);
  }

  protected async open(page: number): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      const result = await this.server.notifications(page);
      this.rows.set(result.data);
      this.page.set(page);
      this.lastPage.set(result.lastPage);
    } catch (error) {
      this.error.set(serverMessage(error) || this.t('requestFailed'));
    } finally {
      this.busy.set(false);
    }
  }

  /** Task notifications carry the board and card they are about: link straight to the card. */
  protected taskLink(item: NoticeItem): { board: number; card?: number } | null {
    const board = Number(item.data?.['board_id'] ?? 0);
    if (!board || !item.kind?.startsWith('tasks.')) return null;
    const card = Number(item.data?.['card_id'] ?? 0);
    return card ? { board, card } : { board };
  }

  protected async read(id: string): Promise<void> {
    this.busy.set(true);
    try {
      await this.server.readNotice(id);
      await this.open(this.page());
    } catch (error) {
      this.error.set(serverMessage(error) || this.t('requestFailed'));
      this.busy.set(false);
    }
  }

  protected async readAll(): Promise<void> {
    this.busy.set(true);
    try {
      await this.server.readEveryNotice();
      await this.open(this.page());
    } catch (error) {
      this.error.set(serverMessage(error) || this.t('requestFailed'));
      this.busy.set(false);
    }
  }
}
