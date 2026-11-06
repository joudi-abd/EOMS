import { Component, computed, inject, signal } from '@angular/core';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { minutesOf } from '../../../data/attendance';
import { weekOrder } from '../../../data/hr';
import { Office, PolicyRules } from '../../../data/office';

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

  /** Versions newest first, each with the state it has today. */
  protected readonly versions = computed(() => {
    const list = this.office.policyVersions();
    const today = this.office.nextPolicyDay();
    const current = list.find((item) => item.effective_from < today);
    return list.map((item) => ({
      ...item,
      state: item === current ? 'policyCurrent' : item.effective_from >= today ? 'policyUpcoming' : 'policyEnded',
    }) as typeof item & { state: CopyKey });
  });

  /** The version in force today; the form starts from its rules. */
  protected readonly current = computed(() => {
    const list = this.office.policyVersions();
    const today = this.office.nextPolicyDay();
    return list.find((item) => item.effective_from < today) ?? list[list.length - 1] ?? null;
  });

  protected graceName(mode: string): CopyKey {
    return mode === 'excess_only' ? 'graceExcess' : mode === 'completed_blocks' ? 'graceBlocks' : 'graceThreshold';
  }

  protected pairName(mode: string): CopyKey {
    return mode === 'directional' ? 'pairDirectional' : 'pairFirstLast';
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
      String(data.get('effective') ?? ''),
      this.session.account().id,
      {
        graceMode: String(data.get('graceMode')) as PolicyRules['graceMode'],
        lateBlock: Number(data.get('lateBlock')),
        overtime: Number(data.get('overtime')),
        pairing: String(data.get('pairing')) as PolicyRules['pairing'],
        duplicateSeconds: Number(data.get('duplicate')),
        finalizeAfter: Number(data.get('finalize')),
      },
    );
    this.saving.set(false);
    this.problem.set(outcome.ok ? '' : (outcome.error ?? ''));
  }
}
