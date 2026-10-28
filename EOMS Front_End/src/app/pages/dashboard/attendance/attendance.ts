import { Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { BarChart } from '../../../shared/charts/bar-chart/bar-chart';
import { ChartSeries, tone } from '../../../shared/charts/chart';
import { DonutChart } from '../../../shared/charts/donut-chart/donut-chart';
import { LineChart } from '../../../shared/charts/line-chart/line-chart';
import { RankChart } from '../../../shared/charts/rank-chart/rank-chart';
import { Insights } from '../insights';

/** Office attendance at a glance: the trend, the latest workday, and where lateness concentrates. */
@Component({
  selector: 'app-attendance-block',
  imports: [RouterLink, LineChart, DonutChart, RankChart, BarChart],
  templateUrl: './attendance.html',
  styleUrl: './attendance.scss',
})
export class AttendanceBlock extends Ui {
  protected readonly office = inject(Office);
  protected readonly insights = inject(Insights);

  protected readonly rateSeries = computed<ChartSeries[]>(() => [{ key: 'rate', label: this.t('attendanceRate'), color: tone.blue }]);
  protected readonly issueSeries = computed<ChartSeries[]>(() => [
    { key: 'late', label: this.t('lateArrival'), color: tone.wait },
    { key: 'review', label: this.t('reviewMark'), color: tone.late },
  ]);
}
