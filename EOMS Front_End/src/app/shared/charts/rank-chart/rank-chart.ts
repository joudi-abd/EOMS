import { Component, computed, inject, input } from '@angular/core';
import { Language } from '../../../core/language';
import { ChartRow, tone } from '../chart';

/** Horizontal bars for ranking named items; long Arabic names stay readable. */
@Component({
  selector: 'app-rank-chart',
  templateUrl: './rank-chart.html',
  styleUrl: './rank-chart.scss',
})
export class RankChart {
  readonly rows = input.required<ChartRow[]>();
  readonly label = input.required<string>();
  readonly color = input(tone.blue);
  readonly unit = input('');
  readonly max = input<number | null>(null);
  readonly limit = input(8);
  protected readonly language = inject(Language);

  protected readonly top = computed(() => this.max() ?? Math.max(1, ...this.rows().map((row) => row.value)));
  protected readonly shown = computed(() => this.rows().slice(0, this.limit()));

  protected share(value: number): number {
    return Math.max(0, Math.min(100, (value / this.top()) * 100));
  }

  protected format(value: number): string {
    return `${this.language.formatNumber(Math.round(value * 10) / 10)}${this.unit()}`;
  }
}
