import { Component, computed, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Employee, FingerprintState } from '../../../data/employees';
import { Office } from '../../../data/office';

export const fingerKey: Record<FingerprintState, CopyKey> = {
  none: 'fingerNone',
  queued: 'fingerQueued',
  waiting: 'fingerWaiting',
  enrolled: 'fingerEnrolled',
  failed: 'fingerFailed',
};

@Component({
  selector: 'app-employee-info',
  imports: [RouterLink],
  templateUrl: './employee-info.html',
  styleUrl: './employee-info.scss',
})
export class EmployeeInfo extends Ui {
  readonly person = input.required<Employee>();
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly fingerKey = fingerKey;

  protected readonly account = computed(() => this.office.accounts().find((item) => item.id === this.person().id));
  protected readonly manager = computed(() => {
    const id = this.person().managerId;
    return id ? this.office.person(id) : undefined;
  });

  protected department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : '—';
  }

  protected readonly problem = signal('');
  protected readonly saving = signal(false);
  protected readonly offerLogin = computed(
    () => !this.person().hasAccount && this.person().status !== 'ended' && this.session.canAct('account.add'),
  );

  protected async openLogin(event: Event): Promise<void> {
    event.preventDefault();
    if (this.saving()) return;
    const data = new FormData(event.target as HTMLFormElement);
    this.saving.set(true);
    this.problem.set('');
    const outcome = await this.office.createLogin(this.person().id, String(data.get('email') ?? ''), String(data.get('password') ?? ''), this.session.account().id);
    this.saving.set(false);
    if (!outcome.ok) this.problem.set(outcome.error ?? '');
  }
}
