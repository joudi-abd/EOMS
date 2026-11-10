import { Component, input, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Ui } from '../../../core/ui';
import { HalfDays } from '../../../data/leaves';

@Component({
  selector: 'app-leave-periods',
  imports: [FormsModule],
  templateUrl: './leave-periods.html',
  styleUrl: './leave-periods.scss',
  host: { class: 'leave-kit' },
})
export class LeavePeriods extends Ui {
  readonly halves = input.required<HalfDays>();
  readonly busy = input(false);
  readonly save = output<void>();

  protected l(ar: string, en: string): string { return this.language.lang() === 'ar' ? ar : en; }
  protected set(key: keyof HalfDays, value: string): void { this.halves()[key] = value; }
}
