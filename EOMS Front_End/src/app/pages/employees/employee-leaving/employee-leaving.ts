import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { LeavingReason } from '../../../data/hr';
import { Office } from '../../../data/office';

export const reasonKey: Record<LeavingReason, CopyKey> = {
  resignation: 'reasonResignation',
  termination: 'reasonTermination',
  contractEnd: 'reasonContractEnd',
  other: 'reasonOther',
};

@Component({
  selector: 'app-employee-leaving',
  imports: [RouterLink],
  templateUrl: './employee-leaving.html',
  styleUrl: './employee-leaving.scss',
})
export class EmployeeLeaving extends Ui {
  readonly person = input.required<Employee>();
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly reasons: LeavingReason[] = ['resignation', 'termination', 'contractEnd', 'other'];
  protected readonly reasonKey = reasonKey;

  protected readonly items = computed(() => this.office.custody().filter((item) => item.employeeId === this.person().id));
  protected readonly openItems = computed(() => this.items().filter((item) => !item.returnedAt).length);
  protected readonly process = computed(() => {
    const list = this.office.offboardings().filter((item) => item.employeeId === this.person().id);
    return list.find((item) => item.status === 'open') ?? list[list.length - 1];
  });
  protected readonly self = computed(() => this.session.account().id === this.person().id);

  protected actor(id: string): string {
    return id ? this.text(this.office.personName(id)) : '—';
  }

  protected addCustody(event: Event): void {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    if (this.office.addCustody(this.person().id, String(data.get('item') ?? ''), String(data.get('since') ?? ''), this.session.account().id)) {
      form.reset();
    }
  }

  protected giveBack(id: string): void {
    this.office.returnCustody(id, this.session.account().id);
  }

  protected start(event: Event): void {
    event.preventDefault();
    const data = new FormData(event.target as HTMLFormElement);
    const reason = this.reasons.find((item) => item === data.get('reason')) ?? 'other';
    this.office.startOffboarding(this.person().id, reason, String(data.get('lastDay') ?? ''), String(data.get('note') ?? ''), this.session.account().id);
  }

  protected close(): void {
    this.office.closeOffboarding(this.person().id, this.session.account().id);
  }

  protected cancel(): void {
    this.office.cancelOffboarding(this.person().id, this.session.account().id);
  }
}
