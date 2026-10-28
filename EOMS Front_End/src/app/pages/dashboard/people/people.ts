import { Component, inject } from '@angular/core';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { DonutChart } from '../../../shared/charts/donut-chart/donut-chart';
import { RankChart } from '../../../shared/charts/rank-chart/rank-chart';
import { Insights } from '../insights';

/** Headcount by department and the leave picture, each shown only to the roles that may see it. */
@Component({
  selector: 'app-people',
  imports: [RankChart, DonutChart],
  templateUrl: './people.html',
  styleUrl: './people.scss',
})
export class People extends Ui {
  protected readonly insights = inject(Insights);
  protected readonly session = inject(Session);
}
