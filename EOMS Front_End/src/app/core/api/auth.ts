import { computed, inject, Injectable, signal } from '@angular/core';
import { Api, statusOf } from './api';
import { ApiItem, ApiUser } from './types';

const tokenKey = 'eoms-token';

@Injectable({ providedIn: 'root' })
export class Auth {
  private readonly api = inject(Api);
  readonly token = signal<string | null>(globalThis.localStorage?.getItem(tokenKey) ?? null);
  readonly user = signal<ApiUser | null>(null);
  readonly userId = computed(() => this.user()?.id ?? null);
  private restoring: Promise<boolean> | null = null;

  async login(email: string, password: string): Promise<void> {
    const result = await this.api.post<ApiItem<{ token: string; user: ApiUser }>>('/auth/login', { email, password });
    globalThis.localStorage?.setItem(tokenKey, result.data.token);
    this.token.set(result.data.token);
    this.user.set(result.data.user);
  }

  /** Resolves the signed-in user from the stored token. A rejected token is dropped; an unreachable server keeps it. */
  restore(): Promise<boolean> {
    if (this.user()) return Promise.resolve(true);
    if (!this.token()) return Promise.resolve(false);
    this.restoring ??= this.api
      .get<ApiItem<{ user: ApiUser }>>('/auth/me')
      .then((result) => {
        this.user.set(result.data.user);
        return true;
      })
      .catch((error: unknown) => {
        if (statusOf(error) === 401) this.clear();
        return false;
      })
      .finally(() => {
        this.restoring = null;
      });
    return this.restoring;
  }

  async logout(): Promise<void> {
    try {
      await this.api.post('/auth/logout');
    } catch {
      // The token is dropped locally whatever the server answers.
    }
    this.clear();
  }

  clear(): void {
    globalThis.localStorage?.removeItem(tokenKey);
    this.token.set(null);
    this.user.set(null);
  }
}
