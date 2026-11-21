import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { DonutChart } from '../../../shared/charts/donut-chart/donut-chart';
import { runStatusKey } from '../../payroll/run-status';
import { Insights } from '../insights';

/** The latest payroll run on the home screen. */
@Component({
  selector: 'app-hr-flow',
  imports: [RouterLink, DonutChart],
  templateUrl: './hr-flow.html',
  styleUrl: './hr-flow.scss',
})
export class HrFlow extends Ui {
  protected readonly insights = inject(Insights);
  protected readonly session = inject(Session);
  protected readonly runStatusKey = runStatusKey;
}
