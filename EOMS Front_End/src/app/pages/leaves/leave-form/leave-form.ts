import { Component, inject, input, model, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { LeaveInput, LeaveOptions, LeaveQuote, LeaveRecord } from '../../../data/leaves';
import { leaveClock } from '../leave-format';

@Component({
  selector: 'app-leave-form',
  imports: [FormsModule],
  templateUrl: './leave-form.html',
  styleUrl: './leave-form.scss',
  host: { class: 'leave-kit' },
})
export class LeaveForm extends Ui {
  protected readonly office = inject(Office);
  readonly settings = input.required<LeaveOptions>();
  readonly form = input.required<LeaveInput>();
  readonly editing = input<LeaveRecord>();
  readonly quote = input<LeaveQuote | null>(null);
  readonly busy = input(false);
  readonly forEmployee = input('');
  readonly consent = model(false);
  readonly approveNow = model(false);
  readonly employeeChange = output<string>();
  readonly preview = output<void>();
  readonly confirm = output<void>();
  readonly clear = output<void>();
  readonly invalidate = output<void>();

  protected l(ar: string, en: string): string { return this.language.lang() === 'ar' ? ar : en; }
  protected days(units: number): string { return this.num(units / 1_000_000); }
  protected clock(value: string | null | undefined): string { return leaveClock(value); }
  protected set<K extends keyof LeaveInput>(key: K, value: LeaveInput[K]): void {
    this.form()[key] = value;
    this.invalidate.emit();
  }
}
