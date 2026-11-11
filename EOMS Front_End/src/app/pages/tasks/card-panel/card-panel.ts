import { Component, computed, effect, ElementRef, input, output, signal, untracked, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { saveFile } from '../../../core/api/api';
import { CopyKey } from '../../../core/copy';
import {
  ActivityRow,
  AttachmentRow,
  BoardSnapshot,
  BoardSummary,
  CardDetail,
  CardFields,
  Checklist,
  CommentRow,
  Member,
  priorities,
  Priority,
  TaskListRow,
} from '../../../data/tasks';
import { TasksBase } from '../tasks-base';

const activityKey: Record<string, CopyKey> = {
  'card.created': 'tkActCreated',
  'card.updated': 'tkActUpdated',
  'card.moved': 'tkActMoved',
  'card.reordered': 'tkActReordered',
  'card.rejected': 'tkActRejected',
  'card.reopened': 'tkActReopened',
  'card.archived': 'tkActArchived',
  'card.restored': 'tkActRestored',
  'card.assignees': 'tkActAssignees',
  'card.labels': 'tkActLabels',
  'card.transferred_in': 'tkActTransferredIn',
  'card.transferred_out': 'tkActTransferredOut',
  'comment.added': 'tkActComment',
  'comment.edited': 'tkActComment',
  'comment.deleted': 'tkActComment',
  'attachment.added': 'tkActAttachment',
  'attachment.removed': 'tkActAttachment',
};

/**
 * The open card, as a side panel over the board: its fields, people, checklists, comments, attachments and history,
 * and the completion steps (assignee → "done", owner → confirm, send back with a reason, or reopen).
 */
@Component({
  selector: 'app-card-panel',
  imports: [FormsModule],
  templateUrl: './card-panel.html',
  styleUrl: './card-panel.scss',
  host: { class: 'ledger' },
})
export class CardPanel extends TasksBase {
  readonly cardId = input.required<number>();
  readonly board = input.required<BoardSnapshot>();
  /** Bumped by the board when someone else changed it. */
  readonly refresh = input(0);
  readonly closed = output<void>();
  readonly changed = output<void>();

  private readonly heading = viewChild<ElementRef<HTMLElement>>('heading');

  protected readonly card = signal<CardDetail | null>(null);
  protected readonly loading = signal(true);
  protected readonly priorities = priorities;

  protected readonly lists = computed(() => [...this.board().lists].sort((a, b) => Number(a.position) - Number(b.position)));
  protected readonly list = computed<TaskListRow | undefined>(() => this.lists().find((row) => row.id === this.card()?.list_id));
  protected readonly kind = computed(() => this.list()?.kind ?? 'normal');
  protected readonly boardOpen = computed(() => !this.board().board.archived_at);
  protected readonly isOwner = computed(() => this.board().role === 'owner' && this.boardOpen());
  protected readonly isAssignee = computed(() => this.card()?.assignee_ids.includes(this.me) ?? false);
  /** Members edit open cards; a confirmed card is read-only until the owner reopens it. */
  protected readonly editable = computed(
    () => this.board().role !== 'viewer' && this.boardOpen() && this.kind() !== 'finished' && !this.card()?.archived_at,
  );
  protected readonly workers = computed(() => this.board().members.filter((m) => m.role !== 'viewer'));
  protected readonly readers = computed(() => this.board().members);

  // Field drafts
  protected draft = { title: '', description: '', priority: 'normal' as Priority, start_date: '', due_date: '' };
  protected editingDescription = false;

  // Workflow
  protected rejecting = false;
  protected rejectReason = '';
  protected moveTo = 0;
  protected moveWhere: 'top' | 'bottom' = 'bottom';

  // Elsewhere
  protected readonly elsewhere = signal<'none' | 'transfer' | 'copy'>('none');
  protected readonly targets = signal<BoardSummary[]>([]);
  protected readonly targetLists = signal<TaskListRow[]>([]);
  protected targetBoard = 0;
  protected targetList = 0;
  protected copyTitle = '';

  // Checklists and comments
  protected newChecklist = '';
  protected newItem: Record<number, string> = {};
  protected commentBody = '';
  protected mentions: number[] = [];
  protected mentionPick = 0;
  protected editingComment = 0;
  protected editText = '';

  constructor() {
    super();
    effect(() => {
      const id = this.cardId();
      this.refresh();
      untracked(() => void this.load(id));
    });
  }

  protected async load(id = this.cardId()): Promise<void> {
    const first = this.card()?.id !== id;
    if (first) {
      this.loading.set(true);
      this.editingDescription = false;
    }
    try {
      this.show(await this.tasks.card(id));
      if (first) {
        this.elsewhere.set('none');
        this.rejecting = false;
        setTimeout(() => this.heading()?.nativeElement.focus());
      }
    } catch (error) {
      this.office.fail(error);
      this.closed.emit();
    } finally {
      this.loading.set(false);
    }
  }

  protected override async onConflict(): Promise<void> {
    await this.load();
  }

  private show(card: CardDetail): void {
    this.card.set(card);
    this.draft = {
      title: card.title,
      // A reload from someone else's change keeps a description being typed.
      description: this.editingDescription ? this.draft.description : (card.description ?? ''),
      priority: card.priority ?? 'normal',
      start_date: card.start_date ?? '',
      due_date: card.due_date ?? '',
    };
    this.moveTo = card.list_id;
  }

  /** A server call that returns the card: show it and let the board refresh its column. */
  private async apply(run: () => Promise<CardDetail>, message: CopyKey | null = null): Promise<boolean> {
    const card = await this.value(run, message);
    if (!card) return false;
    this.show(card);
    this.changed.emit();
    return true;
  }

  /** A call that changes part of the card: reload the card, then the board. */
  private async touch(run: () => Promise<unknown>, message: CopyKey | null = null): Promise<boolean> {
    if (!(await this.act(run, message))) return false;
    await this.load();
    this.changed.emit();
    return true;
  }

  protected member(userId: number | null): Member | undefined {
    return this.board().members.find((m) => m.user_id === userId);
  }

  protected nameOf(userId: number | null): string {
    return this.member(userId)?.name ?? '—';
  }

  protected listName(listId: unknown): string {
    return this.lists().find((row) => row.id === Number(listId))?.name ?? '—';
  }

  // ── Fields ────────────────────────────────────────────────────────────────

  protected async saveFields(fields: CardFields): Promise<void> {
    const card = this.card();
    if (!card) return;
    await this.apply(() => this.tasks.updateCard(card.id, card.version, fields));
  }

  protected saveTitle(): void {
    const title = this.draft.title.trim();
    if (!title) {
      this.draft.title = this.card()?.title ?? '';
      return;
    }
    if (title !== this.card()?.title) void this.saveFields({ title });
  }

  protected async saveDescription(): Promise<void> {
    await this.saveFields({ description: this.draft.description.trim() || null });
    this.editingDescription = false;
  }

  protected saveDates(): void {
    const card = this.card();
    const start = this.draft.start_date || null;
    const due = this.draft.due_date || null;
    if (card && (start !== card.start_date || due !== card.due_date)) void this.saveFields({ start_date: start, due_date: due });
  }

  protected toggleAssignee(userId: number): void {
    const card = this.card();
    if (!card) return;
    const ids = card.assignee_ids.includes(userId) ? card.assignee_ids.filter((id) => id !== userId) : [...card.assignee_ids, userId];
    void this.apply(() => this.tasks.setAssignees(card.id, ids));
  }

  protected toggleLabel(labelId: number): void {
    const card = this.card();
    if (!card) return;
    const ids = card.label_ids.includes(labelId) ? card.label_ids.filter((id) => id !== labelId) : [...card.label_ids, labelId];
    void this.apply(() => this.tasks.setLabels(card.id, ids));
  }

  protected async watch(): Promise<void> {
    const card = this.card();
    if (!card) return;
    const result = await this.value(() => this.tasks.watch(card.id, !card.watching));
    if (result) this.card.set({ ...card, watching: result.watching });
  }

  // ── Moving and completion ─────────────────────────────────────────────────

  /** The card's neighbours in a list, without the card itself. */
  private others(listId: number): { id: number; position: number | string }[] {
    return this.board()
      .cards.filter((row) => row.list_id === listId && row.id !== this.cardId())
      .sort((a, b) => Number(a.position) - Number(b.position));
  }

  protected async move(listId: number, where: 'top' | 'bottom'): Promise<void> {
    const rows = this.others(listId);
    const after = where === 'top' ? null : (rows[rows.length - 1]?.id ?? null);
    if (await this.act(() => this.tasks.move(this.cardId(), listId, after), 'tkMoved')) {
      await this.load();
      this.changed.emit();
    }
  }

  protected movable(row: TaskListRow): boolean {
    if (row.kind === 'finished') return this.isOwner();
    if (row.kind === 'done' || this.kind() === 'done') return this.isOwner() || this.isAssignee();
    return true;
  }

  protected markDone(): void {
    const done = this.lists().find((row) => row.kind === 'done');
    if (done) void this.move(done.id, 'bottom');
  }

  protected approve(): void {
    void this.apply(() => this.tasks.approve(this.cardId()), 'tkDone');
  }

  protected async reject(): Promise<void> {
    const reason = this.rejectReason.trim();
    if (reason.length < 3) return;
    if (await this.apply(() => this.tasks.reject(this.cardId(), reason), 'tkDone')) {
      this.rejecting = false;
      this.rejectReason = '';
    }
  }

  protected reopen(): void {
    void this.apply(() => this.tasks.reopen(this.cardId()), 'tkDone');
  }

  protected async archive(archived: boolean): Promise<void> {
    if (await this.apply(() => this.tasks.archiveCard(this.cardId(), archived), archived ? 'tkDone' : 'tkRestoredCard')) {
      if (archived) this.closed.emit();
    }
  }

  // ── Another board ─────────────────────────────────────────────────────────

  protected async startElsewhere(mode: 'transfer' | 'copy'): Promise<void> {
    if (this.elsewhere() === mode) {
      this.elsewhere.set('none');
      return;
    }
    this.elsewhere.set(mode);
    this.copyTitle = this.card()?.title ?? '';
    const boards = await this.value(() => this.tasks.boards());
    this.targets.set((boards ?? []).filter((b) => (b.role === 'owner' || b.role === 'member') && (mode === 'copy' || b.id !== this.board().board.id)));
    this.targetBoard = 0;
    this.targetList = 0;
    this.targetLists.set([]);
  }

  protected async pickBoard(id: number): Promise<void> {
    this.targetBoard = id;
    this.targetList = 0;
    this.targetLists.set([]);
    if (!id) return;
    const snapshot = await this.value(() => this.tasks.board(id));
    const rows = (snapshot?.lists ?? []).filter((row) => row.kind === 'normal').sort((a, b) => Number(a.position) - Number(b.position));
    this.targetLists.set(rows);
    this.targetList = rows[0]?.id ?? 0;
  }

  protected async sendElsewhere(): Promise<void> {
    const card = this.card();
    if (!card || !this.targetBoard || !this.targetList) return;
    if (this.elsewhere() === 'transfer') {
      if (await this.value(() => this.tasks.transfer(card.id, this.targetBoard, this.targetList), 'tkMoved')) {
        this.changed.emit();
        this.closed.emit();
      }
      return;
    }
    if (await this.value(() => this.tasks.copy(card.id, this.targetBoard, this.targetList, this.copyTitle.trim() || null), 'tkCopied')) {
      this.elsewhere.set('none');
      this.changed.emit();
    }
  }

  // ── Checklists ────────────────────────────────────────────────────────────

  protected async addChecklist(): Promise<void> {
    const title = this.newChecklist.trim();
    if (title && (await this.touch(() => this.tasks.addChecklist(this.cardId(), title)))) this.newChecklist = '';
  }

  protected deleteChecklist(list: Checklist): void {
    void this.touch(() => this.tasks.deleteChecklist(list.id));
  }

  protected async addItem(list: Checklist): Promise<void> {
    const text = (this.newItem[list.id] ?? '').trim();
    if (text && (await this.touch(() => this.tasks.addItem(list.id, text)))) this.newItem[list.id] = '';
  }

  protected toggleItem(itemId: number, done: boolean): void {
    void this.touch(() => this.tasks.updateItem(itemId, { done }));
  }

  protected deleteItem(itemId: number): void {
    void this.touch(() => this.tasks.deleteItem(itemId));
  }

  protected progress(list: Checklist): string {
    return `${this.num(list.items.filter((item) => item.done).length)}/${this.num(list.items.length)}`;
  }

  // ── Comments ──────────────────────────────────────────────────────────────

  /** Picking a reader adds "@name" to the comment and records the mention the server notifies. */
  protected mention(userId: number): void {
    this.mentionPick = 0;
    const person = this.member(Number(userId));
    if (!person) return;
    if (!this.mentions.includes(person.user_id)) this.mentions = [...this.mentions, person.user_id];
    this.commentBody = `${this.commentBody}${this.commentBody && !this.commentBody.endsWith(' ') ? ' ' : ''}@${person.name} `;
  }

  protected async comment(): Promise<void> {
    const body = this.commentBody.trim();
    if (!body) return;
    // A mention whose name was deleted from the text is dropped.
    const mentions = this.mentions.filter((id) => body.includes(`@${this.nameOf(id)}`));
    if (await this.touch(() => this.tasks.comment(this.cardId(), body, mentions))) {
      this.commentBody = '';
      this.mentions = [];
    }
  }

  protected startEdit(row: CommentRow): void {
    this.editingComment = row.id;
    this.editText = row.body;
  }

  protected async saveEdit(row: CommentRow): Promise<void> {
    const body = this.editText.trim();
    if (!body) return;
    if (await this.touch(() => this.tasks.editComment(row.id, body))) this.editingComment = 0;
  }

  protected deleteComment(row: CommentRow): void {
    void this.touch(() => this.tasks.deleteComment(row.id));
  }

  // ── Attachments ───────────────────────────────────────────────────────────

  protected async upload(event: Event): Promise<void> {
    const field = event.target as HTMLInputElement;
    const file = field.files?.[0];
    field.value = '';
    if (!file) return;
    if (file.size > 25 * 1024 * 1024) {
      this.warn('tkAttachHint');
      return;
    }
    await this.touch(() => this.tasks.attach(this.cardId(), file), 'tkDone');
  }

  protected async download(row: AttachmentRow): Promise<void> {
    const blob = await this.value(() => this.tasks.download(row.id));
    if (blob) saveFile(blob, row.original_name);
  }

  protected detach(row: AttachmentRow): void {
    void this.touch(() => this.tasks.detach(row.id));
  }

  protected size(bytes: number): string {
    if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }

  // ── History ───────────────────────────────────────────────────────────────

  protected activityLine(row: ActivityRow): string {
    if (row.action === 'card.moved' && row.data?.['kind'] === 'finished') return this.t('tkActFinished');
    const key = activityKey[row.action] ?? (row.action.startsWith('checklist.') ? 'tkActChecklist' : 'tkActOther');
    const target = row.action === 'card.moved' ? ` «${this.listName(row.data?.['to'])}»` : '';
    const reason = row.action === 'card.rejected' && row.data?.['reason'] ? `: ${String(row.data['reason'])}` : '';
    return this.t(key) + target + reason;
  }

  protected warningName(userId: number): string {
    return this.nameOf(userId);
  }
}
