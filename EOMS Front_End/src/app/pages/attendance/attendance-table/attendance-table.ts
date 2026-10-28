import { Component, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Ui } from '../../../core/ui';
import { DayMark, DayRow } from '../../../data/attendance';
import { Employee } from '../../../data/employees';
import { Office, projects } from '../../../data/office';
import { markKey, markTone } from '../marks';

@Component({
  selector: 'app-attendance-table',
  imports: [RouterLink],
  templateUrl: './attendance-table.html',
  styleUrl: './attendance-table.scss',
})
export class AttendanceTable extends Ui {
  readonly rows = input.required<Array<DayRow & { employee: Employee }>>();
  private readonly office = inject(Office);

  protected department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : id;
  }

  protected mark(mark: DayMark): string {
    return this.t(markKey[mark]);
  }

  protected tone(mark: DayMark): string {
    return markTone(mark);
  }

  protected project(id: string): string {
    const match = projects.find((item) => item.id === id);
    return match ? this.text(match.name) : '';
  }
}
