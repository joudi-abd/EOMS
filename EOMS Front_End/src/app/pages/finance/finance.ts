import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import { Finance } from '../../data/finance';
import { Office } from '../../data/office';
import { Records } from '../../data/records';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { FinanceCash } from './finance-cash/finance-cash';
import { FinanceInvoices } from './finance-invoices/finance-invoices';
import { FinanceItems } from './finance-items/finance-items';
import { FinanceLedger } from './finance-ledger/finance-ledger';
import { FinanceReports } from './finance-reports/finance-reports';

type View = 'cash' | 'invoices' | 'items' | 'ledger' | 'reports';

const views: { id: View; label: CopyKey; icon: string }[] = [
  { id: 'cash', label: 'finTabCash', icon: 'fa-solid fa-cash-register' },
  { id: 'invoices', label: 'finTabInvoices', icon: 'fa-solid fa-file-invoice' },
  { id: 'items', label: 'finTabItems', icon: 'fa-solid fa-boxes-stacked' },
  { id: 'ledger', label: 'finTabLedger', icon: 'fa-solid fa-book' },
  { id: 'reports', label: 'finTabReports', icon: 'fa-solid fa-chart-column' },
];

/** The finance area: one heading, a row of views, and the chosen view. The view lives in `?view=` so a link opens it. */
@Component({
  selector: 'app-finance',
  imports: [RouterLink, Notice, FinanceCash, FinanceInvoices, FinanceItems, FinanceLedger, FinanceReports],
  templateUrl: './finance.html',
  styleUrl: './finance.scss',
})
export class FinancePage extends Ui {
  protected readonly chrome = inject(Chrome);
  protected readonly session = inject(Session);
  private readonly finance = inject(Finance);
  private readonly office = inject(Office);
  private readonly records = inject(Records);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);
  protected readonly views = views;

  protected readonly view = computed<View>(() => {
    const chosen = this.params()?.get('view');
    return views.some((item) => item.id === chosen) ? (chosen as View) : 'cash';
  });

  constructor() {
    super();
    if (this.session.can('finance')) void this.loadLookups();
  }

  /** Every view reads the same accounts, boxes, parties and items, so they load once here. */
  private async loadLookups(): Promise<void> {
    try {
      await Promise.all([
        this.finance.loadAccounts(),
        this.finance.loadBoxes(),
        this.finance.loadParties(),
        this.finance.loadItems(),
        this.finance.loadLimits(),
        this.records.loadCurrencies(),
      ]);
    } catch (error) {
      this.office.fail(error);
    }
  }
}
