import { Component, computed, DestroyRef, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import {
  BoardRole,
  BoardSnapshot,
  CardSummary,
  Frequency,
  Label,
  Member,
  Person,
  priorities,
  Priority,
  Recurrence,
  roles,
  TaskListRow,
} from '../../../data/tasks';
import { CardPanel } from '../card-panel/card-panel';
import { dayKeys, TasksBase } from '../tasks-base';

type Panel = 'none' | 'members' | 'labels' | 'recurring' | 'settings' | 'archive';

/** Where a dragged card would land: before `beforeId` in `listId`, or at the end when `beforeId` is null. */
interface DropTarget {
  listId: number;
  beforeId: number | null;
}

const POLL_MS = 5000;

/**
 * One board: its lists as columns of cards. Cards move by drag and drop (or from the card panel's "Move to"),
 * changes from other people arrive by polling the board's change feed, and the open card shows in a side panel.
 */
@Component({
  selector: 'app-board-view',
  imports: [FormsModule, RouterLink, CardPanel],
  templateUrl: './board-view.html',
  styleUrl: './board-view.scss',
  host: { class: 'ledger' },
})
export class BoardView extends TasksBase {
  readonly boardId = input.required<number>();
  readonly cardId = input(0);
  private readonly router = inject(Router);

  protected readonly snapshot = signal<BoardSnapshot | null>(null);
  protected readonly loading = signal(true);
  protected readonly missing = signal(false);
  protected readonly panel = signal<Panel>('none');
  /** Bumped when someone else changes the board, so the open card reloads too. */
  protected readonly refresh = signal(0);

  protected readonly dragging = signal<number | null>(null);
  protected readonly target = signal<DropTarget | null>(null);

  protected readonly role = computed<BoardRole>(() => this.snapshot()?.role ?? 'viewer');
  protected readonly archived = computed(() => !!this.snapshot()?.board.archived_at);
  protected readonly isOwner = computed(() => this.role() === 'owner' && !this.archived());
  protected readonly canWork = computed(() => this.role() !== 'viewer' && !this.archived());
  protected readonly isPersonal = computed(() => this.snapshot()?.board.type === 'personal');
  protected readonly lists = computed(() => [...(this.snapshot()?.lists ?? [])].sort((a, b) => Number(a.position) - Number(b.position)));
  protected readonly members = computed(() => this.snapshot()?.members ?? []);
  protected readonly workers = computed(() => this.members().filter((m) => m.role !== 'viewer'));
  protected readonly labels = computed(() => this.snapshot()?.labels ?? []);
  protected readonly todayIso = computed(() => this.snapshot()?.today ?? this.today());

  private readonly byList = computed(() => {
    const map = new Map<number, CardSummary[]>();
    for (const card of this.snapshot()?.cards ?? []) {
      const rows = map.get(card.list_id) ?? [];
      rows.push(card);
      map.set(card.list_id, rows);
    }
    for (const rows of map.values()) rows.sort((a, b) => Number(a.position) - Number(b.position));
    return map;
  });

  // Quick-add and list forms
  protected newCard: Record<number, string> = {};
  protected newList = '';
  protected renaming = signal<number | null>(null);
  protected renameText = '';

  // Members panel
  protected readonly people = signal<Person[]>([]);
  protected readonly peopleAllowed = signal(true);
  protected peopleQuery = '';
  protected newMemberRole: BoardRole = 'member';
  protected readonly roles = roles;

  // Labels panel
  protected labelForm = { id: 0, name: '', color: this.swatches[0] };

  // Settings panel
  protected settings = { name: '', description: '', color: '' };

  // Archive panel
  protected readonly archivedLists = signal<(TaskListRow & { archived_at: string })[]>([]);
  protected readonly archivedCards = signal<CardSummary[]>([]);

  // Recurring panel
  protected readonly recurrences = signal<Recurrence[]>([]);
  protected readonly priorities = priorities;
  protected readonly dayKeys = dayKeys;
  protected recurring = this.blankRecurring();

  private cursor = 0;
  private polling = false;

  constructor() {
    super();
    effect(() => {
      const id = this.boardId();
      untracked(() => {
        this.panel.set('none');
        void this.load(id);
      });
    });
    const timer = setInterval(() => void this.poll(), POLL_MS);
    inject(DestroyRef).onDestroy(() => clearInterval(timer));
  }

  protected cardsIn(listId: number): CardSummary[] {
    return this.byList().get(listId) ?? [];
  }

  protected member(userId: number): Member | undefined {
    return this.members().find((m) => m.user_id === userId);
  }

  protected label(id: number): Label | undefined {
    return this.labels().find((l) => l.id === id);
  }

  protected overdue(card: CardSummary): boolean {
    return !!card.due_date && !card.done_at && card.due_date < this.todayIso();
  }

  protected isFinishedList(list: TaskListRow): boolean {
    return list.kind === 'finished';
  }

  protected async load(id = this.boardId()): Promise<void> {
    this.loading.set(!this.snapshot() || this.snapshot()?.board.id !== id);
    try {
      const snapshot = await this.tasks.board(id);
      this.snapshot.set(snapshot);
      this.cursor = snapshot.cursor;
      this.missing.set(false);
    } catch (error) {
      this.missing.set(true);
      this.office.fail(error);
    } finally {
      this.loading.set(false);
    }
  }

  protected override async onConflict(): Promise<void> {
    await this.load();
  }

  /** Polls the change feed while the tab is visible; reloads the board when someone else changed it. */
  private async poll(): Promise<void> {
    const snapshot = this.snapshot();
    if (!snapshot || this.polling || this.dragging() !== null || document.visibilityState !== 'visible') return;
    this.polling = true;
    try {
      const feed = await this.tasks.changes(snapshot.board.id, this.cursor);
      const foreign = feed.changes.some((change) => change.user_id !== this.me) || !feed.complete;
      this.cursor = feed.cursor;
      if (foreign) {
        await this.load();
        this.refresh.update((n) => n + 1);
      }
    } catch {
      // A missed poll is retried on the next tick.
    } finally {
      this.polling = false;
    }
  }

  // ── Cards ─────────────────────────────────────────────────────────────────

  protected open(card: CardSummary): void {
    void this.router.navigate([], { queryParams: { board: this.boardId(), card: card.id } });
  }

  protected closeCard(): void {
    void this.router.navigate([], { queryParams: { board: this.boardId() } });
  }

  protected async addCard(list: TaskListRow): Promise<void> {
    const title = (this.newCard[list.id] ?? '').trim();
    if (!title) return;
    const card = await this.value(() => this.tasks.createCard(this.boardId(), { list_id: list.id, title }));
    if (card) {
      this.newCard[list.id] = '';
      await this.load();
    }
  }

  /** The card panel changed a card: refresh its row (and the whole board when it left this board or list order changed). */
  protected async cardChanged(): Promise<void> {
    await this.load();
  }

  // ── Drag and drop ─────────────────────────────────────────────────────────

  protected dragStart(event: DragEvent, card: CardSummary): void {
    if (!this.canWork() || this.listKind(card.list_id) === 'finished') {
      event.preventDefault();
      return;
    }
    this.dragging.set(card.id);
    event.dataTransfer?.setData('text/plain', String(card.id));
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
  }

  protected dragEnd(): void {
    this.dragging.set(null);
    this.target.set(null);
  }

  protected overCard(event: DragEvent, list: TaskListRow, card: CardSummary): void {
    if (this.dragging() === null || !this.mayDropIn(list)) return;
    event.preventDefault();
    event.stopPropagation();
    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const before = event.clientY < box.top + box.height / 2;
    const rows = this.cardsIn(list.id);
    const index = rows.findIndex((row) => row.id === card.id);
    const beforeId = before ? card.id : (rows[index + 1]?.id ?? null);
    this.setTarget({ listId: list.id, beforeId });
  }

  protected overList(event: DragEvent, list: TaskListRow): void {
    if (this.dragging() === null || !this.mayDropIn(list)) return;
    event.preventDefault();
    const current = this.target();
    if (!current || current.listId !== list.id) this.setTarget({ listId: list.id, beforeId: null });
  }

  private setTarget(target: DropTarget): void {
    const current = this.target();
    if (!current || current.listId !== target.listId || current.beforeId !== target.beforeId) this.target.set(target);
  }

  protected showGap(listId: number, beforeId: number | null): boolean {
    const target = this.target();
    return !!target && target.listId === listId && target.beforeId === beforeId;
  }

  /** Mirrors the server: only the owner drops into "finished"; nobody drags out of it. */
  private mayDropIn(list: TaskListRow): boolean {
    return list.kind !== 'finished' || this.isOwner();
  }

  private listKind(listId: number): TaskListRow['kind'] | undefined {
    return this.lists().find((list) => list.id === listId)?.kind;
  }

  protected async drop(event: DragEvent): Promise<void> {
    event.preventDefault();
    const id = this.dragging();
    const target = this.target();
    this.dragEnd();
    if (id === null || !target) return;
    await this.moveCard(id, target.listId, target.beforeId);
  }

  /** Moves a card before another (or to the end): optimistic on screen, then confirmed by the server. */
  async moveCard(id: number, listId: number, beforeId: number | null): Promise<void> {
    const snapshot = this.snapshot();
    if (!snapshot || beforeId === id) return;
    const rows = this.cardsIn(listId).filter((row) => row.id !== id);
    const index = beforeId === null ? rows.length : rows.findIndex((row) => row.id === beforeId);
    const after = index > 0 ? rows[index - 1] : null;
    const next = index >= 0 ? (rows[index] ?? null) : null;
    const card = snapshot.cards.find((row) => row.id === id);
    if (!card || index < 0) return;
    // Dropped where it already was: with the card taken out, its old index is the same slot.
    if (card.list_id === listId && this.cardsIn(listId).findIndex((row) => row.id === id) === index) return;
    const low = after ? Number(after.position) : next ? Number(next.position) - 2048 : 0;
    const high = next ? Number(next.position) : low + 2048;
    const position = (low + high) / 2;
    this.snapshot.set({ ...snapshot, cards: snapshot.cards.map((row) => (row.id === id ? { ...row, list_id: listId, position } : row)) });

    const moved = await this.value(() => this.tasks.move(id, listId, after?.id ?? null));
    if (moved) {
      const current = this.snapshot();
      if (current) this.snapshot.set({ ...current, cards: current.cards.map((row) => (row.id === id ? moved : row)) });
      // Personal boards finish a card straight from "done", and other moves may renumber a list: reload to be exact.
      if (moved.list_id !== listId || this.listKind(listId) !== 'normal') await this.load();
      this.refresh.update((n) => n + 1);
    } else {
      await this.load();
    }
  }

  // ── Lists ─────────────────────────────────────────────────────────────────

  protected async addList(): Promise<void> {
    const name = this.newList.trim();
    if (!name) return;
    if (await this.act(() => this.tasks.createList(this.boardId(), name))) {
      this.newList = '';
      await this.load();
    }
  }

  protected startRename(list: TaskListRow): void {
    this.renaming.set(list.id);
    this.renameText = list.name;
  }

  protected async rename(list: TaskListRow): Promise<void> {
    const name = this.renameText.trim();
    this.renaming.set(null);
    if (!name || name === list.name) return;
    if (await this.act(() => this.tasks.updateList(list.id, { name }))) await this.load();
  }

  protected async archiveList(list: TaskListRow): Promise<void> {
    if (await this.act(() => this.tasks.archiveList(list.id, true), 'tkDone')) await this.load();
  }

  /** Swaps a normal list with its neighbour by placing it just past that neighbour. */
  protected async shiftList(list: TaskListRow, step: -1 | 1): Promise<void> {
    const rows = this.lists();
    const index = rows.findIndex((row) => row.id === list.id);
    const neighbour = rows[index + step];
    if (!neighbour || neighbour.kind !== 'normal') return;
    const beyond = rows[index + 2 * step];
    const edge = Number(neighbour.position);
    const far = beyond ? Number(beyond.position) : edge + step * 2048;
    if (await this.act(() => this.tasks.updateList(list.id, { position: (edge + far) / 2 }))) await this.load();
  }

  protected canShift(list: TaskListRow, step: -1 | 1): boolean {
    const rows = this.lists();
    const index = rows.findIndex((row) => row.id === list.id);
    return list.kind === 'normal' && rows[index + step]?.kind === 'normal';
  }

  // ── Panels ────────────────────────────────────────────────────────────────

  protected show(panel: Panel): void {
    const next = this.panel() === panel ? 'none' : panel;
    this.panel.set(next);
    const board = this.snapshot()?.board;
    if (next === 'settings' && board) this.settings = { name: board.name, description: board.description ?? '', color: board.color ?? this.swatches[0] };
    if (next === 'members' && this.isOwner() && !this.isPersonal()) void this.searchPeople();
    if (next === 'recurring') void this.loadRecurrences();
    if (next === 'archive') void this.loadArchive();
    if (next === 'labels') this.labelForm = { id: 0, name: '', color: this.swatches[0] };
  }

  // Members
  protected async searchPeople(): Promise<void> {
    try {
      this.people.set(await this.tasks.people(this.peopleQuery.trim()));
      this.peopleAllowed.set(true);
    } catch {
      this.peopleAllowed.set(false);
    }
  }

  protected candidates(): Person[] {
    const explicit = new Set(this.members().filter((m) => !m.implicit).map((m) => m.user_id));
    return this.people().filter((person) => !explicit.has(person.user_id));
  }

  protected async addMember(person: Person): Promise<void> {
    if (await this.act(() => this.tasks.addMember(this.boardId(), person.user_id, this.newMemberRole), 'tkDone')) await this.load();
  }

  protected async changeRole(member: Member, role: BoardRole): Promise<void> {
    // A department board's implicit members get an explicit row when their role changes.
    await this.act(
      () => (member.implicit ? this.tasks.addMember(this.boardId(), member.user_id, role) : this.tasks.changeRole(this.boardId(), member.user_id, role)),
      'tkDone',
    );
    await this.load();
  }

  protected async removeMember(member: Member): Promise<void> {
    if (await this.act(() => this.tasks.removeMember(this.boardId(), member.user_id), 'tkDone')) await this.load();
  }

  // Labels
  protected editLabel(label: Label): void {
    this.labelForm = { id: label.id, name: label.name, color: label.color };
  }

  protected async saveLabel(): Promise<void> {
    const name = this.labelForm.name.trim();
    if (!name) return;
    const { id, color } = this.labelForm;
    const saved = await this.act(() => (id ? this.tasks.updateLabel(id, { name, color }) : this.tasks.createLabel(this.boardId(), name, color)));
    if (saved) {
      this.labelForm = { id: 0, name: '', color: this.swatches[0] };
      await this.load();
    }
  }

  protected async deleteLabel(label: Label): Promise<void> {
    if (await this.act(() => this.tasks.deleteLabel(label.id), 'tkDone')) await this.load();
  }

  // Settings
  protected async saveSettings(): Promise<void> {
    const name = this.settings.name.trim();
    if (!name) return;
    const body = { name, description: this.settings.description.trim() || null, color: this.settings.color || null };
    if (await this.act(() => this.tasks.updateBoard(this.boardId(), body), 'tkSettingsSaved')) await this.load();
  }

  protected async archiveBoard(archived: boolean): Promise<void> {
    if (await this.act(() => this.tasks.archiveBoard(this.boardId(), archived), 'tkDone')) {
      this.panel.set('none');
      await this.load();
    }
  }

  // Archive
  protected async loadArchive(): Promise<void> {
    const rows = await this.value(() => this.tasks.archived(this.boardId()));
    this.archivedLists.set(rows?.lists ?? []);
    this.archivedCards.set(rows?.cards ?? []);
  }

  protected async restoreCard(card: CardSummary): Promise<void> {
    if (await this.act(() => this.tasks.archiveCard(card.id, false), 'tkRestoredCard')) {
      await Promise.all([this.loadArchive(), this.load()]);
    }
  }

  protected async restoreList(list: TaskListRow): Promise<void> {
    if (await this.act(() => this.tasks.archiveList(list.id, false), 'tkDone')) {
      await Promise.all([this.loadArchive(), this.load()]);
    }
  }

  protected listName(listId: number): string {
    return this.lists().find((list) => list.id === listId)?.name ?? '—';
  }

  // Recurring
  private blankRecurring() {
    return {
      title: '',
      list_id: 0,
      frequency: 'weekly' as Frequency,
      weekdays: [0] as number[],
      month_day: 1,
      due_in_days: 0,
      priority: 'normal' as Priority,
      assignee_ids: [] as number[],
    };
  }

  protected async loadRecurrences(): Promise<void> {
    const rows = await this.value(() => this.tasks.recurrences(this.boardId()));
    this.recurrences.set(rows ?? []);
    if (!this.recurring.list_id) this.recurring.list_id = this.lists().find((list) => list.kind === 'normal')?.id ?? 0;
  }

  protected toggleIn(values: number[], value: number): number[] {
    return values.includes(value) ? values.filter((v) => v !== value) : [...values, value].sort((a, b) => a - b);
  }

  protected async saveRecurring(): Promise<void> {
    const form = this.recurring;
    if (!form.title.trim() || !form.list_id) return;
    if (form.frequency === 'weekly' && !form.weekdays.length) return;
    const saved = await this.act(
      () =>
        this.tasks.createRecurrence(this.boardId(), {
          list_id: form.list_id,
          frequency: form.frequency,
          weekdays: form.frequency === 'weekly' ? form.weekdays : null,
          month_day: form.frequency === 'monthly' ? form.month_day : null,
          due_in_days: form.due_in_days,
          template: { title: form.title.trim(), priority: form.priority, assignee_ids: form.assignee_ids },
        }),
      'tkDone',
    );
    if (saved) {
      this.recurring = this.blankRecurring();
      await this.loadRecurrences();
    }
  }

  protected async stopRecurring(row: Recurrence): Promise<void> {
    if (await this.act(() => this.tasks.stopRecurrence(row.id), 'tkDone')) await this.loadRecurrences();
  }

  protected recurrenceWhen(row: Recurrence): string {
    if (row.frequency === 'daily') return this.t('tkDaily');
    if (row.frequency === 'monthly') return `${this.t('tkMonthly')} · ${this.num(row.month_day ?? 1)}`;
    return `${this.t('tkWeekly')} · ${(row.weekdays ?? []).map((d) => this.t(dayKeys[d])).join('، ')}`;
  }
}
