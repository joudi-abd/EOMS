import { Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { BarChart } from '../../../shared/charts/bar-chart/bar-chart';
import { ChartSeries, tone } from '../../../shared/charts/chart';
import { DonutChart } from '../../../shared/charts/donut-chart/donut-chart';
import { Insights } from '../insights';

/** The signed-in person's own attendance this month, for accounts that do not see the office. */
@Component({
  selector: 'app-my-month',
  imports: [RouterLink, DonutChart, BarChart],
  templateUrl: './my-month.html',
  styleUrl: './my-month.scss',
})
export class MyMonth extends Ui {
  protected readonly insights = inject(Insights);
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);

  protected readonly series = computed<ChartSeries[]>(() => [{ key: 'hours', label: this.t('workedHours'), color: tone.blue }]);
  protected readonly shift = computed(() => {
    const [startH, startM] = this.office.policy().start.split(':').map(Number);
    const [endH, endM] = this.office.policy().end.split(':').map(Number);
    return (endH * 60 + endM - startH * 60 - startM) / 60;
  });
}
