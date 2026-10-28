import { Component, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Employee, FingerprintState } from '../../../data/employees';
import { Office } from '../../../data/office';

const fingerShort: Record<FingerprintState, CopyKey> = {
  none: 'fingerMissing',
  queued: 'fingerPending',
  waiting: 'fingerPending',
  enrolled: 'fingerOk',
  failed: 'fingerFail',
};

@Component({
  selector: 'app-employee-table',
  imports: [RouterLink],
  templateUrl: './employee-table.html',
  styleUrl: './employee-table.scss',
})
export class EmployeeTable extends Ui {
  readonly rows = input.required<Employee[]>();
  private readonly office = inject(Office);
  protected readonly fingerKey = fingerShort;

  protected department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : '—';
  }
}
