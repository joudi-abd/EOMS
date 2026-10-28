import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Session } from '../../core/access/session';
import { Language } from '../../core/language';
import { Chrome } from '../chrome';

@Component({
  selector: 'app-account-switch',
  templateUrl: './account-switch.html',
  styleUrl: './account-switch.scss',
})
export class AccountSwitch {
  protected readonly session = inject(Session);
  protected readonly language = inject(Language);
  private readonly router = inject(Router);
  private readonly chrome = inject(Chrome);
  protected readonly leaving = signal(false);

  protected t(key: Parameters<Language['t']>[0]): string {
    return this.language.t(key);
  }

  protected text(value: { ar: string; en: string }): string {
    return this.language.text(value);
  }

  protected initials(): string {
    const words = this.text(this.session.account().name).trim().split(/\s+/);
    return words
      .slice(0, 2)
      .map((word) => word.replace(/^ال/, '').charAt(0))
      .join('');
  }

  protected async signOut(): Promise<void> {
    this.leaving.set(true);
    this.chrome.close();
    await this.session.logout();
    this.leaving.set(false);
    await this.router.navigate(['/login']);
  }
}
