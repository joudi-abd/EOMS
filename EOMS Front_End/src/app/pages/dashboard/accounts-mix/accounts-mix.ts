import { Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuditKind } from '../../../core/access/accounts';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { DonutChart } from '../../../shared/charts/donut-chart/donut-chart';
import { RankChart } from '../../../shared/charts/rank-chart/rank-chart';
import { Insights } from '../insights';

const auditKey: Record<AuditKind, CopyKey> = {
  added: 'auditAdded',
  enabled: 'auditEnabled',
  disabled: 'auditDisabled',
  updated: 'auditUpdated',
};

/** Accounts by role and state, with the latest account activity. */
@Component({
  selector: 'app-accounts-mix',
  imports: [RouterLink, RankChart, DonutChart],
  templateUrl: './accounts-mix.html',
  styleUrl: './accounts-mix.scss',
})
export class AccountsMix extends Ui {
  protected readonly insights = inject(Insights);
  protected readonly office = inject(Office);
  protected readonly recent = computed(() => this.office.audit().slice(0, 5));

  protected line(kind: AuditKind): string {
    return this.t(auditKey[kind]);
  }

  protected actor(id: string): string {
    return this.text(this.office.personName(id));
  }
}
