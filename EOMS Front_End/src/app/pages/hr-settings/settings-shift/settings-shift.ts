import { Component, computed, inject, signal } from '@angular/core';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { minutesOf } from '../../../data/attendance';
import { weekOrder } from '../../../data/hr';
import { Office } from '../../../data/office';

@Component({
  selector: 'app-settings-shift',
  templateUrl: './settings-shift.html',
  styleUrl: './settings-shift.scss',
})
export class SettingsShift extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly days = weekOrder;

  protected readonly length = computed(() => {
    const policy = this.office.policy();
    return Math.max(0, minutesOf(policy.end) - minutesOf(policy.start));
  });

  protected dayName(day: number): string {
    return this.t(`weekday${day}` as CopyKey);
  }

  protected readonly problem = signal('');
  protected readonly saving = signal(false);

  protected async save(event: Event): Promise<void> {
    event.preventDefault();
    if (this.saving()) return;
    const data = new FormData(event.target as HTMLFormElement);
    this.saving.set(true);
    const outcome = await this.office.setPolicy(
      {
        start: String(data.get('start') ?? ''),
        end: String(data.get('end') ?? ''),
        grace: Number(data.get('grace')),
        workdays: data.getAll('workday').map(Number),
      },
      this.session.account().id,
    );
    this.saving.set(false);
    this.problem.set(outcome.ok ? '' : (outcome.error ?? ''));
  }
}
