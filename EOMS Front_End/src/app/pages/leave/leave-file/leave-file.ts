import { Component, computed, effect, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { validClock, validIsoDate } from '../../../data/leave';
import { Office } from '../../../data/office';
import { flowTone } from '../leave-table/leave-table';

@Component({
  selector: 'app-leave-file',
  templateUrl: './leave-file.html',
  styleUrl: './leave-file.scss',
})
export class LeaveFile extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly typeId = signal('annual');
  protected readonly from = signal('');
  protected readonly days = signal(1);
  protected readonly start = signal('09:00');
  protected readonly end = signal('11:00');
  protected readonly reason = signal('');
  protected readonly missed = signal(false);
  private loaded = false;
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly creating = computed(() => this.params()?.get('req') === 'new');
  protected readonly type = computed(() => this.office.leaveTypes().find((item) => item.id === this.typeId()));
  protected readonly hourly = computed(() => this.type()?.unit === 'hour');
  protected readonly row = computed(() => {
    const id = this.params()?.get('req') ?? '';
    if (!id || id === 'new') return undefined;
    const row = this.office.leaves().find((item) => item.id === id);
    if (!row) return undefined;
    const actor = this.session.account().id;
    const visible = this.session.canAct('leave.decide') || row.employeeId === actor || this.office.person(row.employeeId)?.managerId === actor;
    return visible ? row : undefined;
  });

  protected readonly ready = computed(() => {
    if (!validIsoDate(this.from())) return false;
    if (this.hourly()) return validClock(this.start()) && validClock(this.end()) && this.start() < this.end();
    const days = this.days();
    return Number.isInteger(days) && days >= 1 && days <= 60;
  });

  protected readonly clockOrder = computed(() => this.hourly() && validClock(this.start()) && validClock(this.end()) && this.start() >= this.end());

  constructor() {
    super();
    effect(() => {
      if (!this.creating()) {
        this.loaded = false;
        return;
      }
      if (this.loaded) return;
      this.loaded = true;
      this.typeId.set(this.office.leaveTypes()[0]?.id ?? 'annual');
      this.from.set('');
      this.days.set(1);
      this.start.set(this.office.policy().start);
      this.end.set('11:00');
      this.reason.set('');
      this.missed.set(false);
    });
  }

  protected tone(status: string): string {
    return flowTone(status);
  }

  protected own(): boolean {
    return this.row()?.employeeId === this.session.account().id;
  }

  protected actor(id: string): string {
    return this.text(this.office.personName(id));
  }

  protected read(field: 'typeId' | 'from' | 'start' | 'end' | 'reason', event: Event): void {
    this[field].set((event.target as HTMLInputElement | HTMLSelectElement).value);
  }

  protected onDays(event: Event): void {
    this.days.set(Number((event.target as HTMLInputElement).value));
  }

  protected back(): void {
    void this.router.navigate([], { queryParams: { req: null }, queryParamsHandling: 'merge' });
  }

  protected save(event: Event): void {
    event.preventDefault();
    if (!this.ready()) return;
    const actor = this.session.account().id;
    const id = this.hourly()
      ? this.office.requestHours(actor, this.typeId(), this.from(), this.start(), this.end(), this.reason())
      : this.office.requestLeave(actor, this.typeId(), this.from(), this.days(), this.reason());
    if (!id) {
      this.missed.set(true);
      return;
    }
    void this.router.navigate([], { queryParams: { req: id }, queryParamsHandling: 'merge' });
  }

  protected approve(): void {
    const id = this.row()?.id;
    if (id) this.office.approveLeave(id, this.session.account().id);
  }

  protected reject(): void {
    const id = this.row()?.id;
    if (id) this.office.rejectLeave(id, this.session.account().id);
  }
}
