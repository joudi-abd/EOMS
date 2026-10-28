import { Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { BarChart } from '../../../shared/charts/bar-chart/bar-chart';
import { ChartSeries, tone } from '../../../shared/charts/chart';
import { DonutChart } from '../../../shared/charts/donut-chart/donut-chart';
import { runStatusKey } from '../../payroll/run-status';
import { Insights } from '../insights';

/** Requests in flight and the latest payroll run. */
@Component({
  selector: 'app-hr-flow',
  imports: [RouterLink, BarChart, DonutChart],
  templateUrl: './hr-flow.html',
  styleUrl: './hr-flow.scss',
})
export class HrFlow extends Ui {
  protected readonly insights = inject(Insights);
  protected readonly session = inject(Session);
  protected readonly runStatusKey = runStatusKey;

  protected readonly series = computed<ChartSeries[]>(() => [
    { key: 'waiting', label: this.t('waitingSeries'), color: tone.wait },
    { key: 'approved', label: this.t('approved'), color: tone.ok },
    { key: 'rejected', label: this.t('rejected'), color: tone.late },
  ]);
}
