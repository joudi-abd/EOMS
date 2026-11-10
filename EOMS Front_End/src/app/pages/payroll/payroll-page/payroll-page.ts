import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Notice } from '../../../shared/notice/notice';
import { PayrollFacts } from '../payroll-facts/payroll-facts';
import { PayrollRuns } from '../payroll-runs/payroll-runs';

/** Payroll: the monthly pay runs (`/payroll/runs`) and, beside them, each employee's attendance inputs. */
@Component({
  selector: 'app-payroll-page',
  imports: [RouterLink, Notice, PayrollFacts, PayrollRuns],
  templateUrl: './payroll-page.html',
  styleUrl: './payroll-page.scss',
})
export class PayrollPage extends Ui {
  protected readonly session = inject(Session);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  /** People with attendance access but no payroll access only see the attendance inputs. */
  protected readonly view = computed<'runs' | 'facts'>(() => {
    if (!this.session.has('payroll.view')) return 'facts';
    return this.params()?.get('view') === 'facts' ? 'facts' : 'runs';
  });
}
