import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Api, serverMessage, statusOf } from '../../core/api/api';
import { Auth } from '../../core/api/auth';
import { Ui } from '../../core/ui';

@Component({
  selector: 'app-login',
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login extends Ui {
  private readonly auth = inject(Auth);
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  protected readonly busy = signal(false);
  protected readonly problem = signal('');
  protected readonly reveal = signal(false);
  protected readonly year = new Date().getFullYear();
  /** The company name from the public settings; the shipped brand name until it arrives. */
  protected readonly company = signal('');

  constructor() {
    super();
    void this.api
      .get<{ data: Record<string, string> | unknown[] }>('/settings/config/public')
      .then((result) => {
        if (result.data && !Array.isArray(result.data)) this.company.set(result.data['company.name'] ?? '');
      })
      .catch(() => undefined);
  }

  protected async submit(event: Event): Promise<void> {
    event.preventDefault();
    const data = new FormData(event.target as HTMLFormElement);
    const email = String(data.get('email') ?? '').trim();
    const password = String(data.get('password') ?? '');
    if (!email || !password || this.busy()) return;
    this.busy.set(true);
    this.problem.set('');
    try {
      await this.auth.login(email, password);
      await this.router.navigate(['/']);
    } catch (error) {
      const status = statusOf(error);
      if (status === 429) this.problem.set(this.t('tooManyRequests'));
      else if (status >= 500) this.problem.set(this.t('serverError'));
      else this.problem.set(serverMessage(error) || this.t(status === 0 ? 'serverDown' : 'requestFailed'));
    } finally {
      this.busy.set(false);
    }
  }

  protected toggleReveal(): void {
    this.reveal.update((shown) => !shown);
  }

  protected toggleLanguage(): void {
    this.language.set(this.language.lang() === 'ar' ? 'en' : 'ar');
  }
}
