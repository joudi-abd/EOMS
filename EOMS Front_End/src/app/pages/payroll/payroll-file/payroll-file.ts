import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';

@Component({
  selector: 'app-payroll-file',
  templateUrl: './payroll-file.html',
  styleUrl: './payroll-file.scss',
})
export class PayrollFile extends Ui {
  private readonly office = inject(Office);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly person = computed(() => this.office.person(this.params()?.get('sheet') ?? ''));
  protected readonly payments = computed(() => {
    const id = this.person()?.id;
    return this.office.payments().filter((item) => item.employeeId === id);
  });

  protected department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : id;
  }

  protected period(runId: string): string {
    const run = this.office.runs().find((item) => item.id === runId);
    if (!run) return runId;
    const [year, month] = run.period.split('-').map(Number);
    return this.language.formatMonth(new Date(year, month - 1, 1));
  }

  protected actor(id: string): string {
    return this.text(this.office.personName(id));
  }

  protected back(): void {
    void this.router.navigate([], { queryParams: { sheet: null }, queryParamsHandling: 'merge' });
  }
}
