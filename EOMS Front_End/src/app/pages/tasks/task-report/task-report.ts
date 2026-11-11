import { Component, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { BoardSummary, TaskReport as Report } from '../../../data/tasks';
import { TasksBase } from '../tasks-base';

/** Per-person figures over the shared boards the user can see: open, overdue, awaiting confirmation, finished on time. */
@Component({
  selector: 'app-task-report',
  imports: [FormsModule],
  templateUrl: './task-report.html',
  styleUrl: './task-report.scss',
  host: { class: 'ledger' },
})
export class TaskReport extends TasksBase {
  protected readonly report = signal<Report | null>(null);
  protected readonly boards = signal<BoardSummary[]>([]);
  protected readonly departments = signal<{ id: number; name: string }[]>([]);

  protected from = this.monthStart();
  protected to = this.today();
  protected boardId = 0;
  protected departmentId = 0;

  constructor() {
    super();
    void this.tasks.boards().then((rows) => this.boards.set(rows.filter((b) => b.type !== 'personal'))).catch(() => undefined);
    void this.tasks.options().then((options) => this.departments.set(options.departments)).catch(() => undefined);
    void this.load();
  }

  private monthStart(): string {
    return `${this.today().slice(0, 8)}01`;
  }

  protected async load(): Promise<void> {
    if (!this.from || !this.to || this.from > this.to) return;
    const report = await this.value(() =>
      this.tasks.report({ from: this.from, to: this.to, board_id: this.boardId || undefined, department_id: this.departmentId || undefined }),
    );
    if (report) this.report.set(report);
  }

  protected rate(value: number | null): string {
    return value === null ? '—' : this.percent(value);
  }
}
