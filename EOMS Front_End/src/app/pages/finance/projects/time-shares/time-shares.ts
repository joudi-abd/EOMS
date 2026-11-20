import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { FinProjects, TimeShareMonth } from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';

interface Row {
  id: number;
  name: string;
  /** project id → percentage (empty = none). */
  cells: Record<number, number | null>;
}

interface Column {
  id: number;
  name: string;
}

/** The server keeps four decimal places and refuses more (`decimal:0,4`). */
function round4(value: number): number {
  return Math.round(value * 10_000) / 10_000;
}

/**
 * The month's time shares: how much of each employee's month went to each project. Each employee's total is at most
 * 100%; whatever is left stays a general cost. A month whose payroll allocation is posted is frozen.
 */
@Component({
  selector: 'app-fpj-time-shares',
  imports: [FormsModule],
  templateUrl: './time-shares.html',
  styleUrl: './time-shares.scss',
  host: { class: 'ledger' },
})
export class FpjTimeShares extends FinanceBase {
  private readonly api = inject(FinProjects);

  readonly year = input.required<number>();
  readonly month = input.required<number>();

  protected readonly loading = signal(true);
  protected readonly locked = signal(false);
  protected readonly rows = signal<Row[]>([]);
  protected readonly columns = signal<Column[]>([]);
  protected readonly dirty = signal(false);

  protected addEmployee = 0;
  protected addProject = 0;

  /** Employees not yet in the grid (the employee list needs `employees.view`). */
  protected readonly freeEmployees = computed(() => {
    const taken = new Set(this.rows().map((row) => row.id));
    return this.office
      .employees()
      .filter((item) => item.status !== 'ended' && !taken.has(Number(item.id)))
      .map((item) => ({ id: Number(item.id), name: this.text(item.name) }));
  });

  protected readonly freeProjects = computed(() => {
    const taken = new Set(this.columns().map((column) => column.id));
    return this.finance.openProjects().filter((item) => !taken.has(item.project_id));
  });

  constructor() {
    super();
    effect(() => {
      const year = this.year();
      const month = this.month();
      untracked(() => void this.load(year, month));
    });
  }

  private async load(year = this.year(), month = this.month()): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => this.fill(await this.api.timeShares(year, month)));
    this.loading.set(false);
  }

  private fill(data: TimeShareMonth): void {
    const columns = new Map<number, string>();
    const rows: Row[] = data.employees.map((employee) => {
      const cells: Record<number, number | null> = {};
      for (const share of employee.shares) {
        cells[share.project_id] = Number(share.percentage);
        if (!columns.has(share.project_id)) columns.set(share.project_id, share.project_name ?? this.finance.projectName(share.project_id));
      }
      return { id: employee.employee_id, name: employee.employee_name ?? `#${employee.employee_id}`, cells };
    });
    this.locked.set(data.locked);
    this.rows.set(rows);
    this.columns.set([...columns].map(([id, name]) => ({ id, name })));
    this.dirty.set(false);
  }

  protected total(row: Row): number {
    return Object.values(row.cells).reduce<number>((sum, value) => sum + (Number(value) || 0), 0);
  }

  protected over(row: Row): boolean {
    return this.total(row) > 100.00001;
  }

  protected changed(): void {
    this.dirty.set(true);
  }

  protected pickEmployee(id: number): void {
    const found = this.freeEmployees().find((item) => item.id === id);
    if (found) {
      this.rows.update((rows) => [...rows, { id: found.id, name: found.name, cells: {} }]);
      this.dirty.set(true);
    }
    this.addEmployee = 0;
  }

  protected pickProject(id: number): void {
    const found = this.finance.project(id);
    if (found) this.columns.update((columns) => [...columns, { id, name: found.name }]);
    this.addProject = 0;
  }

  protected async saveShares(): Promise<void> {
    if (this.rows().some((row) => this.over(row))) {
      this.warn('fpjOver100');
      return;
    }
    if (!this.rows().length) return;
    const employees = this.rows().map((row) => ({
      employee_id: row.id,
      shares: Object.entries(row.cells)
        .map(([project, value]) => ({ project_id: Number(project), percentage: round4(Number(value) || 0) }))
        .filter((share) => share.percentage > 0),
    }));
    const result = await this.act(() => this.api.setTimeShares(this.year(), this.month(), employees), 'fpjSharesSaved');
    if (result) this.fill(result);
  }

  protected async copyPrevious(): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    const result = await this.act(() => this.api.copyTimeShares(this.year(), this.month()), 'fpjSharesCopied');
    if (result) this.fill(result);
  }
}
