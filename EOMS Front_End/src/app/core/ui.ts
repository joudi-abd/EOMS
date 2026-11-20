import { inject } from '@angular/core';
import { Text } from '../data/text';
import { Confirm, ConfirmRequest } from './confirm';
import { CopyKey } from './copy';
import { Language } from './language';

export abstract class Ui {
  protected readonly language = inject(Language);
  private readonly confirmDialog = inject(Confirm);

  /** Asks before a sensitive or irreversible action. Resolves true only when the user confirms. */
  protected askConfirm(request: ConfirmRequest): Promise<boolean> {
    return this.confirmDialog.ask(request);
  }

  protected askDelete(message: CopyKey = 'confirmDeleteBody', detail?: string): Promise<boolean> {
    return this.confirmDialog.delete(message, detail);
  }

  protected t(key: CopyKey): string {
    return this.language.t(key);
  }

  protected text(value: Text): string {
    return this.language.text(value);
  }

  protected date(iso: string): string {
    return iso ? this.language.formatFullDate(iso.slice(0, 10)) : '—';
  }

  protected short(iso: string): string {
    return iso ? this.language.formatDate(iso.slice(0, 10)) : '—';
  }

  /** A server timestamp as a readable day and time. */
  protected stamp(iso: string | null): string {
    return iso ? this.language.formatDateTime(iso) : '—';
  }

  /** Two initials for an avatar; the Arabic definite article is not a name's first letter. */
  protected initials(value: Text): string {
    return this.text(value)
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((word) => word.replace(/^ال/, '').charAt(0))
      .join('\u200c');
  }

  protected num(value: number): string {
    return this.language.formatNumber(value);
  }

  protected percent(value: number): string {
    return this.language.formatPercent(value);
  }

  protected hours(minutes: number | null): string {
    if (minutes === null) return '—';
    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
  }
}
