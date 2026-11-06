import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { isoDay, LeavingReason } from '../../../data/hr';
import { Office } from '../../../data/office';
import { Records } from '../../../data/records';

export const reasonKey: Record<LeavingReason, CopyKey> = {
  resignation: 'reasonResignation',
  termination: 'reasonTermination',
  contractEnd: 'reasonContractEnd',
  other: 'reasonOther',
};

/** Ends the employment on the server; the server refuses while a contract is still active. */
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
  private readonly records = inject(Records);
  protected readonly reasons: LeavingReason[] = ['resignation', 'termination', 'contractEnd', 'other'];
  protected readonly reasonKey = reasonKey;
  protected readonly today = isoDay(new Date());
  protected readonly problem = signal('');
  protected readonly saving = signal(false);

  protected readonly self = computed(() => this.session.account().id === this.person().id);
  protected readonly activeContract = computed(() => (this.records.contracts()[this.person().id] ?? []).some((item) => item.status === 'active'));

  constructor() {
    super();
    effect(() => {
      const id = this.person().id;
      untracked(() => void this.records.loadContracts(id));
    });
  }

  protected async end(event: Event): Promise<void> {
    event.preventDefault();
    if (this.saving()) return;
    const data = new FormData(event.target as HTMLFormElement);
    const reason = this.reasons.find((item) => item === data.get('reason')) ?? 'other';
    const note = String(data.get('note') ?? '').trim();
    const text = note ? `${this.t(reasonKey[reason])} — ${note}` : this.t(reasonKey[reason]);
    this.saving.set(true);
    this.problem.set('');
    const outcome = await this.office.terminate(this.person().id, String(data.get('date') ?? ''), text, this.session.account().id);
    this.saving.set(false);
    if (!outcome.ok) this.problem.set(outcome.error ?? '');
  }
}
