import { Component, input } from '@angular/core';
import { CustodyKind } from '../../../../data/finance-advances';
import { MyCustody } from '../../../../data/finance-site';
import { FinanceBase } from '../../finance-base';
import { custodyKindKey } from '../../advances/custody-detail/custody-detail';

/** "عهدتي": what the supervisor holds per currency, each open custody, and the last movements. */
@Component({
  selector: 'app-fin-site-custody',
  templateUrl: './site-custody.html',
  styleUrl: './site-custody.scss',
  host: { class: 'ledger' },
})
export class SiteCustody extends FinanceBase {
  readonly myCustody = input<MyCustody | null>(null);

  constructor() {
    super();
    void this.quiet(() => this.finance.loadCurrencies());
  }

  protected kind(kind: CustodyKind): string {
    return this.t(custodyKindKey[kind] ?? 'vtCustodyIssue');
  }

  protected openOnes(data: MyCustody): MyCustody['custodies'] {
    return data.custodies.filter((item) => item.status === 'open');
  }
}
