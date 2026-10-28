import { afterNextRender, DestroyRef, ElementRef, inject, signal } from '@angular/core';

export interface ChartSeries {
  key: string;
  label: string;
  color: string;
}

export interface ChartDatum {
  label: string;
  values: Record<string, number>;
}

export interface ChartSlice {
  label: string;
  value: number;
  color: string;
}

export interface ChartRow {
  label: string;
  value: number;
  note?: string;
  unit?: string;
  color?: string;
}

/** Colors are CSS custom properties so the charts follow the theme and print the same. */
export const tone = {
  blue: 'var(--series-1)',
  sky: 'var(--series-2)',
  teal: 'var(--series-3)',
  slate: 'var(--series-4)',
  violet: 'var(--series-5)',
  ok: 'var(--fill-ok)',
  wait: 'var(--fill-wait)',
  late: 'var(--fill-late)',
  info: 'var(--fill-info)',
  quiet: 'var(--fill-quiet)',
};

/** Axes draw four gridlines, so the top is four times a round step. */
export function niceMax(value: number): number {
  if (value <= 4) return 4;
  const raw = value / 4;
  const power = 10 ** Math.floor(Math.log10(raw));
  const steps = power === 1 ? [1, 2, 3, 4, 5, 6, 8, 10] : [1, 1.5, 2, 2.5, 3, 4, 5, 6, 7.5, 8, 10];
  return (steps.find((item) => item * power >= raw) ?? 10) * power * 4;
}

/** Tracks the rendered width of the host so the SVG draws at real pixel size. */
export function hostWidth(fallback = 640) {
  const width = signal(fallback);
  const host = inject(ElementRef<HTMLElement>);
  const destroy = inject(DestroyRef);
  afterNextRender(() => {
    const element = host.nativeElement as HTMLElement;
    const read = () => {
      const next = Math.round(element.clientWidth || element.getBoundingClientRect().width);
      if (next > 0 && next !== width()) width.set(Math.max(240, next));
    };
    read();
    requestAnimationFrame(read);
    if (typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(read);
    observer.observe(element);
    destroy.onDestroy(() => observer.disconnect());
  });
  return width;
}
