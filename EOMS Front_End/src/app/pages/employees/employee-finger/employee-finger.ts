import { Component, computed, inject, input } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { Office } from '../../../data/office';
import { fingerKey } from '../employee-info/employee-info';
import { FingerDialog } from '../finger-dialog/finger-dialog';

/** The device user number (`biometric_code`) as the server holds it, and the way into enrollment. */
@Component({
  selector: 'app-employee-finger',
  imports: [FingerDialog, RouterLink],
  templateUrl: './employee-finger.html',
  styleUrl: './employee-finger.scss',
})
export class EmployeeFinger extends Ui {
  readonly person = input.required<Employee>();
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly fingerKey = fingerKey;
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly pin = computed(() => this.office.pinOf(this.person().id));
  /** The dialog lives in the address, so a new hire lands straight in it. */
  protected readonly enrolling = computed(
    () => this.params()?.get('enroll') === '1' && this.mayEnroll(),
  );
  protected readonly mayEnroll = computed(
    () =>
      this.person().status !== 'ended' &&
      this.session.canAct('attendance.link') &&
      this.session.mayManage(this.person().id),
  );

  protected openEnroll(): void {
    void this.router.navigate([], { queryParams: { enroll: 1 }, queryParamsHandling: 'merge' });
  }

  protected closeEnroll(): void {
    void this.router.navigate([], { queryParams: { enroll: null }, queryParamsHandling: 'merge' });
  }
}
