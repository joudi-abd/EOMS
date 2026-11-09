import { Component, computed, effect, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Session } from '../../../core/access/session';
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
  /** The request opened in the side panel. */
  protected readonly picked = signal<number | null>(null);
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
  protected days(units: number): string { return this.num(units / 1_000_000); }
  protected clock(value: string | null | undefined): string { return leaveClock(value); }
  protected tone(status: string): string { return leaveTone(status); }
  protected label(status: string): string { return leaveLabel(this.language.lang() === 'ar', status); }
  protected owns(row: LeaveRecord): boolean { return row.is_owner || !!row.can_act_for_owner; }
  protected set(key: keyof LeaveFilters, value: string): void { this.filters()[key] = value; }
  protected pick(row: LeaveRecord): void {
    this.picked.set(this.picked() === row.id ? null : row.id);
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
    return row.approver_role === 'company_manager' ? this.l('مدير الشركة', 'Company manager') : this.l('الموارد البشرية', 'HR');
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
