import { Component, inject } from '@angular/core';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { DonutChart } from '../../../shared/charts/donut-chart/donut-chart';
import { Insights } from '../insights';

@Component({
  selector: 'app-purchase-table',
  imports: [DonutChart],
  templateUrl: './purchase-table.html',
  styleUrl: './purchase-table.scss',
})
export class PurchaseTable extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly insights = inject(Insights);

  protected add(event: Event): void {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const item = String(data.get('item') ?? '').trim();
    const supplier = String(data.get('supplier') ?? '').trim();
    if (!item || !supplier) {
      return;
    }
    this.office.addPurchase({ ar: item, en: item }, { ar: supplier, en: supplier }, this.session.account().id);
    form.reset();
  }

  protected decide(id: string, approve: boolean): void {
    const actor = this.session.account().id;
    if (approve) this.office.approvePurchase(id, actor);
    else this.office.rejectPurchase(id, actor);
  }
}
