import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Ui } from '../../../core/ui';
import { DonutChart } from '../../../shared/charts/donut-chart/donut-chart';
import { RankChart } from '../../../shared/charts/rank-chart/rank-chart';
import { Insights } from '../insights';

/** Accounts by role and by state. */
@Component({
  selector: 'app-accounts-mix',
  imports: [RouterLink, RankChart, DonutChart],
  templateUrl: './accounts-mix.html',
  styleUrl: './accounts-mix.scss',
})
export class AccountsMix extends Ui {
  protected readonly insights = inject(Insights);
}
