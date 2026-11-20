import { Directive, inject, signal } from '@angular/core';
import { statusOf } from '../../core/api/api';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import { Office } from '../../data/office';
import { BoardRole, BoardType, Priority, Tasks } from '../../data/tasks';

export const typeKey: Record<BoardType, CopyKey> = {
  personal: 'tkTypePersonal',
  team: 'tkTypeTeam',
  department: 'tkTypeDepartment',
  project: 'tkTypeProject',
};

export const roleKey: Record<BoardRole, CopyKey> = {
  owner: 'tkRoleOwner',
  member: 'tkRoleMember',
  viewer: 'tkRoleViewer',
};

export const priorityKey: Record<Priority, CopyKey> = {
  low: 'tkPriorityLow',
  normal: 'tkPriorityNormal',
  high: 'tkPriorityHigh',
  urgent: 'tkPriorityUrgent',
};

export const dayKeys: CopyKey[] = ['tkDay0', 'tkDay1', 'tkDay2', 'tkDay3', 'tkDay4', 'tkDay5', 'tkDay6'];

/** The four lists a new board starts with. Their stored names are Arabic; English reads the matching label. */
const stockLists: Record<string, CopyKey> = {
  'للقيام': 'tkListTodo',
  'قيد التنفيذ': 'tkListDoing',
  'منجز': 'tkListDone',
  'منتهية': 'tkListDoneColumn',
};

/** Board and label colours. Each one keeps white text at 4.5:1 or better. The server accepts any `#rrggbb`. */
export const swatches = ['#2f6f9f', '#398256', '#9f691b', '#c0392b', '#7b5ea7', '#1d8080', '#8a6d3b', '#5f6b7a'];

/** Darkens a stored colour until white text on it reaches 4.5:1. The hue stays the same. */
export function readableBoard(color: string | null | undefined): string {
  const raw = /^#[0-9a-fA-F]{6}$/.test(color ?? '') ? (color as string) : swatches[0];
  let [r, g, b] = [1, 3, 5].map((i) => parseInt(raw.slice(i, i + 2), 16));
  const whiteOn = (rr: number, gg: number, bb: number) => {
    const channel = (c: number) => {
      const x = c / 255;
      return x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
    };
    const lum = 0.2126 * channel(rr) + 0.7152 * channel(gg) + 0.0722 * channel(bb);
    return 1.05 / (lum + 0.05);
  };
  for (let step = 0; step < 40 && whiteOn(r, g, b) < 4.6; step++) {
    r = Math.round(r * 0.96);
    g = Math.round(g * 0.96);
    b = Math.round(b * 0.96);
  }
  return `#${[r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('')}`;
}

/** Shared helpers for the task screens: wording for types, roles and priorities, and one way to run a server action. */
@Directive()
export abstract class TasksBase extends Ui {
  protected readonly tasks = inject(Tasks);
  protected readonly session = inject(Session);
  protected readonly office = inject(Office);
  protected readonly busy = signal(false);
  protected readonly swatches = swatches;
  protected readonly readable = readableBoard;

  protected get me(): number {
    return this.session.account().userId;
  }

  /** Avatar initials for a plain name (the server sends names as one string). */
  protected ini(name: string): string {
    return this.initials({ ar: name, en: name });
  }

  /** A list title. A renamed list keeps the name its owner wrote; a stock list follows the interface language. */
  protected listLabel(name: string): string {
    const key = stockLists[name];
    return key ? this.t(key) : name;
  }

  protected typeLabel(type: BoardType): string {
    return this.t(typeKey[type]);
  }

  protected roleLabel(role: BoardRole | null): string {
    return role ? this.t(roleKey[role]) : '—';
  }

  protected priorityLabel(priority: Priority | null): string {
    return this.t(priorityKey[priority ?? 'normal']);
  }

  protected today(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  }

  /** Runs one server action: shows the success notice (when given) or the server's own wording. True when it worked. */
  protected async act(run: () => Promise<unknown>, message: CopyKey | null = null): Promise<boolean> {
    const result = await this.value(async () => {
      await run();
      return true;
    }, message);
    return result === true;
  }

  /** Like `act`, for calls whose answer is needed: resolves to it, or to null when the call failed. */
  protected async value<T>(run: () => Promise<T>, message: CopyKey | null = null): Promise<T | null> {
    this.busy.set(true);
    try {
      const result = await run();
      if (message) this.office.done(message);
      return result;
    } catch (error) {
      // 409: someone changed the record first (or the board was archived). Show the server's wording and reload.
      this.office.fail(error);
      if (statusOf(error) === 409) void this.onConflict();
      return null;
    } finally {
      this.busy.set(false);
    }
  }

  /** A conflict: reload what is on screen. */
  protected async onConflict(): Promise<void> {
    // Screens that hold server state override this.
  }

  protected warn(message: CopyKey): void {
    this.office.notice.set({ message, detail: '', undo: null, error: true });
  }
}
