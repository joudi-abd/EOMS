import { Component, computed, inject, input } from '@angular/core';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { HistoryEntry, HistoryField } from '../../../data/hr';
import { Office } from '../../../data/office';
import { Text } from '../../../data/text';

const fieldKey: Record<HistoryField, CopyKey> = {
  name: 'fieldName',
  department: 'fieldDepartment',
  title: 'fieldTitle',
  manager: 'fieldManager',
  status: 'fieldStatus',
  phone: 'fieldPhone',
  email: 'fieldEmail',
  contract: 'fieldContract',
};

const statusKey: Record<string, CopyKey> = { active: 'active', away: 'away', leaving: 'leaving', ended: 'ended' };

@Component({
  selector: 'app-employee-history',
  templateUrl: './employee-history.html',
  styleUrl: './employee-history.scss',
})
export class EmployeeHistory extends Ui {
  readonly person = input.required<Employee>();
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly fieldKey = fieldKey;

  protected readonly entries = computed(() => this.office.history().filter((item) => item.employeeId === this.person().id));
  protected readonly evaluations = computed(() => this.office.evaluations().filter((item) => item.employeeId === this.person().id));
  protected readonly mayEvaluate = computed(() => this.office.mayEvaluate(this.session.account().id, this.person().id));

  protected value(entry: HistoryEntry, side: Text): string {
    if (entry.field === 'status') return statusKey[side.ar] ? this.t(statusKey[side.ar]) : side.ar;
    return this.text(side) || '—';
  }

  protected actor(id: string): string {
    return this.text(this.office.personName(id));
  }

  protected addDocument(event: Event): void {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const name = String(new FormData(form).get('document') ?? '');
    if (this.office.addDocument(this.person().id, name, this.session.account().id)) {
      form.reset();
    }
  }

  protected addEvaluation(event: Event): void {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const note = String(new FormData(form).get('evaluation') ?? '');
    if (this.office.addEvaluation(this.person().id, note, this.session.account().id)) {
      form.reset();
    }
  }
}
