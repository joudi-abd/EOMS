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

/** The colours offered for boards and labels; the server accepts any `#rrggbb`. */
export const swatches = ['#2f6f9f', '#3f8f5f', '#b7791f', '#c0392b', '#7b5ea7', '#1f8a8a', '#8a6d3b', '#5f6b7a'];

/** Shared helpers for the task screens: wording for types, roles and priorities, and one way to run a server action. */
@Directive()
export abstract class TasksBase extends Ui {
  protected readonly tasks = inject(Tasks);
  protected readonly session = inject(Session);
  protected readonly office = inject(Office);
  protected readonly busy = signal(false);
  protected readonly swatches = swatches;

  protected get me(): number {
    return this.session.account().userId;
  }

  /** Avatar initials for a plain name (the server sends names as one string). */
  protected ini(name: string): string {
    return this.initials({ ar: name, en: name });
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
