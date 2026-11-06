import { Component, computed, effect, inject, input, untracked } from '@angular/core';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { Office } from '../../../data/office';
import { JobChange, Records } from '../../../data/records';

interface Line {
  field: CopyKey;
  from: string;
  to: string;
}

@Component({
  selector: 'app-employee-history',
  templateUrl: './employee-history.html',
  styleUrl: './employee-history.scss',
})
export class EmployeeHistory extends Ui {
  readonly person = input.required<Employee>();
  protected readonly office = inject(Office);
  protected readonly records = inject(Records);

  protected readonly entries = computed(() => this.records.history()[this.person().id] ?? []);
  protected readonly loading = computed(() => this.records.isLoading('history', this.person().id));

  constructor() {
    super();
    effect(() => {
      const id = this.person().id;
      untracked(() => void this.records.loadHistory(id));
    });
  }

  /** The hire lists what the person started with; a change lists only what moved. */
  protected lines(entry: JobChange): Line[] {
    const all: Line[] = [
      { field: 'fieldDepartment', from: this.department(entry.department[0]), to: this.department(entry.department[1]) },
      { field: 'fieldTitle', from: this.title(entry.title[0]), to: this.title(entry.title[1]) },
      { field: 'fieldManager', from: this.manager(entry.manager[0]), to: this.manager(entry.manager[1]) },
    ];
    if (entry.event === 'hired') return all.map((line) => ({ ...line, from: '' }));
    const pairs = [entry.department, entry.title, entry.manager];
    return all.filter((_, index) => pairs[index][0] !== pairs[index][1]);
  }

  private department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : '—';
  }

  private title(id: string): string {
    const match = this.office.titles().find((item) => item.id === id);
    return match ? this.text(match.name) : '—';
  }

  private manager(id: string): string {
    return id ? this.text(this.office.personName(id)) : '—';
  }
}
