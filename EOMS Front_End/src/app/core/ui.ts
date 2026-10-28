import { inject } from '@angular/core';
import { Text } from '../data/text';
import { CopyKey } from './copy';
import { Language } from './language';

export abstract class Ui {
  protected readonly language = inject(Language);

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
