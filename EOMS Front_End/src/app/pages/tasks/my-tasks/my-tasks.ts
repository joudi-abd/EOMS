import { Component, computed, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { MyCard, MyCardsFilter, priorities, Priority } from '../../../data/tasks';
import { TasksBase } from '../tasks-base';

type Quick = 'open' | 'overdue' | 'soon' | 'finished';

/** Cards assigned to me on every board I can still see, soonest due first. */
@Component({
  selector: 'app-my-tasks',
  imports: [FormsModule, RouterLink],
  templateUrl: './my-tasks.html',
  styleUrl: './my-tasks.scss',
  host: { class: 'ledger' },
})
export class MyTasks extends TasksBase {
  protected readonly cards = signal<MyCard[]>([]);
  protected readonly todayIso = signal('');
  protected readonly loading = signal(true);
  protected readonly quick = signal<Quick>('open');
  protected readonly priorities = priorities;
  protected priority: Priority | '' = '';

  /** Boards that appear in the result, for the board filter. */
  protected readonly boardsSeen = computed(() => {
    const seen = new Map<number, string>();
    for (const card of this.cards()) seen.set(card.board.id, card.board.name);
    return [...seen].map(([id, name]) => ({ id, name }));
  });
  protected boardId = 0;

  protected readonly shown = computed(() => this.cards().filter((card) => !this.boardId || card.board.id === this.boardId));

  constructor() {
    super();
    void this.load();
  }

  protected pick(quick: Quick): void {
    this.quick.set(quick);
    this.boardId = 0;
    void this.load();
  }

  protected async load(): Promise<void> {
    const quick = this.quick();
    const filter: MyCardsFilter = {
      state: quick === 'finished' ? 'finished' : 'open',
      due: quick === 'overdue' ? 'overdue' : quick === 'soon' ? 'soon' : undefined,
      priority: this.priority || undefined,
    };
    this.loading.set(true);
    try {
      const result = await this.tasks.mine(filter);
      this.cards.set(result.cards);
      this.todayIso.set(result.today);
    } catch (error) {
      this.office.fail(error);
    } finally {
      this.loading.set(false);
    }
  }

  protected overdue(card: MyCard): boolean {
    return !!card.due_date && !card.done_at && card.list.kind !== 'finished' && card.due_date < this.todayIso();
  }
}
