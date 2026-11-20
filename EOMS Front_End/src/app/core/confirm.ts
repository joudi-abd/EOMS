import { Injectable, signal } from '@angular/core';
import { CopyKey } from './copy';

export type ConfirmTone = 'danger' | 'action';

/** What the confirmation dialog shows. `message` is the main wording; `detail` is optional free text under it. */
export interface ConfirmRequest {
  title?: CopyKey;
  message: CopyKey;
  detail?: string;
  confirm?: CopyKey;
  cancel?: CopyKey;
  tone?: ConfirmTone;
}

/**
 * One confirmation dialog for the whole app. Destructive and sensitive actions call `ask` (or `delete`)
 * and wait for the answer before they continue.
 */
@Injectable({ providedIn: 'root' })
export class Confirm {
  readonly request = signal<ConfirmRequest | null>(null);
  private pending: ((ok: boolean) => void) | null = null;

  ask(request: ConfirmRequest): Promise<boolean> {
    if (this.pending) {
      this.pending(false);
      this.pending = null;
    }
    this.request.set(request);
    return new Promise((resolve) => {
      this.pending = resolve;
    });
  }

  /** Irreversible deletes: danger tone and a clear delete label. */
  delete(message: CopyKey, detail?: string): Promise<boolean> {
    return this.ask({
      title: 'confirmDeleteTitle',
      message,
      detail,
      confirm: 'confirmDelete',
      tone: 'danger',
    });
  }

  settle(ok: boolean): void {
    this.request.set(null);
    const done = this.pending;
    this.pending = null;
    done?.(ok);
  }
}
