import { Component, computed, inject, input, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Session } from '../../../core/access/session';
import { loginMail, loginUserRejected } from '../../../core/login-mail';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { Office } from '../../../data/office';

@Component({
  selector: 'app-employee-form',
  templateUrl: './employee-form.html',
  styleUrl: './employee-form.scss',
})
export class EmployeeForm extends Ui {
  readonly person = input<Employee | undefined>(undefined);
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly problem = signal('');
  protected readonly mailNote = signal('');
  protected readonly saving = signal(false);
  private readonly router = inject(Router);

  protected readonly today = new Date().toISOString().slice(0, 10);
  protected readonly managers = computed(() =>
    this.office.employees().filter((item) => item.status !== 'ended' && item.id !== this.person()?.id),
  );
  /** Contact fields show only when creating, or when the server sent them for this file. */
  protected readonly showSensitive = computed(() => !this.person() || this.person()!.sensitive);
  protected readonly showWallet = computed(() => !this.person() || this.person()!.financial);
  /** Only an account that manages users may open the login together with the file. */
  protected readonly offerLogin = computed(() => !this.person() && this.session.canAct('account.add'));
  protected readonly offerEnroll = computed(() => !this.person() && this.session.canAct('attendance.link'));

  protected onMail(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.mailNote.set(loginUserRejected(value) ? this.t('mailUserInvalid') : '');
  }

  protected cancel(): void {
    const editing = this.person();
    void this.router.navigate([], { queryParams: editing ? { edit: null } : { file: null }, queryParamsHandling: 'merge' });
  }

  protected async save(event: Event): Promise<void> {
    event.preventDefault();
    if (this.saving()) return;
    const data = new FormData(event.target as HTMLFormElement);
    const read = (name: string) => String(data.get(name) ?? '').trim();
    const actor = this.session.account().id;
    const editing = this.person();
    const shared = {
      firstName: read('firstName'),
      fatherName: read('fatherName'),
      lastName: read('lastName'),
      birthDate: data.has('birthDate') ? read('birthDate') : (editing?.birthDate ?? ''),
      phone: data.has('phone') ? read('phone') : (editing?.phone ?? ''),
      email: data.has('email') ? read('email') : (editing?.email ?? ''),
      address: data.has('address') ? read('address') : (editing?.address ?? ''),
      titleId: read('titleId'),
      department: read('department'),
      managerId: read('manager'),
      wallet: data.has('wallet') ? read('wallet') : (editing?.wallet ?? ''),
    };
    const typedAccount = read('accountEmail');
    if (!editing && loginUserRejected(typedAccount)) {
      this.mailNote.set(this.t('mailUserInvalid'));
      return;
    }
    const accountEmail = loginMail(typedAccount);
    if (!editing && accountEmail && String(data.get('accountPassword') ?? '').length < 10) {
      this.problem.set(this.t('passwordHint'));
      return;
    }
    this.saving.set(true);
    this.problem.set('');
    const outcome = editing
      ? await this.office.updateEmployee(editing.id, { ...shared, effectiveDate: read('effectiveDate'), reason: read('reason') }, actor)
      : await this.office.hire({ ...shared, nationalId: read('nationalId'), hired: read('hired') }, actor);
    if (outcome.ok && !editing && outcome.id && accountEmail) {
      await this.office.createLogin(outcome.id, accountEmail, String(data.get('accountPassword') ?? ''), actor);
    }
    this.saving.set(false);
    if (!outcome.ok) {
      this.problem.set(outcome.error ?? '');
      return;
    }
    const enroll = !editing && data.get('enroll') === 'on';
    const queryParams = editing ? { edit: null } : { file: outcome.id ?? null, tab: enroll ? 'finger' : null, enroll: enroll ? 1 : null };
    void this.router.navigate([], { queryParams, queryParamsHandling: 'merge' });
  }
}
