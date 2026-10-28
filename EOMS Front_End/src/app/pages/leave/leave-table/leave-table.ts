import { Component, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Leave } from '../../../data/leave';
import { Office } from '../../../data/office';

export function flowTone(status: string): string {
  if (status === 'approved') return 'ok';
  if (status === 'rejected') return 'late';
  return 'wait';
}

@Component({
  selector: 'app-leave-table',
  imports: [RouterLink],
  templateUrl: './leave-table.html',
  styleUrl: './leave-table.scss',
})
export class LeaveTable extends Ui {
  readonly rows = input.required<Leave[]>();
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);

  protected length(row: Leave): string {
    if (row.kind === 'permit') {
      return `${row.start}–${row.end}`;
    }
    const unit = row.days === 1 ? this.t('day') : this.t('days');
    const until = row.until !== row.from ? ` · ${this.short(row.until)}` : '';
    return `${this.num(row.days)} ${unit}${until}`;
  }

  protected tone(status: string): string {
    return flowTone(status);
  }

  protected approve(id: string): void {
    this.office.approveLeave(id, this.session.account().id);
  }

  protected reject(id: string): void {
    this.office.rejectLeave(id, this.session.account().id);
  }
}
