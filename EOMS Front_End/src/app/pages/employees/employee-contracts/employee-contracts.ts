import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { isoDay } from '../../../data/hr';
import { Contract, ContractStatus, ContractType, Records } from '../../../data/records';

type Step = { kind: 'new' | 'renew' | 'end' | 'schedule'; contract: Contract | null };

const statusKey: Record<ContractStatus, CopyKey> = {
  draft: 'contractDraft',
  active: 'contractActive',
  expired: 'contractExpired',
  terminated: 'contractTerminated',
};

const typeKey: Record<ContractType, CopyKey> = { fixed_term: 'contractFixed', indefinite: 'contractOpen' };

@Component({
  selector: 'app-employee-contracts',
  templateUrl: './employee-contracts.html',
  styleUrl: './employee-contracts.scss',
})
export class EmployeeContracts extends Ui {
  readonly person = input.required<Employee>();
  protected readonly records = inject(Records);
  protected readonly session = inject(Session);
  protected readonly statusKey = statusKey;
  protected readonly typeKey = typeKey;
  protected readonly step = signal<Step | null>(null);
  protected readonly draftType = signal<ContractType>('fixed_term');
  protected readonly problem = signal('');
  protected readonly saving = signal(false);

  protected readonly today = isoDay(new Date());
  protected readonly tomorrow = isoDay(new Date(Date.now() + 86_400_000));

  protected readonly rows = computed(() => this.records.contracts()[this.person().id] ?? []);
  protected readonly loading = computed(() => this.records.isLoading('contracts', this.person().id));
  protected readonly manages = computed(() => this.person().status !== 'ended' && this.session.mayEdit(this.person().id, 'employee.edit'));
  protected readonly suggestedNumber = computed(() => `${this.person().staffNo}-${this.today.slice(0, 4)}-${this.rows().length + 1}`);

  constructor() {
    super();
    effect(() => {
      const id = this.person().id;
      untracked(() => {
        this.step.set(null);
        void this.records.loadContracts(id);
      });
    });
  }

  protected renewed(contract: Contract): boolean {
    return this.rows().some((row) => row.renewedFrom === contract.id);
  }

  protected open(kind: Step['kind'], contract: Contract | null = null): void {
    this.problem.set('');
    this.draftType.set(contract?.type ?? 'fixed_term');
    this.step.set({ kind, contract });
    if ((kind === 'new' || kind === 'renew') && this.person().financial) void this.records.loadCurrencies();
  }

  protected close(): void {
    this.step.set(null);
    this.problem.set('');
  }

  protected onType(event: Event): void {
    this.draftType.set((event.target as HTMLSelectElement).value === 'indefinite' ? 'indefinite' : 'fixed_term');
  }

  protected async activate(contract: Contract): Promise<void> {
    await this.run(() => this.records.activate(this.person().id, contract.id));
  }

  protected async cancelSchedule(contract: Contract): Promise<void> {
    await this.run(() => this.records.cancelScheduledEnd(this.person().id, contract.id));
  }

  protected async submit(event: Event): Promise<void> {
    event.preventDefault();
    const step = this.step();
    if (!step) return;
    const data = new FormData(event.target as HTMLFormElement);
    const read = (name: string) => String(data.get(name) ?? '').trim();
    const id = this.person().id;
    const contractId = step.contract?.id ?? '';
    if (step.kind === 'end') {
      await this.run(() => this.records.endContract(id, contractId, read('date'), read('reason')), true);
      return;
    }
    if (step.kind === 'schedule') {
      await this.run(() => this.records.scheduleEnd(id, contractId, read('date'), read('reason')), true);
      return;
    }
    const entry = { number: read('number'), type: this.draftType(), start: read('start'), end: read('end'), salary: read('salary'), currency: read('currency') };
    await this.run(() => this.records.createContract(id, entry, step.kind === 'renew' ? contractId : ''), true);
  }

  private async run(action: () => Promise<{ ok: boolean; error?: string }>, closeOnDone = false): Promise<void> {
    if (this.saving()) return;
    this.saving.set(true);
    this.problem.set('');
    const outcome = await action();
    this.saving.set(false);
    if (!outcome.ok) this.problem.set(outcome.error ?? '');
    else if (closeOnDone) this.step.set(null);
  }
}
