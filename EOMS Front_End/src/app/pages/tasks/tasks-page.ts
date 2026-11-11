import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { Ui } from '../../core/ui';
import { BoardOptions, Tasks } from '../../data/tasks';
import { Notice } from '../../shared/notice/notice';
import { BoardList } from './board-list/board-list';
import { BoardView } from './board-view/board-view';
import { MyTasks } from './my-tasks/my-tasks';
import { TaskReport } from './task-report/task-report';

/**
 * Task boards (`/tasks`), Trello-style. One page with three views chosen by the query string:
 * the boards (or one board with `?board=`, and a card on it with `&card=`), "my tasks", and the reports.
 */
@Component({
  selector: 'app-tasks-page',
  imports: [RouterLink, Notice, BoardList, BoardView, MyTasks, TaskReport],
  templateUrl: './tasks-page.html',
  styleUrl: './tasks-page.scss',
})
export class TasksPage extends Ui {
  protected readonly session = inject(Session);
  private readonly tasks = inject(Tasks);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);
  protected readonly options = signal<BoardOptions | null>(null);

  protected readonly view = computed<'boards' | 'mine' | 'reports'>(() => {
    const view = this.params()?.get('view');
    return view === 'mine' || view === 'reports' ? view : 'boards';
  });

  protected readonly boardId = computed(() => Number(this.params()?.get('board') ?? 0) || 0);
  protected readonly cardId = computed(() => Number(this.params()?.get('card') ?? 0) || 0);

  /** Reports are for people who see other people's boards: whoever may open team or department boards. */
  protected readonly showReports = computed(() => (this.options()?.types.length ?? 0) > 1);

  constructor() {
    super();
    void this.tasks.options().then((options) => this.options.set(options)).catch(() => undefined);
  }
}
