import { afterNextRender, Component, computed, ElementRef, inject, input, OnDestroy, output, viewChild } from '@angular/core';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { device } from '../../../data/attendance';
import { Employee } from '../../../data/employees';
import { EnrollState, Enrollment } from '../../../data/enrollment';

type StepState = 'todo' | 'current' | 'done' | 'failed';

const stepKeys: CopyKey[] = ['enrollStepQueue', 'enrollStepDevice', 'enrollStepFinger', 'enrollStepSave'];

/** Which stage each server state has reached; a failure is shown on the finger stage. */
const reached: Record<EnrollState, number> = { none: -1, queued: 0, sent: 1, scanning: 2, enrolled: 4, failed: 2 };

const ring = 2 * Math.PI * 54;
const gap = 10;

@Component({
  selector: 'app-finger-dialog',
  templateUrl: './finger-dialog.html',
  styleUrl: './finger-dialog.scss',
})
export class FingerDialog extends Ui implements OnDestroy {
  readonly person = input.required<Employee>();
  readonly closed = output<void>();
  protected readonly enrollment = inject(Enrollment);
  protected readonly device = device;
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  protected readonly state = computed(() => this.enrollment.status().state);
  protected readonly presses = computed(() => (this.state() === 'enrolled' ? 3 : this.enrollment.status().presses));
  protected readonly tone = computed(() => {
    if (this.enrollment.available() === false) return 'is-off';
    const state = this.state();
    if (state === 'enrolled') return 'is-done';
    if (state === 'failed') return 'is-failed';
    if (state === 'none') return 'is-idle';
    return 'is-live';
  });

  protected readonly steps = computed(() => {
    const state = this.state();
    const at = reached[state];
    return stepKeys.map((key, index) => {
      let mark: StepState = 'todo';
      if (index < at) mark = 'done';
      else if (index === at) mark = state === 'failed' ? 'failed' : 'current';
      return { key, mark };
    });
  });

  /** Three arcs around the print, one per press. */
  protected readonly arcs = computed(() => {
    const length = ring / 3 - gap;
    return [0, 1, 2].map((index) => ({
      dash: `${length} ${ring - length}`,
      offset: -(index * (ring / 3)) - gap / 2,
      filled: index < this.presses(),
    }));
  });

  protected readonly headline = computed<CopyKey>(() => {
    if (this.enrollment.available() === null) return 'enrollChecking';
    if (this.enrollment.available() === false) return 'enrollUnavailable';
    const keys: Record<EnrollState, CopyKey> = {
      none: 'enrollIdle',
      queued: 'enrollQueued',
      sent: 'enrollSent',
      scanning: 'enrollScanning',
      enrolled: 'enrollEnrolled',
      failed: 'enrollFailed',
    };
    return keys[this.state()];
  });

  protected readonly detail = computed(() => {
    if (this.enrollment.available() === false) return this.t('enrollUnavailableNote');
    const state = this.state();
    if (state === 'scanning') return `${this.t('enrollPress')} ${this.num(Math.min(3, this.presses() + 1))} ${this.t('ofWord')} ${this.num(3)}`;
    if (state === 'failed') return this.enrollment.status().message || this.t('enrollFailedNote');
    const keys: Partial<Record<EnrollState, CopyKey>> = {
      none: 'enrollIdleNote',
      queued: 'enrollQueuedNote',
      sent: 'enrollSentNote',
      enrolled: 'enrollEnrolledNote',
    };
    const key = keys[state];
    return key ? this.t(key) : '';
  });

  protected readonly busy = computed(() => ['queued', 'sent', 'scanning'].includes(this.state()));
  protected readonly pin = computed(() => this.enrollment.status().pin || this.person().pin);

  constructor() {
    super();
    afterNextRender(() => {
      this.dialog().nativeElement.showModal();
      void this.enrollment.open(this.person().id);
    });
  }

  ngOnDestroy(): void {
    this.enrollment.stop();
  }

  protected start(): void {
    void this.enrollment.start(this.person().id);
  }

  protected close(): void {
    this.enrollment.stop();
    this.dialog().nativeElement.close();
  }

  protected onClosed(): void {
    this.closed.emit();
  }
}
