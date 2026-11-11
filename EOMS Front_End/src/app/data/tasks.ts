import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem } from '../core/api/types';

/** Shapes returned by `/api/v1/tasks` (BoardController, CardController and TaskQueries). Dates are `Y-m-d`. */

export type BoardType = 'personal' | 'team' | 'department' | 'project';
export type BoardRole = 'owner' | 'member' | 'viewer';
export type ListKind = 'normal' | 'done' | 'finished';
export type Priority = 'low' | 'normal' | 'high' | 'urgent';
export type Frequency = 'daily' | 'weekly' | 'monthly';

export const priorities: Priority[] = ['low', 'normal', 'high', 'urgent'];
export const roles: BoardRole[] = ['owner', 'member', 'viewer'];

export interface BoardSummary {
  id: number;
  type: BoardType;
  name: string;
  description: string | null;
  color: string | null;
  department_id: number | null;
  archived_at: string | null;
  version: number;
  role: BoardRole | null;
  open_cards: number;
  overdue_cards: number;
}

export interface BoardOptions {
  types: BoardType[];
  departments: { id: number; name: string }[];
}

export interface Person {
  user_id: number;
  name: string;
  department_id: number | null;
  department_name: string | null;
}

export interface Member {
  user_id: number;
  name: string;
  role: BoardRole;
  /** A department board's staff and manager, who are there without an explicit row. */
  implicit: boolean;
  employee_id: number | null;
  department_id: number | null;
}

export interface Label {
  id: number;
  name: string;
  color: string;
}

export interface TaskListRow {
  id: number;
  name: string;
  kind: ListKind;
  position: number | string;
}

export interface CardSummary {
  id: number;
  board_id: number;
  list_id: number;
  title: string;
  priority: Priority;
  start_date: string | null;
  due_date: string | null;
  position: number | string;
  done_at: string | null;
  completed_at: string | null;
  recurrence_id: number | null;
  version: number;
  assignee_ids: number[];
  label_ids: number[];
  checklist: { done: number; total: number };
  comments: number;
  attachments: number;
  has_description: boolean;
}

export interface BoardSnapshot {
  board: Omit<BoardSummary, 'role' | 'open_cards' | 'overdue_cards'> & { created_by: number };
  role: BoardRole;
  members: Member[];
  labels: Label[];
  lists: TaskListRow[];
  cards: CardSummary[];
  cursor: number;
  today: string;
}

export interface ChecklistItem {
  id: number;
  text: string;
  done: boolean;
  done_by: number | null;
  done_at: string | null;
}

export interface Checklist {
  id: number;
  title: string;
  items: ChecklistItem[];
}

export interface CommentRow {
  id: number;
  user_id: number;
  user_name: string | null;
  body: string;
  mentions: number[] | null;
  edited_at: string | null;
  created_at: string;
}

export interface AttachmentRow {
  id: number;
  original_name: string;
  mime: string | null;
  size: number;
  uploaded_by: number;
  created_at: string;
}

export interface ActivityRow {
  id: number;
  action: string;
  data: Record<string, unknown> | null;
  user_id: number | null;
  user_name: string | null;
  created_at: string;
}

export interface CardWarning {
  user_id: number;
  name?: string;
  [key: string]: unknown;
}

export interface CardDetail extends Omit<CardSummary, 'comments' | 'attachments'> {
  description: string | null;
  previous_list_id: number | null;
  done_by: number | null;
  completed_by: number | null;
  created_by: number;
  created_at: string;
  archived_at: string | null;
  watcher_ids: number[];
  watching: boolean;
  checklists: Checklist[];
  comments: CommentRow[];
  attachments: AttachmentRow[];
  activity: ActivityRow[];
  recurrence: Recurrence | null;
  warnings: CardWarning[];
}

export interface Change {
  id: number;
  card_id: number | null;
  action: string;
  user_id: number | null;
  created_at: string;
}

export interface Changes {
  cursor: number;
  changes: Change[];
  /** False when more than one page changed: reload the whole board. */
  complete: boolean;
}

export interface RecurrenceTemplate {
  title: string;
  description?: string | null;
  priority?: Priority | null;
  assignee_ids?: number[];
  label_ids?: number[];
  checklist?: { title: string; items?: string[] }[];
}

export interface Recurrence {
  id: number;
  board_id?: number;
  list_id?: number;
  template?: RecurrenceTemplate;
  frequency: Frequency;
  weekdays: number[] | null;
  month_day: number | null;
  due_in_days: number | null;
  next_run_on: string | null;
  last_run_on?: string | null;
  active: boolean;
}

export interface RecurrenceInput {
  list_id: number;
  frequency: Frequency;
  weekdays?: number[] | null;
  month_day?: number | null;
  due_in_days?: number | null;
  starts_on?: string | null;
  template: RecurrenceTemplate;
}

export interface MyCard extends CardSummary {
  board: { id: number; name: string; type: BoardType; color: string | null };
  list: { id: number; name: string; kind: ListKind };
}

export interface MyCardsFilter {
  state?: 'open' | 'finished' | 'all';
  due?: 'overdue' | 'soon' | 'none';
  board_id?: number;
  priority?: Priority;
}

export interface ReportPerson {
  user_id: number;
  name: string | null;
  open: number;
  overdue: number;
  awaiting_review: number;
  finished: number;
  on_time: number;
  on_time_rate: number | null;
  avg_days: number | null;
}

export interface TaskReport {
  from: string;
  to: string;
  today: string;
  people: ReportPerson[];
  totals: { open: number; overdue: number; finished: number; on_time: number };
}

export interface CardFields {
  title?: string;
  description?: string | null;
  priority?: Priority | null;
  start_date?: string | null;
  due_date?: string | null;
}

/** Calls for the task boards. Every call returns the unwrapped `data`. */
@Injectable({ providedIn: 'root' })
export class Tasks {
  private readonly api = inject(Api);

  private async read<T>(path: string, params: Record<string, string | number> = {}): Promise<T> {
    return (await this.api.get<ApiItem<T>>('/tasks' + path, params)).data;
  }

  private async send<T>(method: 'post' | 'put' | 'patch', path: string, body: unknown = {}): Promise<T> {
    return (await this.api[method]<ApiItem<T>>('/tasks' + path, body)).data;
  }

  // Boards
  boards(archived = false): Promise<BoardSummary[]> {
    return this.read('/boards', archived ? { archived: 1 } : {});
  }
  options(): Promise<BoardOptions> {
    return this.read('/boards/options');
  }
  people(q = ''): Promise<Person[]> {
    return this.read('/people', q ? { q } : {});
  }
  createBoard(body: {
    type: BoardType;
    name: string;
    description?: string | null;
    color?: string | null;
    department_id?: number | null;
    members?: { user_id: number; role: BoardRole }[];
  }): Promise<BoardSnapshot> {
    return this.send('post', '/boards', body);
  }
  board(id: number): Promise<BoardSnapshot> {
    return this.read(`/boards/${id}`);
  }
  changes(id: number, since: number): Promise<Changes> {
    return this.read(`/boards/${id}/changes`, { since });
  }
  updateBoard(id: number, body: { name?: string; description?: string | null; color?: string | null }): Promise<unknown> {
    return this.send('patch', `/boards/${id}`, body);
  }
  archiveBoard(id: number, archived: boolean): Promise<unknown> {
    return this.send('post', `/boards/${id}/archive`, { archived });
  }
  archived(id: number): Promise<{ lists: (TaskListRow & { archived_at: string })[]; cards: CardSummary[] }> {
    return this.read(`/boards/${id}/archived`);
  }

  // Members
  addMember(boardId: number, userId: number, role: BoardRole): Promise<unknown> {
    return this.send('post', `/boards/${boardId}/members`, { user_id: userId, role });
  }
  changeRole(boardId: number, userId: number, role: BoardRole): Promise<unknown> {
    return this.send('patch', `/boards/${boardId}/members/${userId}`, { role });
  }
  removeMember(boardId: number, userId: number): Promise<unknown> {
    return this.api.delete(`/tasks/boards/${boardId}/members/${userId}`);
  }

  // Lists and labels
  createList(boardId: number, name: string): Promise<TaskListRow> {
    return this.send('post', `/boards/${boardId}/lists`, { name });
  }
  updateList(id: number, body: { name?: string; position?: number }): Promise<TaskListRow> {
    return this.send('patch', `/lists/${id}`, body);
  }
  archiveList(id: number, archived: boolean): Promise<unknown> {
    return this.send('post', `/lists/${id}/archive`, { archived });
  }
  createLabel(boardId: number, name: string, color: string): Promise<Label> {
    return this.send('post', `/boards/${boardId}/labels`, { name, color });
  }
  updateLabel(id: number, body: { name?: string; color?: string }): Promise<Label> {
    return this.send('patch', `/labels/${id}`, body);
  }
  deleteLabel(id: number): Promise<unknown> {
    return this.api.delete(`/tasks/labels/${id}`);
  }

  // Recurring cards
  recurrences(boardId: number): Promise<Recurrence[]> {
    return this.read(`/boards/${boardId}/recurrences`);
  }
  createRecurrence(boardId: number, body: RecurrenceInput): Promise<Recurrence> {
    return this.send('post', `/boards/${boardId}/recurrences`, body);
  }
  stopRecurrence(id: number): Promise<unknown> {
    return this.send('post', `/recurrences/${id}/stop`);
  }

  // Cards
  createCard(boardId: number, body: CardFields & { list_id: number; title: string; assignee_ids?: number[]; label_ids?: number[] }): Promise<CardDetail> {
    return this.send('post', `/boards/${boardId}/cards`, body);
  }
  card(id: number): Promise<CardDetail> {
    return this.read(`/cards/${id}`);
  }
  updateCard(id: number, version: number, body: CardFields): Promise<CardDetail> {
    return this.send('patch', `/cards/${id}`, { ...body, version });
  }
  setAssignees(id: number, userIds: number[]): Promise<CardDetail> {
    return this.send('put', `/cards/${id}/assignees`, { user_ids: userIds });
  }
  setLabels(id: number, labelIds: number[]): Promise<CardDetail> {
    return this.send('put', `/cards/${id}/labels`, { label_ids: labelIds });
  }
  move(id: number, listId: number, afterCardId: number | null): Promise<CardSummary> {
    return this.send('post', `/cards/${id}/move`, { list_id: listId, after_card_id: afterCardId });
  }
  approve(id: number): Promise<CardDetail> {
    return this.send('post', `/cards/${id}/approve`);
  }
  reject(id: number, reason: string): Promise<CardDetail> {
    return this.send('post', `/cards/${id}/reject`, { reason });
  }
  reopen(id: number): Promise<CardDetail> {
    return this.send('post', `/cards/${id}/reopen`);
  }
  archiveCard(id: number, archived: boolean): Promise<CardDetail> {
    return this.send('post', `/cards/${id}/archive`, { archived });
  }
  watch(id: number, watching: boolean): Promise<{ watching: boolean }> {
    return this.send('post', `/cards/${id}/watch`, { watching });
  }
  transfer(id: number, boardId: number, listId: number): Promise<CardDetail> {
    return this.send('post', `/cards/${id}/transfer`, { board_id: boardId, list_id: listId });
  }
  copy(id: number, boardId: number, listId: number, title: string | null): Promise<CardDetail> {
    return this.send('post', `/cards/${id}/copy`, { board_id: boardId, list_id: listId, title });
  }

  // Checklists
  addChecklist(cardId: number, title: string): Promise<unknown> {
    return this.send('post', `/cards/${cardId}/checklists`, { title });
  }
  deleteChecklist(id: number): Promise<unknown> {
    return this.api.delete(`/tasks/checklists/${id}`);
  }
  addItem(checklistId: number, text: string): Promise<unknown> {
    return this.send('post', `/checklists/${checklistId}/items`, { text });
  }
  updateItem(id: number, body: { text?: string; done?: boolean }): Promise<unknown> {
    return this.send('patch', `/checklist-items/${id}`, body);
  }
  deleteItem(id: number): Promise<unknown> {
    return this.api.delete(`/tasks/checklist-items/${id}`);
  }

  // Comments and attachments
  comment(cardId: number, body: string, mentions: number[]): Promise<CommentRow> {
    return this.send('post', `/cards/${cardId}/comments`, { body, mentions });
  }
  editComment(id: number, body: string): Promise<unknown> {
    return this.send('patch', `/comments/${id}`, { body });
  }
  deleteComment(id: number): Promise<unknown> {
    return this.api.delete(`/tasks/comments/${id}`);
  }
  attach(cardId: number, file: File): Promise<AttachmentRow> {
    const form = new FormData();
    form.append('file', file);
    return this.send('post', `/cards/${cardId}/attachments`, form);
  }
  download(id: number): Promise<Blob> {
    return this.api.blob(`/tasks/attachments/${id}`);
  }
  detach(id: number): Promise<unknown> {
    return this.api.delete(`/tasks/attachments/${id}`);
  }

  // Across boards
  mine(filter: MyCardsFilter): Promise<{ today: string; cards: MyCard[] }> {
    const params: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(filter)) if (value !== undefined && value !== null && value !== '') params[key] = value as string | number;
    return this.read('/my-cards', params);
  }
  report(params: { from: string; to: string; board_id?: number; department_id?: number }): Promise<TaskReport> {
    const query: Record<string, string | number> = { from: params.from, to: params.to };
    if (params.board_id) query['board_id'] = params.board_id;
    if (params.department_id) query['department_id'] = params.department_id;
    return this.read('/reports', query);
  }
}
