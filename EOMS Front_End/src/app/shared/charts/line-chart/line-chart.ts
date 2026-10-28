import { Component, computed, inject, input, signal } from '@angular/core';
import { Language } from '../../../core/language';
import { ChartDatum, ChartSeries, hostWidth, niceMax } from '../chart';

/** A trend over ordered categories. The first series carries a soft area under its line. */
@Component({
  selector: 'app-line-chart',
  templateUrl: './line-chart.html',
  styleUrl: './line-chart.scss',
})
export class LineChart {
  readonly data = input.required<ChartDatum[]>();
  readonly series = input.required<ChartSeries[]>();
  readonly label = input.required<string>();
  readonly height = input(240);
  readonly unit = input('');
  readonly max = input<number | null>(null);
  protected readonly language = inject(Language);
  protected readonly width = hostWidth();
  protected readonly active = signal<number | null>(null);

  protected readonly rtl = computed(() => this.language.lang() === 'ar');
  protected readonly pad = { top: 14, bottom: 30, start: 44, end: 12 };
  protected readonly gradientId = `area-${Math.random().toString(36).slice(2, 9)}`;

  private readonly values = computed(() => this.data().flatMap((item) => this.series().map((series) => item.values[series.key] ?? 0)));

  protected readonly top = computed(() => {
    const ceiling = this.max();
    if (ceiling !== null) return ceiling;
    return niceMax(Math.max(0, ...this.values()));
  });

  protected readonly floor = computed(() => {
    if (this.unit() !== '%' && this.max() !== 100) return 0;
    const low = Math.min(...this.values(), this.top());
    if (low >= 80) return 80;
    if (low >= 60) return 60;
    if (low >= 40) return 40;
    return 0;
  });

  protected readonly plot = computed(() => ({
    width: Math.max(10, this.width() - this.pad.start - this.pad.end),
    height: Math.max(10, this.height() - this.pad.top - this.pad.bottom),
  }));

  protected readonly step = computed(() => this.plot().width / Math.max(1, this.data().length - 1));

  protected readonly ticks = computed(() => {
    const span = Math.max(1, this.top() - this.floor());
    return [0, 0.25, 0.5, 0.75, 1].map((share) => ({
      value: this.floor() + span * share,
      y: this.pad.top + this.plot().height * (1 - share),
    }));
  });

  protected readonly lines = computed(() =>
    this.series().map((series, order) => {
      const points = this.data().map((item, index) => ({ x: this.xAt(index), y: this.yAt(item.values[series.key] ?? 0) }));
      const path = points.map((point, index) => `${index ? 'L' : 'M'}${point.x.toFixed(1)},${point.y.toFixed(1)}`).join(' ');
      const base = this.pad.top + this.plot().height;
      const area = points.length ? `${path} L${points[points.length - 1].x.toFixed(1)},${base} L${points[0].x.toFixed(1)},${base} Z` : '';
      return { key: series.key, color: series.color, path, area: order === 0 ? area : '', points };
    }),
  );

  protected readonly labels = computed(() => {
    const room = Math.max(1, Math.floor(this.plot().width / 64));
    const step = Math.ceil(this.data().length / room);
    const last = this.data().length - 1;
    return this.data().map((item, index) => ({ text: item.label, x: this.xAt(index), shown: (last - index) % step === 0 }));
  });

  protected readonly tip = computed(() => {
    const index = this.active();
    if (index === null) return null;
    const item = this.data()[index];
    if (!item) return null;
    return {
      x: Math.min(Math.max(this.xAt(index), 80), this.width() - 80),
      guide: this.xAt(index),
      title: item.label,
      rows: this.series().map((series) => ({ label: series.label, color: series.color, value: this.format(item.values[series.key] ?? 0) })),
    };
  });

  protected axisX(): number {
    return this.rtl() ? this.width() - this.pad.start + 8 : this.pad.start - 8;
  }

  protected format(value: number): string {
    const rounded = Math.round(value * 10) / 10;
    return `${this.language.formatNumber(rounded)}${this.unit()}`;
  }

  protected track(event: PointerEvent): void {
    const box = (event.currentTarget as SVGElement).getBoundingClientRect();
    const offset = event.clientX - box.left;
    const local = this.rtl() ? this.width() - offset : offset;
    const index = Math.round((local - this.pad.start) / this.step());
    this.active.set(index >= 0 && index < this.data().length ? index : null);
  }

  private xAt(index: number): number {
    const x = this.pad.start + (this.data().length === 1 ? this.plot().width / 2 : this.step() * index);
    return this.rtl() ? this.width() - x : x;
  }

  private yAt(value: number): number {
    const span = Math.max(1, this.top() - this.floor());
    return this.pad.top + this.plot().height * (1 - Math.min(1, Math.max(0, value - this.floor()) / span));
  }
}
