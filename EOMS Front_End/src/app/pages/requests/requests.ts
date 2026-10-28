import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { Ui } from '../../core/ui';
import { RequestKind } from '../../data/hr';
import { Office } from '../../data/office';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { RequestFile } from './request-file/request-file';
import { kindIcon, kindKey, requestKinds } from './request-kinds';
import { RequestTable } from './request-table/request-table';

@Component({
  selector: 'app-requests',
  imports: [Notice, RouterLink, RequestTable, RequestFile],
  templateUrl: './requests.html',
  styleUrl: './requests.scss',
})
export class Requests extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly chrome = inject(Chrome);
  protected readonly kinds = requestKinds;
  protected readonly kindKey = kindKey;
  protected readonly kindIcon = kindIcon;
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly request = computed(() => this.params()?.get('req') ?? '');
  protected readonly kind = computed(() => this.params()?.get('kind') ?? '');
  protected readonly status = computed(() => this.params()?.get('status') ?? '');

  protected readonly rows = computed(() => {
    const actor = this.session.account().id;
    const officeWide = this.session.canAct('leave.decide');
    const kind = this.kind();
    const status = this.status();
    return this.office.requests().filter((row) => {
      if (!officeWide && row.employeeId !== actor && this.office.person(row.employeeId)?.managerId !== actor) return false;
      if (kind && row.kind !== kind) return false;
      if (status && row.status !== status) return false;
      return true;
    });
  });

  protected openNew(kind: RequestKind): void {
    void this.router.navigate([], { queryParams: { req: 'new', draft: kind }, queryParamsHandling: 'merge' });
  }

  protected filter(name: 'kind' | 'status', event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    void this.router.navigate([], { queryParams: { [name]: value || null }, queryParamsHandling: 'merge' });
  }

  protected clearFilters(): void {
    void this.router.navigate([], { queryParams: { kind: null, status: null }, queryParamsHandling: 'merge' });
  }
}
