import { Component, computed, ElementRef, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Reporter, ReportDraft } from '../../../core/reports/reporter';
import { Ui } from '../../../core/ui';
import { ChartSlice, tone } from '../../../shared/charts/chart';
import { DonutChart } from '../../../shared/charts/donut-chart/donut-chart';
import { daysBetween, monthBounds, PayMethod, RunStatus } from '../../../data/hr';
import { Office } from '../../../data/office';
import { PayrollTable, RunLine } from '../payroll-table/payroll-table';
import { runStatusKey } from '../run-status';

@Component({
  selector: 'app-payroll-run',
  imports: [PayrollTable, DonutChart],
  templateUrl: './payroll-run.html',
  styleUrl: './payroll-run.scss',
})
export class PayrollRunView extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly method = signal<PayMethod>('cash');
  protected readonly refused = signal(false);
  protected readonly exporting = signal(false);
  private readonly reporter = inject(Reporter);
  private readonly host = inject(ElementRef<HTMLElement>);
  protected readonly stages: RunStatus[] = ['draft', 'review', 'approval', 'approved'];
  protected readonly runStatusKey = runStatusKey;
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly run = computed(() => this.office.runs().find((item) => item.id === this.params()?.get('run')));
  protected readonly step = computed(() => {
    const run = this.run();
    return run ? this.office.runStep(run, this.session.account().id) : null;
  });
  protected readonly mayReturn = computed(() => {
    const run = this.run();
    return run ? this.office.canReturnRun(run, this.session.account().id) : false;
  });

  protected readonly lines = computed<RunLine[]>(() => {
    const run = this.run();
    if (!run) return [];
    const { first, last } = monthBounds(run.period);
    const end = last < this.office.lastWorkday() ? last : this.office.lastWorkday();
    const days = daysBetween(first, end);
    return run.employeeIds.flatMap((id) => {
      const employee = this.office.person(id);
      if (!employee) return [];
      let present = 0;
      let late = 0;
      let review = 0;
      let overtime = 0;
      for (const day of days) {
        const row = this.office.day(day).rows.find((item) => item.employeeId === id);
        if (!row) continue;
        if (row.mark === 'present' || row.mark === 'late' || row.mark === 'early' || row.mark === 'incomplete') present += 1;
        if (row.mark === 'late') late += 1;
        if (row.mark === 'review' || row.mark === 'incomplete') review += 1;
        overtime += row.overtime;
      }
      const payment = this.office.payments().find((item) => item.runId === run.id && item.employeeId === id);
      return [{ employee, present, late, review, overtime, payment }];
    });
  });

  protected readonly unpaid = computed(() => this.lines().filter((line) => !line.payment));

  protected actor(id: string): string {
    return id ? this.text(this.office.personName(id)) : '—';
  }

  protected month(period: string): string {
    const [year, month] = period.split('-').map(Number);
    return this.language.formatMonth(new Date(year, month - 1, 1));
  }

  protected reached(stage: RunStatus): boolean {
    const run = this.run();
    return run ? this.stages.indexOf(run.status) >= this.stages.indexOf(stage) : false;
  }

  protected stepLabel(): string {
    const step = this.step();
    if (step === 'submit') return this.t('submitRun');
    if (step === 'review') return this.t('reviewRun');
    return this.t('approveRun');
  }

  protected advance(): void {
    const run = this.run();
    if (run) this.office.advanceRun(run.id, this.session.account().id);
  }

  protected sendBack(event: Event): void {
    event.preventDefault();
    const run = this.run();
    const note = String(new FormData(event.target as HTMLFormElement).get('note') ?? '');
    if (run) this.office.returnRun(run.id, this.session.account().id, note);
  }

  protected onMethod(event: Event): void {
    this.method.set((event.target as HTMLSelectElement).value === 'wallet' ? 'wallet' : 'cash');
  }

  protected pay(event: Event): void {
    event.preventDefault();
    const run = this.run();
    if (!run) return;
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const saved = this.office.recordPayment(
      run.id,
      String(data.get('employee') ?? ''),
      this.method(),
      String(data.get('reference') ?? ''),
      String(data.get('paidOn') ?? ''),
      this.session.account().id,
    );
    this.refused.set(!saved);
    if (saved) form.reset();
  }

  protected back(): void {
    void this.router.navigate([], { queryParams: { run: null }, queryParamsHandling: 'merge' });
  }

  protected readonly paidMix = computed<ChartSlice[]>(() => {
    const lines = this.lines();
    return [
      { label: this.t('payCash'), value: lines.filter((line) => line.payment?.method === 'cash').length, color: tone.blue },
      { label: this.t('payWallet'), value: lines.filter((line) => line.payment?.method === 'wallet').length, color: tone.teal },
      { label: this.t('notPaid'), value: lines.filter((line) => !line.payment).length, color: tone.quiet },
    ].filter((slice) => slice.value > 0);
  });

  private draft(): ReportDraft | null {
    const run = this.run();
    if (!run) return null;
    const paid = this.lines().filter((line) => line.payment).length;
    return {
      file: `payroll-${run.period}`,
      title: `${this.t('payrollReportTitle')} · ${this.month(run.period)}`,
      subtitle: `${this.t('amountsUnset')} ${this.t('noDeduction')}`,
      meta: [
        [this.t('state'), this.t(runStatusKey[run.status])],
        [this.t('preparedByLabel'), this.actor(run.preparedBy)],
        [this.t('reviewedByLabel'), this.actor(run.reviewedBy)],
        [this.t('approvedByLabel'), this.actor(run.approvedBy)],
      ],
      figures: [
        { label: this.t('runEmployees'), value: this.num(run.employeeIds.length) },
        { label: this.t('paidCount'), value: this.num(paid) },
        { label: this.t('notPaid'), value: this.num(run.employeeIds.length - paid) },
      ],
      tables: [
        {
          name: this.t('runEmployees'),
          columns: [
            { header: this.t('staffNo') },
            { header: this.t('employeeCol') },
            { header: this.t('presentDays'), numeric: true },
            { header: this.t('lateDays'), numeric: true },
            { header: this.t('reviewDays'), numeric: true },
            { header: this.t('overtimeHours'), numeric: true },
            { header: this.t('payMethod') },
            { header: this.t('payReference') },
            { header: this.t('paidOn'), numeric: true },
          ],
          rows: this.lines().map((line) => [
            line.employee.staffNo,
            this.text(line.employee.name),
            line.present,
            line.late,
            line.review,
            this.hours(line.overtime),
            line.payment ? (line.payment.method === 'cash' ? this.t('payCash') : this.t('payWallet')) : this.t('notPaid'),
            line.payment?.reference ?? '',
            line.payment?.paidOn ?? '',
          ]),
        },
      ],
      landscape: true,
    };
  }

  protected async exportExcel(): Promise<void> {
    const draft = this.draft();
    if (!draft) return;
    this.exporting.set(true);
    try {
      await this.reporter.excel(draft);
    } finally {
      this.exporting.set(false);
    }
  }

  protected exportPdf(): void {
    const draft = this.draft();
    if (draft) this.reporter.pdf(draft, this.host.nativeElement);
  }
}
