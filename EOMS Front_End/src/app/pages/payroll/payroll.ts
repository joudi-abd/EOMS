import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { Ui } from '../../core/ui';
import { PayrollRun } from '../../data/hr';
import { Office } from '../../data/office';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { PayrollFile } from './payroll-file/payroll-file';
import { PayrollRunView } from './payroll-run/payroll-run';
import { runStatusKey } from './run-status';

@Component({
  selector: 'app-payroll',
  imports: [Notice, RouterLink, PayrollRunView, PayrollFile],
  templateUrl: './payroll.html',
  styleUrl: './payroll.scss',
})
export class Payroll extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly chrome = inject(Chrome);
  protected readonly exists = signal(false);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly run = computed(() => this.params()?.get('run') ?? '');
  protected readonly sheet = computed(() => this.params()?.get('sheet') ?? '');
  protected readonly nextPeriod = computed(() => this.office.lastWorkday().slice(0, 7));

  protected status(run: PayrollRun): string {
    if (run.status === 'approved' && this.paid(run) === run.employeeIds.length) return this.t('statusPaid');
    return this.t(runStatusKey[run.status]);
  }

  protected tone(run: PayrollRun): string {
    if (run.status === 'approved') return this.paid(run) === run.employeeIds.length ? 'ok' : 'wait';
    return 'wait';
  }

  protected paid(run: PayrollRun): number {
    return this.office.payments().filter((item) => item.runId === run.id).length;
  }

  protected actor(id: string): string {
    return id ? this.text(this.office.personName(id)) : '—';
  }

  protected month(period: string): string {
    const [year, month] = period.split('-').map(Number);
    return this.language.formatMonth(new Date(year, month - 1, 1));
  }

  protected create(event: Event): void {
    event.preventDefault();
    const period = String(new FormData(event.target as HTMLFormElement).get('period') ?? '');
    const id = this.office.createRun(period, this.session.account().id);
    this.exists.set(!id);
    if (id) {
      void this.router.navigate([], { queryParams: { run: id }, queryParamsHandling: 'merge' });
    }
  }
}
