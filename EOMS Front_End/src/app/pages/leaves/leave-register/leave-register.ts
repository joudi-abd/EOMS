import { Component, computed, effect, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { LeaveOptions, LeaveRecord, LeaveTypeOption } from '../../../data/leaves';
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
  styleUrl: './leave-register.scss',
  host: { class: 'leave-kit' },
})
export class LeaveRegister extends Ui {
  protected readonly office = inject(Office);
  readonly settings = input.required<LeaveOptions>();
  readonly rows = input.required<LeaveRecord[]>();
  readonly filters = input.required<LeaveFilters>();
  readonly busy = input(false);
  readonly scope = input.required<'mine' | 'review' | 'all'>();
  readonly page = input(1);
  readonly lastPage = input(1);
  readonly decision = input<{row: LeaveRecord; action: string} | null>(null);
  readonly apply = output<void>();
  readonly turn = output<number>();
  readonly edit = output<LeaveRecord>();
  readonly choose = output<{row: LeaveRecord; action: string}>();
  readonly confirm = output<{reason: string; from: string}>();
  readonly dismiss = output<void>();
  protected reason = '';
  protected from = '';
  /** The request opened in the side card. */
  protected readonly picked = signal<number | null>(null);
  /** True while the detail card is playing its exit slide. */
  protected readonly closing = signal(false);
  protected readonly current = computed(() => this.rows().find((row: LeaveRecord) => row.id === this.picked()) ?? null);

  constructor() {
    super();
    effect(() => {
      this.decision();
      this.reason = '';
      this.from = '';
    });
  }

  protected l(ar: string, en: string): string { return this.language.lang() === 'ar' ? ar : en; }

  /** Compact page window with gaps: 1 … 4 5 6 … 12 */
  protected pageItems(): (number | 'gap')[] {
    const last = this.lastPage();
    const current = this.page();
    if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);
    const marks = new Set(
      [1, 2, last - 1, last, current - 1, current, current + 1].filter((page) => page >= 1 && page <= last),
    );
    const sorted = [...marks].sort((a, b) => a - b);
    const items: (number | 'gap')[] = [];
    for (let index = 0; index < sorted.length; index += 1) {
      const page = sorted[index]!;
      if (index > 0 && page - sorted[index - 1]! > 1) items.push('gap');
      items.push(page);
    }
    return items;
  }

  protected days(units: number): string { return this.num(units / 1_000_000); }
  protected clock(value: string | null | undefined): string { return leaveClock(value); }
  protected tone(status: string): string { return leaveTone(status); }
  protected label(status: string): string { return leaveLabel(this.language.lang() === 'ar', status); }
  protected owns(row: LeaveRecord): boolean { return row.is_owner || !!row.can_act_for_owner; }
  protected set(key: keyof LeaveFilters, value: string): void {
    this.filters()[key] = value;
    this.apply.emit();
  }

  protected pick(row: LeaveRecord): void {
    if (this.picked() === row.id) {
      this.close();
      return;
    }
    this.closing.set(false);
    this.picked.set(row.id);
  }

  protected close(): void {
    if (this.picked() === null || this.closing()) return;
    this.dismiss.emit();
    if (typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches) {
      this.picked.set(null);
      return;
    }
    this.closing.set(true);
  }

  protected finishClose(event?: AnimationEvent): void {
    // Angular emulates view encapsulation by prefixing keyframe names.
    if (event && !String(event.animationName).includes('lv-card-out')) return;
    if (!this.closing()) return;
    this.closing.set(false);
    this.picked.set(null);
  }

  protected typeName(id: number): string {
    return this.settings().types.find((type: LeaveTypeOption) => type.id === id)?.name ?? `#${id}`;
  }

  /** Whole and part days the request takes, counting only days still active. */
  protected total(row: LeaveRecord): string {
    const units = row.days.filter((day) => day.active !== false).reduce((sum, day) => sum + day.covered_units + day.unpaid_units, 0);
    return this.days(units);
  }

  protected unpaid(row: LeaveRecord): number {
    return row.days.filter((day) => day.active !== false).reduce((sum, day) => sum + day.unpaid_units, 0);
  }

  protected approver(row: LeaveRecord): string {
    // Routed by permission: staff requests go to leave approvers, the approvers' own to the level above them.
    return row.approval_level === 'reviewers' ? this.l('المستوى الأعلى', 'Senior approver') : this.l('معتمِد الإجازات', 'Leave approver');
  }

  /** Two initials for the avatar; the Arabic article is not a name's first letter. */
  protected initialsOf(name: string): string {
    return (name || '')
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((word) => word.replace(/^ال/, '').charAt(0))
      .join(' ');
  }

  protected decided(row: LeaveRecord): boolean {
    return row.status !== 'pending';
  }

  /** The balance the account holds for this type, shown only on the account's own request. */
  protected balanceOf(row: LeaveRecord): number | null {
    if (!row.is_owner) return null;
    return this.settings().types.find((type: LeaveTypeOption) => type.id === row.leave_type_id)?.available_units ?? null;
  }

  protected readonly statuses = ['pending', 'approved', 'rejected', 'withdrawn', 'cancelled', 'expired'];
}
