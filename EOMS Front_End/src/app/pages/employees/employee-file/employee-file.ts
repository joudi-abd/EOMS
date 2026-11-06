import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { Office } from '../../../data/office';
import { EmployeeDossier } from '../employee-dossier';
import { EmployeeContracts } from '../employee-contracts/employee-contracts';
import { EmployeeDocuments } from '../employee-documents/employee-documents';
import { EmployeeFinger } from '../employee-finger/employee-finger';
import { EmployeeForm } from '../employee-form/employee-form';
import { EmployeeHistory } from '../employee-history/employee-history';
import { EmployeeInfo } from '../employee-info/employee-info';
import { EmployeeLeaving } from '../employee-leaving/employee-leaving';

type Tab = 'info' | 'contracts' | 'documents' | 'finger' | 'history' | 'leaving';

@Component({
  selector: 'app-employee-file',
  imports: [RouterLink, EmployeeForm, EmployeeInfo, EmployeeContracts, EmployeeDocuments, EmployeeFinger, EmployeeHistory, EmployeeLeaving],
  templateUrl: './employee-file.html',
  styleUrl: './employee-file.scss',
})
export class EmployeeFile extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly dossier = inject(EmployeeDossier);
  protected readonly exporting = signal(false);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  /** Documents sit behind their own permission on the server. */
  protected readonly tabs = computed(() =>
    (
      [
        { id: 'info', label: 'tabInfo', icon: 'fa-id-card' },
        { id: 'contracts', label: 'tabContracts', icon: 'fa-file-signature' },
        { id: 'documents', label: 'tabDocuments', icon: 'fa-folder-open' },
        { id: 'finger', label: 'tabFinger', icon: 'fa-fingerprint' },
        { id: 'history', label: 'tabHistory', icon: 'fa-clock-rotate-left' },
        { id: 'leaving', label: 'offboardTitle', icon: 'fa-user-minus' },
      ] as { id: Tab; label: CopyKey; icon: string }[]
    ).filter((item) => item.id !== 'documents' || this.session.canAct('document.view')),
  );

  protected readonly creating = computed(() => this.params()?.get('file') === 'new');
  protected readonly editing = computed(() => this.params()?.get('edit') === '1');
  protected readonly tab = computed<Tab>(() => {
    const value = this.params()?.get('tab');
    if (this.tabs().some((item) => item.id === value)) return value as Tab;
    if (this.params()?.get('enroll') === '1' && this.tabs().some((item) => item.id === 'finger')) return 'finger';
    return 'info';
  });
  protected readonly employee = computed(() => {
    const id = this.params()?.get('file');
    if (!id || id === 'new') return undefined;
    return this.office.person(id);
  });

  protected async exportDossier(person: Employee): Promise<void> {
    if (this.exporting()) return;
    this.exporting.set(true);
    try {
      await this.dossier.export(person);
    } finally {
      this.exporting.set(false);
    }
  }

  protected back(): void {
    void this.router.navigate([], { queryParams: { file: null, tab: null, edit: null, enroll: null }, queryParamsHandling: 'merge' });
  }
}
