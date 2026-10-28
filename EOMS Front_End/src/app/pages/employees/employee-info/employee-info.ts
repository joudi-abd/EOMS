import { Component, computed, inject, input } from '@angular/core';
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

  protected documents(): string {
    const list = this.person().documents;
    if (list.length === 0) return '—';
    return list.map((item) => this.text(item)).join(this.language.lang() === 'ar' ? '، ' : ', ');
  }
}
