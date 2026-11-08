import { Component, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CopyKey } from '../../../core/copy';
import { FinReport, ReportAccountRow, ReportKind, today } from '../../../data/finance';
import { accountTypeKey } from '../finance-ledger/finance-ledger';
import { FinanceTab } from '../finance-tab';

const reports: { id: ReportKind; label: CopyKey }[] = [
  { id: 'trial-balance', label: 'finReportTrial' },
  { id: 'income-statement', label: 'finReportIncome' },
  { id: 'balance-sheet', label: 'finReportBalance' },
  { id: 'ledger', label: 'finReportLedger' },
  { id: 'party', label: 'finReportParty' },
  { id: 'box', label: 'finReportBox' },
];

/** The six finance reports. Each runs in one currency: the server never converts or adds currencies together. */
@Component({
  selector: 'app-finance-reports',
  imports: [FormsModule],
  templateUrl: './finance-reports.html',
  styleUrl: '../finance-shared.scss',
})
export class FinanceReports extends FinanceTab {
  protected readonly reports = reports;
  protected readonly kind = signal<ReportKind>('trial-balance');
  protected readonly result = signal<FinReport | null>(null);
  protected readonly shown = signal<ReportKind | null>(null);
  protected readonly loading = signal(false);

  protected currency = 'SYP';
  protected from = `${today().slice(0, 4)}-01-01`;
  protected to = today();
  protected accountId = 0;
  protected partyId = 0;
  protected boxId = 0;

  protected pick(kind: ReportKind): void {
    this.kind.set(kind);
    this.result.set(null);
    this.shown.set(null);
  }

  protected label(kind: ReportKind): string {
    return this.t(reports.find((item) => item.id === kind)?.label ?? 'finReportTrial');
  }

  protected typeLabel(row: ReportAccountRow): string {
    return this.t(accountTypeKey[row.type]);
  }

  /** What the chosen report still needs before it can run. */
  protected ready(): boolean {
    const kind = this.kind();
    if (!this.to) return false;
    if (kind !== 'balance-sheet' && !this.from) return false;
    if (kind === 'ledger') return !!this.accountId;
    if (kind === 'party') return !!this.partyId;
    if (kind === 'box') return !!this.boxId;
    return true;
  }

  protected async run(): Promise<void> {
    if (!this.ready()) return;
    const kind = this.kind();
    const params: Record<string, string | number> = { to: this.to };
    if (kind !== 'box') params['currency'] = this.currency;
    if (kind !== 'balance-sheet') params['from'] = this.from;
    if (kind === 'ledger') params['account_id'] = this.accountId;
    if (kind === 'party') params['party_id'] = this.partyId;
    if (kind === 'box') params['box_id'] = this.boxId;
    this.loading.set(true);
    await this.quiet(async () => {
      this.result.set(await this.finance.report(kind, params));
      this.shown.set(kind);
    });
    this.loading.set(false);
  }

  protected print(): void {
    globalThis.print();
  }

  protected statement(): boolean {
    const kind = this.shown();
    return kind === 'ledger' || kind === 'party' || kind === 'box';
  }

  protected reportCurrency(report: FinReport): string {
    if (report.currency) return report.currency;
    return this.finance.boxes().find((box) => box.id === this.boxId)?.currency_code ?? '';
  }

  protected balancedTrial(report: FinReport): boolean {
    return Math.abs((report.debit ?? 0) - (report.credit ?? 0)) < 0.005;
  }

  protected balancedSheet(report: FinReport): boolean {
    return Math.abs((report.total_assets ?? 0) - (report.total_liabilities_and_equity ?? 0)) < 0.005;
  }
}
