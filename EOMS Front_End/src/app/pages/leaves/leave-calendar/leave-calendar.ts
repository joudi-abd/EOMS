import { Component, inject, input, model, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { LeaveCalendar as LeaveBoard } from '../../../data/leaves';
import { leaveClock, leaveLabel, leaveShift, leaveTone } from '../leave-format';

@Component({
  selector: 'app-leave-calendar',
  imports: [FormsModule],
  templateUrl: './leave-calendar.html',
  styleUrl: '../leave-fields.scss',
})
export class LeaveCalendar extends Ui {
  protected readonly office = inject(Office);
  readonly from = model('');
  readonly to = model('');
  readonly department = model('');
  readonly includePending = model(false);
  readonly board = input<LeaveBoard | null>(null);
  readonly busy = input(false);
  readonly show = output<void>();

  protected l(ar: string, en: string): string { return this.language.lang() === 'ar' ? ar : en; }
  protected clock(value: string | null | undefined): string { return leaveClock(value); }
  protected tone(status: string): string { return leaveTone(status); }
  protected label(status: string): string { return leaveLabel(this.language.lang() === 'ar', status); }
  protected latest(): string { return leaveShift(this.from(), 61); }
}
