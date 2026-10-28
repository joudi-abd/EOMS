import { Component, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { HrRequest } from '../../../data/hr';
import { Office } from '../../../data/office';
import { flowTone } from '../../leave/leave-table/leave-table';
import { kindKey } from '../request-kinds';

@Component({
  selector: 'app-request-table',
  imports: [RouterLink],
  templateUrl: './request-table.html',
  styleUrl: './request-table.scss',
})
export class RequestTable extends Ui {
  readonly rows = input.required<HrRequest[]>();
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly kindKey = kindKey;

  protected tone(status: string): string {
    return flowTone(status);
  }

  protected summary(row: HrRequest): string {
    if (row.kind === 'correction') return `${this.short(row.day)} · ${row.checkIn}–${row.checkOut}`;
    if (row.kind === 'equipment') return row.item;
    return `${this.t('lastDay')} ${this.short(row.day)}`;
  }

  protected decide(id: string, approve: boolean): void {
    this.office.decideRequest(id, this.session.account().id, approve);
  }
}
