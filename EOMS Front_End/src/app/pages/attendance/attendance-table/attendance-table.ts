import { Component, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Ui } from '../../../core/ui';
import { DayMark } from '../../../data/attendance';
import { RollLine } from '../../../data/attendance-book';
import { Office } from '../../../data/office';
import { markKey, markTone } from '../marks';

@Component({
  selector: 'app-attendance-table',
  imports: [RouterLink],
  templateUrl: './attendance-table.html',
  styleUrl: './attendance-table.scss',
})
export class AttendanceTable extends Ui {
  readonly rows = input.required<RollLine[]>();
  private readonly office = inject(Office);

  protected department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : id || '—';
  }

  protected mark(mark: DayMark): string {
    return this.t(markKey[mark]);
  }

  protected tone(mark: DayMark): string {
    return markTone(mark);
  }
}
