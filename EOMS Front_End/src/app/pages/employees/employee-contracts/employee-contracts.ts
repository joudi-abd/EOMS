import { Component, computed, inject, input, signal } from '@angular/core';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Employee, EmploymentContract } from '../../../data/employees';
import { Office } from '../../../data/office';

@Component({
  selector: 'app-employee-contracts',
  templateUrl: './employee-contracts.html',
  styleUrl: './employee-contracts.scss',
})
export class EmployeeContracts extends Ui {
  readonly person = input.required<Employee>();
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly typeId = signal('fixed');
  protected readonly refused = signal(false);

  protected readonly fixedTerm = computed(() => this.office.contractTypes().find((item) => item.id === this.typeId())?.fixed ?? true);
  protected readonly rows = computed(() => [...this.person().contracts].reverse());

  protected typeName(id: string): string {
    const match = this.office.contractTypes().find((item) => item.id === id);
    return match ? this.text(match.name) : id;
  }

  protected state(contract: EmploymentContract): string {
    if (contract.status === 'current') return this.t('currentContract');
    if (contract.status === 'closed') return this.t('closedContract');
    return this.t('previousContract');
  }

  protected onType(event: Event): void {
    this.typeId.set((event.target as HTMLSelectElement).value);
  }

  protected renew(event: Event): void {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const saved = this.office.renewContract(
      this.person().id,
      this.typeId(),
      String(data.get('start') ?? ''),
      String(data.get('end') ?? ''),
      this.session.account().id,
    );
    this.refused.set(!saved);
    if (saved) {
      form.reset();
    }
  }
}
