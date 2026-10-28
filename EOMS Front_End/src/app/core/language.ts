import { effect, Injectable, signal } from '@angular/core';
import { copy, CopyKey, Lang } from './copy';

@Injectable({ providedIn: 'root' })
export class Language {
  readonly lang = signal<Lang>('ar');

  constructor() {
    const saved = globalThis.localStorage?.getItem('eoms-lang');
    if (saved === 'ar' || saved === 'en') {
      this.lang.set(saved);
    }

    effect(() => {
      const lang = this.lang();
      document.documentElement.lang = lang;
      document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
      document.title = copy[lang].brand;
      globalThis.localStorage?.setItem('eoms-lang', lang);
    });
  }

  t(key: CopyKey): string {
    return copy[this.lang()][key];
  }

  text(value: { ar: string; en: string }): string {
    return value[this.lang()];
  }

  set(lang: Lang): void {
    this.lang.set(lang);
  }

  private locale(): string {
    return this.lang() === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US';
  }

  formatNumber(value: number): string {
    return new Intl.NumberFormat(this.locale()).format(value);
  }

  formatPercent(value: number): string {
    return new Intl.NumberFormat(this.locale(), {
      style: 'percent',
      maximumFractionDigits: 0,
    }).format(value / 100);
  }

  formatMonth(date = new Date()): string {
    return new Intl.DateTimeFormat(this.locale(), { month: 'long', year: 'numeric' }).format(date);
  }

  formatDayShort(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(this.locale(), { day: 'numeric', month: 'numeric' }).format(new Date(year, month - 1, day));
  }

  formatWeekday(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(this.locale(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(year, month - 1, day));
  }

  formatFullDate(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(this.locale(), {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    }).format(new Date(year, month - 1, day));
  }

  formatDate(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(this.locale(), {
      day: 'numeric',
      month: 'short',
    }).format(new Date(year, month - 1, day));
  }
}
