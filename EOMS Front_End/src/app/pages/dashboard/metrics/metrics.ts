import { Component, computed, inject } from '@angular/core';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';

interface Metric {
  label: CopyKey;
  value: string;
  sub: string;
  tone?: 'ok' | 'wait' | 'late';
}

/** Headline figures from the server records the signed-in account may see. */
@Component({
  selector: 'app-metrics',
  templateUrl: './metrics.html',
  styleUrl: './metrics.scss',
})
export class Metrics extends Ui {
  protected readonly session = inject(Session);
  protected readonly office = inject(Office);

  protected readonly cards = computed<Metric[]>(() => {
    const cards: Metric[] = [];
    if (this.session.can('employees')) {
      const list = this.office.employees();
      const away = list.filter((item) => item.status === 'away').length;
      const ended = list.filter((item) => item.status === 'ended').length;
      cards.push({ label: 'approvedHeadcount', value: this.num(this.office.headcount()), sub: `${this.num(ended)} ${this.t('ended')}` });
      cards.push({ label: 'listDepartments', value: this.num(this.office.departmentList().length), sub: `${this.num(this.office.titles().length)} ${this.t('listTitles')}` });
      cards.push({ label: 'away', value: this.num(away), sub: this.t('employeesLabel'), tone: away > 0 ? 'wait' : undefined });
    }
    if (this.session.can('access')) {
      const enabled = this.office.accounts().filter((item) => item.enabled).length;
      cards.push({ label: 'accountsActive', value: this.num(enabled), sub: `${this.num(this.office.accounts().length)} ${this.t('accountsTotal')}` });
    }
    return cards.slice(0, 4);
  });
}
