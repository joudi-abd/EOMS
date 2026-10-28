import { Component, computed, inject, input, signal } from '@angular/core';
import { Language } from '../../../core/language';
import { ChartDatum, ChartSeries, hostWidth, niceMax } from '../chart';

interface Bar {
  key: string;
  x: number;
  y: number;
  width: number;
  height: number;
  color: string;
}

/** Vertical bars by category: grouped side by side, or stacked when `stacked` is set. */
@Component({
  selector: 'app-bar-chart',
  templateUrl: './bar-chart.html',
  styleUrl: './bar-chart.scss',
})
export class BarChart {
  readonly data = input.required<ChartDatum[]>();
  readonly series = input.required<ChartSeries[]>();
  readonly label = input.required<string>();
  readonly stacked = input(false);
  readonly height = input(240);
  readonly unit = input('');
  readonly max = input<number | null>(null);
  protected readonly language = inject(Language);
  protected readonly width = hostWidth();
  protected readonly active = signal<number | null>(null);

  protected readonly rtl = computed(() => this.language.lang() === 'ar');
  protected readonly pad = { top: 14, bottom: 30, start: 44, end: 8 };

  protected readonly top = computed(() => {
    const ceiling = this.max();
    if (ceiling !== null) return ceiling;
    const values = this.data().map((item) =>
      this.stacked()
        ? this.series().reduce((sum, series) => sum + (item.values[series.key] ?? 0), 0)
        : Math.max(0, ...this.series().map((series) => item.values[series.key] ?? 0)),
    );
    return niceMax(Math.max(0, ...values));
  });

  protected readonly plot = computed(() => ({
    width: Math.max(10, this.width() - this.pad.start - this.pad.end),
    height: Math.max(10, this.height() - this.pad.top - this.pad.bottom),
  }));

  protected readonly band = computed(() => this.plot().width / Math.max(1, this.data().length));

  protected readonly ticks = computed(() => {
    const top = this.top();
    return [0, 0.25, 0.5, 0.75, 1].map((share) => ({
      value: top * share,
      y: this.pad.top + this.plot().height * (1 - share),
    }));
  });

  protected readonly bars = computed<Bar[][]>(() => {
    const plot = this.plot();
    const band = this.band();
    const group = Math.min(band * 0.68, 64);
    const count = this.series().length;
    return this.data().map((item, index) => {
      const center = this.pad.start + band * index + band / 2;
      if (this.stacked()) {
        let base = 0;
        return this.series().map((series) => {
          const value = item.values[series.key] ?? 0;
          const height = (value / this.top()) * plot.height;
          const bar = { key: series.key, x: this.mirror(center - group / 2, group), y: this.pad.top + plot.height - base - height, width: group, height, color: series.color };
          base += height;
          return bar;
        });
      }
      const width = group / count;
      return this.series().map((series, slot) => {
        const value = item.values[series.key] ?? 0;
        const height = (value / this.top()) * plot.height;
        const x = center - group / 2 + slot * width;
        return { key: series.key, x: this.mirror(x, width - 2), y: this.pad.top + plot.height - height, width: Math.max(2, width - 2), height, color: series.color };
      });
    });
  });

  protected readonly labels = computed(() => {
    const longest = Math.max(1, ...this.data().map((item) => item.label.length));
    const room = Math.max(1, Math.floor(this.plot().width / Math.max(40, longest * 7 + 12)));
    const step = Math.ceil(this.data().length / room);
    return this.data().map((item, index) => ({
      text: item.label,
      x: this.mirror(this.pad.start + this.band() * index + this.band() / 2, 0),
      shown: (this.data().length - 1 - index) % step === 0,
    }));
  });

  protected readonly tip = computed(() => {
    const index = this.active();
    if (index === null) return null;
    const item = this.data()[index];
    if (!item) return null;
    const x = this.mirror(this.pad.start + this.band() * index + this.band() / 2, 0);
    return {
      x: Math.min(Math.max(x, 80), this.width() - 80),
      title: item.label,
      rows: this.series().map((series) => ({ label: series.label, color: series.color, value: this.format(item.values[series.key] ?? 0) })),
    };
  });

  protected axisX(): number {
    return this.rtl() ? this.width() - this.pad.start + 8 : this.pad.start - 8;
  }

  protected guideX(): number {
    const index = this.active();
    return index === null ? 0 : this.mirror(this.pad.start + this.band() * index, this.band());
  }

  protected format(value: number): string {
    const rounded = Math.round(value * 10) / 10;
    return `${this.language.formatNumber(rounded)}${this.unit()}`;
  }

  protected track(event: PointerEvent): void {
    const box = (event.currentTarget as SVGElement).getBoundingClientRect();
    const offset = event.clientX - box.left;
    const local = this.rtl() ? this.width() - offset : offset;
    const index = Math.floor((local - this.pad.start) / this.band());
    this.active.set(index >= 0 && index < this.data().length ? index : null);
  }

  private mirror(x: number, width: number): number {
    return this.rtl() ? this.width() - x - width : x;
  }
}
