import { Component, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { PayRecord } from '../../../data/hr';
import { Office } from '../../../data/office';

export interface RunLine {
  employee: Employee;
  present: number;
  late: number;
  review: number;
  overtime: number;
  payment: PayRecord | undefined;
}

@Component({
  selector: 'app-payroll-table',
  imports: [RouterLink],
  templateUrl: './payroll-table.html',
  styleUrl: './payroll-table.scss',
})
export class PayrollTable extends Ui {
  readonly lines = input.required<RunLine[]>();
  private readonly office = inject(Office);

  protected department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : id;
  }
}
