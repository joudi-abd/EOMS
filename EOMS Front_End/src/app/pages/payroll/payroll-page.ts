import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { Ui } from '../../core/ui';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { PayrollFacts } from './payroll-facts/payroll-facts';

@Component({
  selector: 'app-payroll-page',
  imports: [RouterLink, Notice, PayrollFacts],
  templateUrl: './payroll-page.html',
})
export class PayrollPage extends Ui {
  protected readonly session = inject(Session);
  protected readonly chrome = inject(Chrome);
}
