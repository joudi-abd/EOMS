import { Component, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Reporter } from '../../../../core/reports/reporter';
import { ReportTable } from '../../../../core/reports/report';
import { FinProjects, ProjectFile, ProjectStatement } from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';

/**
 * The project's statement (T9): what came in from the client and what went out by category, in the contract
 * currency. The client copy hides the internal costs and the fee; the internal copy can also hide the fee.
 * Printing builds an A4 document through the shared report builder.
 */
@Component({
  selector: 'app-fpj-project-statement',
  imports: [FormsModule],
  templateUrl: './project-statement.html',
  styleUrl: './project-statement.scss',
  host: { class: 'ledger' },
})
export class FpjProjectStatement extends FinanceBase {
  private readonly api = inject(FinProjects);
  private readonly reporter = inject(Reporter);

  readonly file = input.required<ProjectFile>();

  protected readonly statement = signal<ProjectStatement | null>(null);
  protected readonly loading = signal(true);
  protected readonly variant = signal<'internal' | 'client'>('internal');
  protected hideInternal = false;

  constructor() {
    super();
    effect(() => {
      const id = this.file().project_id;
      untracked(() => void this.load(id));
    });
  }

  protected async load(id = this.file().project_id): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => this.statement.set(await this.api.statement(id, this.variant(), this.hideInternal)));
    this.loading.set(false);
  }

  protected pick(variant: 'internal' | 'client'): void {
    if (this.variant() === variant) return;
    this.variant.set(variant);
    void this.load();
  }

  protected print(): void {
    const s = this.statement();
    if (!s) return;
    const c = s.header.currency;
    const m = (value: string | number | null | undefined) => this.money(value, c);
    const incoming: ReportTable = {
      name: this.t('fpjIncoming'),
      columns: [{ header: this.t('finDate') }, { header: this.t('finNumber') }, { header: this.t('finBox') }, { header: this.t('finDescription') }, { header: this.t('finAmount'), numeric: true }],
      rows: s.incoming.map((row) => [this.short(row.date), row.number ?? '', row.cash_box ?? '', row.description ?? '', m(row.amount)]),
    };
    const outgoing: ReportTable[] = s.outgoing.map((group) => ({
      name: `${group.name} · ${m(group.total)}`,
      columns: [{ header: this.t('finDate') }, { header: this.t('finNumber') }, { header: this.t('finParty') }, { header: this.t('finDescription') }, { header: this.t('finAmount'), numeric: true }],
      rows: group.lines.map((row) => [this.short(row.date), row.number ?? '', row.party ?? '', row.description ?? '', m(row.amount)]),
    }));
    const signatures: ReportTable = {
      name: this.t('fpjSignatures'),
      columns: s.signatures.map((name) => ({ header: name })),
      rows: [s.signatures.map(() => ' ')],
    };
    this.reporter.pdf({
      file: `statement-${s.header.statement_code ?? s.header.project_id}`,
      title: `${this.t('fpjStatementTitle')} · ${s.header.project ?? ''}`,
      subtitle: s.header.client ?? '',
      meta: [
        [this.t('finCode'), s.header.statement_code ?? ''],
        [this.t('fpjClient'), s.header.client ?? '—'],
        [this.t('fpjContractType'), this.contractTypeLabel(s.header.contract_type)],
        [this.t('finCurrency'), c],
        [this.t('fpjAsOf'), this.date(s.header.date)],
        [this.t('fpjCopy'), this.t(s.variant === 'client' ? 'fpjCopyClient' : 'fpjCopyInternal')],
      ],
      figures: this.totals(s).map(([label, value]) => ({ label, value: m(value) })),
      tables: [incoming, ...outgoing, signatures],
    });
  }

  /** The totals that this copy carries, as label and amount. */
  protected totals(s: ProjectStatement): [string, string | number][] {
    const rows: [string, string | number][] = [
      [this.t('fpjTotalIn'), s.totals.incoming],
      [this.t('fpjTotalOut'), s.totals.outgoing],
      [this.t('fpjRemaining'), s.totals.remaining],
    ];
    if (s.totals.fee !== undefined && s.totals.fee !== null) rows.push([this.t('fpjFee'), s.totals.fee]);
    if (s.totals.remaining_after_fee !== undefined && s.totals.remaining_after_fee !== null) rows.push([this.t('fpjAfterFee'), s.totals.remaining_after_fee]);
    return rows;
  }
}
