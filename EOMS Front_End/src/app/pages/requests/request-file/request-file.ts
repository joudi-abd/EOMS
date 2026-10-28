import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { RequestKind } from '../../../data/hr';
import { Office } from '../../../data/office';
import { flowTone } from '../../leave/leave-table/leave-table';
import { kindEffect, kindKey, requestKinds } from '../request-kinds';

@Component({
  selector: 'app-request-file',
  imports: [RouterLink],
  templateUrl: './request-file.html',
  styleUrl: './request-file.scss',
})
export class RequestFile extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly kindKey = kindKey;
  protected readonly kindEffect = kindEffect;
  protected readonly refused = signal(false);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly creating = computed(() => this.params()?.get('req') === 'new');
  protected readonly draft = computed<RequestKind>(() => requestKinds.find((item) => item === this.params()?.get('draft')) ?? 'correction');
  protected readonly row = computed(() => {
    const id = this.params()?.get('req') ?? '';
    const row = this.office.requests().find((item) => item.id === id);
    if (!row) return undefined;
    const actor = this.session.account().id;
    const visible = this.session.canAct('leave.decide') || row.employeeId === actor || this.office.person(row.employeeId)?.managerId === actor;
    return visible ? row : undefined;
  });
  protected readonly custodyFor = computed(() => {
    const row = this.row();
    return row ? this.office.custody().find((item) => item.requestId === row.id) : undefined;
  });

  protected tone(status: string): string {
    return flowTone(status);
  }

  protected actor(id: string): string {
    return this.text(this.office.personName(id));
  }

  protected back(): void {
    void this.router.navigate([], { queryParams: { req: null, draft: null }, queryParamsHandling: 'merge' });
  }

  protected save(event: Event): void {
    event.preventDefault();
    const data = new FormData(event.target as HTMLFormElement);
    const read = (name: string) => String(data.get(name) ?? '').trim();
    const id = this.office.fileRequest({
      employeeId: this.session.account().id,
      kind: this.draft(),
      day: read('day'),
      checkIn: read('checkIn'),
      checkOut: read('checkOut'),
      item: read('item'),
      details: read('details'),
    });
    this.refused.set(!id);
    if (id) {
      void this.router.navigate([], { queryParams: { req: id, draft: null }, queryParamsHandling: 'merge' });
    }
  }

  protected decide(approve: boolean): void {
    const row = this.row();
    if (row) this.office.decideRequest(row.id, this.session.account().id, approve);
  }
}
