import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { EmployeeContracts } from '../employee-contracts/employee-contracts';
import { EmployeeFinger } from '../employee-finger/employee-finger';
import { EmployeeForm } from '../employee-form/employee-form';
import { EmployeeHistory } from '../employee-history/employee-history';
import { EmployeeInfo } from '../employee-info/employee-info';
import { EmployeeLeaving } from '../employee-leaving/employee-leaving';

type Tab = 'info' | 'contracts' | 'finger' | 'history' | 'leaving';

@Component({
  selector: 'app-employee-file',
  imports: [RouterLink, EmployeeForm, EmployeeInfo, EmployeeContracts, EmployeeFinger, EmployeeHistory, EmployeeLeaving],
  templateUrl: './employee-file.html',
  styleUrl: './employee-file.scss',
})
export class EmployeeFile extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly tabs: { id: Tab; label: CopyKey; icon: string }[] = [
    { id: 'info', label: 'tabInfo', icon: 'fa-id-card' },
    { id: 'contracts', label: 'tabContracts', icon: 'fa-file-signature' },
    { id: 'finger', label: 'tabFinger', icon: 'fa-fingerprint' },
    { id: 'history', label: 'tabHistory', icon: 'fa-clock-rotate-left' },
    { id: 'leaving', label: 'tabLeaving', icon: 'fa-box-archive' },
  ];

  protected readonly creating = computed(() => this.params()?.get('file') === 'new');
  protected readonly editing = computed(() => this.params()?.get('edit') === '1');
  protected readonly tab = computed<Tab>(() => {
    const value = this.params()?.get('tab');
    return this.tabs.some((item) => item.id === value) ? (value as Tab) : 'info';
  });
  protected readonly employee = computed(() => {
    const id = this.params()?.get('file');
    if (!id || id === 'new') return undefined;
    return this.office.person(id);
  });

  protected back(): void {
    void this.router.navigate([], { queryParams: { file: null, tab: null, edit: null }, queryParamsHandling: 'merge' });
  }
}
