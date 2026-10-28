import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { DayMark, officeToday, PunchStatus } from '../../../data/attendance';
import { Office, projects } from '../../../data/office';
import { directionKey, markKey, markTone } from '../marks';

@Component({
  selector: 'app-attendance-day',
  templateUrl: './attendance-day.html',
  styleUrl: './attendance-day.scss',
})
export class AttendanceDay extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly projects = projects;
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly day = computed(() => this.params()?.get('day') || officeToday());
  protected readonly who = computed(() => this.params()?.get('who') ?? '');
  protected readonly employee = computed(() => this.office.person(this.who()));
  protected readonly allowed = computed(() => {
    const actor = this.session.account().id;
    return this.session.canAct('attendance.office') || this.who() === actor || this.employee()?.managerId === actor;
  });

  protected readonly report = computed(() => this.office.day(this.day()));
  protected readonly row = computed(() => this.report().rows.find((item) => item.employeeId === this.who()));
  protected readonly punches = computed(() => this.report().log.filter((item) => item.employeeId === this.who()));
  protected readonly correction = computed(() => this.office.adjustments().find((item) => item.employeeId === this.who() && item.day === this.day()));
  protected readonly mission = computed(() => this.office.missions().find((item) => item.employeeId === this.who() && item.day === this.day()));
  protected readonly resolution = computed(() => this.office.resolutions().find((item) => item.employeeId === this.who() && item.day === this.day()));
  protected readonly mayMission = computed(() => this.office.mayRecordMission(this.who(), this.session.account().id));

  protected mark(mark: DayMark): string {
    return this.t(markKey[mark]);
  }

  protected tone(mark: DayMark): string {
    return markTone(mark);
  }

  protected direction(status: PunchStatus): string {
    return this.t(directionKey[status]);
  }

  protected actor(id: string): string {
    return this.text(this.office.personName(id));
  }

  protected project(id: string): string {
    const match = projects.find((item) => item.id === id);
    return match ? this.text(match.name) : id;
  }

  protected department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : id;
  }

  protected back(): void {
    void this.router.navigate([], { queryParams: { who: null }, queryParamsHandling: 'merge' });
  }

  protected save(event: Event): void {
    event.preventDefault();
    const person = this.employee();
    if (!person) return;
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const saved = this.office.correctDay({
      employeeId: person.id,
      day: this.day(),
      checkIn: String(data.get('checkIn') ?? ''),
      checkOut: String(data.get('checkOut') ?? ''),
      note: String(data.get('note') ?? '').trim(),
      by: this.session.account().id,
    });
    if (saved) form.reset();
  }

  protected saveMission(event: Event): void {
    event.preventDefault();
    const data = new FormData(event.target as HTMLFormElement);
    this.office.addMission({
      employeeId: this.who(),
      day: this.day(),
      projectId: String(data.get('project') ?? ''),
      note: String(data.get('note') ?? '').trim(),
      actorId: this.session.account().id,
    });
  }

  protected confirm(event: Event): void {
    event.preventDefault();
    const data = new FormData(event.target as HTMLFormElement);
    this.office.confirmAbsence(this.who(), this.day(), String(data.get('note') ?? ''), this.session.account().id);
  }

  protected reopen(): void {
    this.office.reopenDay(this.who(), this.day(), this.session.account().id);
  }
}
