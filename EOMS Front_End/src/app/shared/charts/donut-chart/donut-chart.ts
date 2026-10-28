import { Component, computed, inject, input, signal } from '@angular/core';
import { Language } from '../../../core/language';
import { ChartSlice } from '../chart';

/** A composition of one whole, with the total in the middle and every share in the legend. */
@Component({
  selector: 'app-donut-chart',
  templateUrl: './donut-chart.html',
  styleUrl: './donut-chart.scss',
})
export class DonutChart {
  readonly slices = input.required<ChartSlice[]>();
  readonly label = input.required<string>();
  readonly center = input('');
  readonly size = input(200);
  protected readonly language = inject(Language);
  protected readonly active = signal<number | null>(null);

  protected readonly radius = computed(() => this.size() / 2 - 14);
  protected readonly circumference = computed(() => 2 * Math.PI * this.radius());
  protected readonly total = computed(() => this.slices().reduce((sum, item) => sum + Math.max(0, item.value), 0));

  protected readonly arcs = computed(() => {
    const total = this.total();
    const length = this.circumference();
    let offset = 0;
    return this.slices().map((slice, index) => {
      const share = total > 0 ? Math.max(0, slice.value) / total : 0;
      const dash = Math.max(0, share * length - (share > 0 && share < 1 ? 2 : 0));
      const arc = { index, color: slice.color, dash, gap: length - dash, offset: -offset, share };
      offset += share * length;
      return arc;
    });
  });

  protected readonly focus = computed(() => {
    const index = this.active();
    return index === null ? null : this.slices()[index] ?? null;
  });

  protected format(value: number): string {
    return this.language.formatNumber(Math.round(value * 10) / 10);
  }

  protected percent(value: number): string {
    const total = this.total();
    return this.language.formatPercent(total > 0 ? (value / total) * 100 : 0);
  }
}
