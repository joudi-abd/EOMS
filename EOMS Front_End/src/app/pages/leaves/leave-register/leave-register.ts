import { Component, effect, inject, input, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { LeaveOptions, LeaveRecord } from '../../../data/leaves';
import { leaveClock, leaveLabel, leaveTone } from '../leave-format';

export interface LeaveFilters {
  status: string;
  employee: string;
  type: string;
  department: string;
  from: string;
  to: string;
  change: string;
}

@Component({
  selector: 'app-leave-register',
  imports: [FormsModule],
  templateUrl: './leave-register.html',
  styleUrls: ['../leave-fields.scss', './leave-register.scss'],
})
export class LeaveRegister extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  readonly settings = input.required<LeaveOptions>();
  readonly rows = input.required<LeaveRecord[]>();
  readonly filters = input.required<LeaveFilters>();
  readonly busy = input(false);
  readonly scope = input.required<'mine' | 'review' | 'all'>();
  readonly page = input(1);
  readonly lastPage = input(1);
  readonly decision = input<{row: LeaveRecord; action: string} | null>(null);
  readonly scopeChange = output<'mine' | 'review' | 'all'>();
  readonly apply = output<void>();
  readonly turn = output<number>();
  readonly edit = output<LeaveRecord>();
  readonly choose = output<{row: LeaveRecord; action: string}>();
  readonly confirm = output<{reason: string; from: string}>();
  readonly dismiss = output<void>();
  protected reason = '';
  protected from = '';

  constructor() {
    super();
    effect(() => {
      this.decision();
      this.reason = '';
      this.from = '';
    });
  }

  protected l(ar: string, en: string): string { return this.language.lang() === 'ar' ? ar : en; }
  protected days(units: number): string { return this.num(units / 1_000_000); }
  protected clock(value: string | null | undefined): string { return leaveClock(value); }
  protected tone(status: string): string { return leaveTone(status); }
  protected label(status: string): string { return leaveLabel(this.language.lang() === 'ar', status); }
  protected owns(row: LeaveRecord): boolean { return row.is_owner || !!row.can_act_for_owner; }
  protected set(key: keyof LeaveFilters, value: string): void { this.filters()[key] = value; }
  protected readonly statuses = ['pending', 'approved', 'rejected', 'withdrawn', 'cancelled', 'expired'];
}
