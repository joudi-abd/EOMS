import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute } from '@angular/router';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Language } from '../../../core/language';
import { Office } from '../../../data/office';

@Component({
  selector: 'app-leave-queue',
  templateUrl: './leave-queue.html',
  styleUrl: './leave-queue.scss',
})
export class LeaveQueue {
  protected readonly language = inject(Language);
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly open = computed(() => this.params()?.get('leave') === 'open');
  protected readonly pending = computed(() => {
    const actor = this.session.account().id;
    const waiting = this.office.leaves().filter((item) => item.status === 'pending_manager' || item.status === 'pending_hr');
    const decides = waiting.filter((item) => this.office.canAdvanceLeave(item.id, actor));
    if (decides.length || this.session.canAct('leave.decide')) {
      return decides;
    }
    return waiting.filter((item) => item.employeeId === actor);
  });

  protected t(key: CopyKey): string {
    return this.language.t(key);
  }

  protected text(value: { ar: string; en: string }): string {
    return this.language.text(value);
  }

  protected format(value: number): string {
    return this.language.formatNumber(value);
  }

  protected date(iso: string): string {
    return this.language.formatDate(iso);
  }

  protected span(leave: { kind: string; days: number; start: string; end: string }): string {
    if (leave.kind === 'permit') {
      return `${leave.start}–${leave.end}`;
    }
    return `${this.format(leave.days)} ${leave.days === 1 ? this.t('day') : this.t('days')}`;
  }

  protected approve(id: string): void {
    this.office.approveLeave(id, this.session.account().id);
  }

  protected reject(id: string): void {
    this.office.rejectLeave(id, this.session.account().id);
  }
}
