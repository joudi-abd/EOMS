import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { BoardOptions, BoardSummary, BoardType } from '../../../data/tasks';
import { TasksBase } from '../tasks-base';

/** Every board the user can see, as tiles, with the form to open a new one. */
@Component({
  selector: 'app-board-list',
  imports: [FormsModule, RouterLink],
  templateUrl: './board-list.html',
  styleUrl: './board-list.scss',
  host: { class: 'ledger' },
})
export class BoardList extends TasksBase {
  readonly options = input<BoardOptions | null>(null);
  private readonly router = inject(Router);

  protected readonly boards = signal<BoardSummary[]>([]);
  protected readonly archivedBoards = signal<BoardSummary[]>([]);
  protected readonly loading = signal(true);
  protected readonly creating = signal(false);
  protected readonly showArchived = signal(false);

  protected form = { type: 'personal' as BoardType, name: '', description: '', color: this.swatches[0], department_id: 0 };

  protected readonly groups = computed(() => {
    const order: BoardType[] = ['personal', 'department', 'team', 'project'];
    return order
      .map((type) => ({ type, boards: this.boards().filter((board) => board.type === type) }))
      .filter((group) => group.boards.length > 0);
  });

  constructor() {
    super();
    void this.load();
    // The first type the user may open becomes the form's default once the options arrive.
    effect(() => {
      const options = this.options();
      if (options && !options.types.includes(this.form.type)) this.form.type = options.types[0] ?? 'personal';
    });
  }

  protected async load(): Promise<void> {
    this.loading.set(true);
    try {
      this.boards.set(await this.tasks.boards());
    } catch (error) {
      this.office.fail(error);
    } finally {
      this.loading.set(false);
    }
  }

  protected async toggleArchived(): Promise<void> {
    const show = !this.showArchived();
    this.showArchived.set(show);
    if (show) {
      const rows = await this.value(() => this.tasks.boards(true));
      this.archivedBoards.set(rows ?? []);
    }
  }

  protected typeHint(type: BoardType): string {
    if (type === 'personal') return this.t('tkTypePersonalHint');
    if (type === 'department') return this.t('tkTypeDepartmentHint');
    return this.t('tkTypeTeamHint');
  }

  protected startCreate(): void {
    const departments = this.options()?.departments ?? [];
    this.form = { ...this.form, name: '', description: '', department_id: departments[0]?.id ?? 0 };
    this.creating.set(true);
  }

  protected async create(): Promise<void> {
    const name = this.form.name.trim();
    if (!name) return;
    const snapshot = await this.value(
      () =>
        this.tasks.createBoard({
          type: this.form.type,
          name,
          description: this.form.description.trim() || null,
          color: this.form.color,
          department_id: this.form.type === 'department' ? this.form.department_id || null : null,
        }),
      'tkCreated',
    );
    if (snapshot) {
      this.creating.set(false);
      void this.router.navigate([], { queryParams: { board: snapshot.board.id } });
    }
  }

  protected async restore(board: BoardSummary): Promise<void> {
    if (await this.act(() => this.tasks.archiveBoard(board.id, false), 'tkDone')) {
      this.archivedBoards.update((rows) => rows.filter((row) => row.id !== board.id));
      void this.load();
    }
  }
}
