import { Component, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import { Finance } from '../../data/finance';
import { Office } from '../../data/office';
import { Records } from '../../data/records';
import { Notice } from '../../shared/notice/notice';
import { FinanceCash } from './finance-cash/finance-cash';
import { FinanceInvoices } from './finance-invoices/finance-invoices';
import { FinanceItems } from './finance-items/finance-items';
import { FinanceLedger } from './finance-ledger/finance-ledger';
import { FinanceReports } from './finance-reports/finance-reports';

type View = 'cash' | 'invoices' | 'items' | 'ledger' | 'reports';

const views: { id: View; label: CopyKey; lede: CopyKey }[] = [
  { id: 'cash', label: 'finCash', lede: 'ledeFinCash' },
  { id: 'invoices', label: 'finInvoices', lede: 'ledeFinInvoices' },
  { id: 'items', label: 'finItems', lede: 'ledeFinItems' },
  { id: 'ledger', label: 'finLedger', lede: 'ledeFinLedger' },
  { id: 'reports', label: 'finReports', lede: 'ledeFinReports' },
];

/** One finance screen. The sidebar chooses it; the route data names which view this page shows. */
@Component({
  selector: 'app-finance',
  imports: [RouterLink, Notice, FinanceCash, FinanceInvoices, FinanceItems, FinanceLedger, FinanceReports],
  templateUrl: './finance.html',
})
export class FinancePage extends Ui {
  protected readonly session = inject(Session);
  private readonly finance = inject(Finance);
  private readonly office = inject(Office);
  private readonly records = inject(Records);
  protected readonly view: View;
  protected readonly title: CopyKey;
  protected readonly lede: CopyKey;

  constructor() {
    super();
    const chosen = inject(ActivatedRoute).snapshot.data['section'] as View | undefined;
    const section = views.find((item) => item.id === chosen) ?? views[0];
    this.view = section.id;
    this.title = section.label;
    this.lede = section.lede;
    if (this.session.has('finance.view')) void this.loadLookups();
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
