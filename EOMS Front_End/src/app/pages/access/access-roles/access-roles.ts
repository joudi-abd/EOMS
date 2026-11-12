import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { covers, Grants, Scope } from '../../../core/access/accounts';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { CatalogPermission, RoleRow, Roles } from '../../../data/roles';

const scopeKey: Record<Scope, CopyKey> = { all: 'scopeAll', department: 'scopeDepartment', own: 'scopeOwn' };

/**
 * The roles screen: every role, and for the picked one its permissions with a reach each. What the server
 * refuses is shown as unavailable here too — the admin role is locked, the department role never goes past
 * the department, and nobody grants more than they hold.
 */
@Component({
  selector: 'app-access-roles',
  imports: [FormsModule],
  templateUrl: './access-roles.html',
  styleUrl: './access-roles.scss',
  host: { class: 'ledger' },
})
export class AccessRoles extends Ui {
  protected readonly catalog = inject(Roles);
  protected readonly session = inject(Session);
  private readonly office = inject(Office);

  protected readonly picked = signal<string>('');
  protected readonly creating = signal(false);
  protected readonly busy = signal(false);

  protected readonly role = computed(() => this.catalog.role(this.picked()));
  protected readonly editor = computed(() => this.session.has('roles.manage'));
  protected readonly editable = computed(() => this.editor() && !!this.role() && this.role()?.kind !== 'admin');

  /** The form: labels and the grants being edited. */
  protected form = { label_ar: '', label_en: '', hint_ar: '', hint_en: '' };
  protected readonly draft = signal<Record<string, Scope>>({});
  protected newRole = { name: '', label_ar: '', label_en: '', copy: '' };

  protected readonly count = computed(() => Object.keys(this.draft()).length);

  constructor() {
    super();
    void this.catalog.reload().catch((error) => this.office.fail(error));
    effect(() => {
      const roles = this.catalog.roles();
      untracked(() => {
        if (!this.picked() && roles.length) this.pick(roles[0]);
      });
    });
  }

  protected pick(role: RoleRow): void {
    this.creating.set(false);
    this.picked.set(role.name);
    this.form = { label_ar: role.label_ar, label_en: role.label_en, hint_ar: role.hint_ar ?? '', hint_en: role.hint_en ?? '' };
    this.draft.set({ ...(role.grants ?? {}) } as Record<string, Scope>);
  }

  protected kindLabel(role: RoleRow): string {
    if (role.kind === 'admin') return this.t('rlKindAdmin');
    if (role.kind === 'department') return this.t('rlKindDepartment');
    return role.is_default ? this.t('rlDefault') : this.t('rlCustom');
  }

  protected scopeLabel(scope: Scope): string {
    return this.t(scopeKey[scope]);
  }

  /** The reaches offered for a permission on the picked role (the department role stops at department). */
  protected scopes(permission: CatalogPermission): Scope[] {
    const list = [...permission.scopes].reverse(); // narrowest first, matching the reading order
    return this.role()?.kind === 'department' ? list.filter((scope) => scope !== 'all') : list;
  }

  /** Mirrors the server: a grant beyond the editor's own reach is not offered, unless the role already has it. */
  protected reachable(permission: string, scope: Scope): boolean {
    if (!this.editable()) return false;
    const before = this.role()?.grants?.[permission];
    if (before && covers(before, scope)) return true;
    return covers(this.session.grants()[permission], scope);
  }

  /** Taking a grant away also needs the editor to hold it. */
  protected removable(permission: string): boolean {
    const current = this.role()?.grants?.[permission];
    return !current || covers(this.session.grants()[permission], current);
  }

  protected set(permission: string, scope: Scope | null): void {
    this.draft.update((grants) => {
      const next = { ...grants };
      if (scope) next[permission] = scope;
      else delete next[permission];
      return next;
    });
  }

  protected async save(): Promise<void> {
    const role = this.role();
    if (!role || !this.form.label_ar.trim()) return;
    await this.run(async () => {
      const saved = await this.catalog.update(role, {
        label_ar: this.form.label_ar.trim(),
        label_en: this.form.label_en.trim() || null,
        hint_ar: this.form.hint_ar.trim() || null,
        hint_en: this.form.hint_en.trim() || null,
        grants: this.draft() as Grants,
      });
      this.pick(saved);
    }, 'rlSaved');
  }

  protected async restore(): Promise<void> {
    const role = this.role();
    if (!role) return;
    await this.run(async () => this.pick(await this.catalog.restore(role)), 'rlRestored');
  }

  protected async remove(): Promise<void> {
    const role = this.role();
    if (!role) return;
    await this.run(async () => {
      await this.catalog.remove(role);
      this.picked.set('');
      const first = this.catalog.roles()[0];
      if (first) this.pick(first);
    }, 'rlDeleted');
  }

  protected startCreate(): void {
    this.newRole = { name: '', label_ar: '', label_en: '', copy: '' };
    this.creating.set(true);
  }

  protected async create(): Promise<void> {
    const name = this.newRole.name.trim();
    if (!/^[a-z][a-z0-9_]*$/.test(name) || !this.newRole.label_ar.trim()) return;
    const source = this.catalog.role(this.newRole.copy);
    // Copy only what the editor may give; the server would refuse the rest.
    const grants = Object.fromEntries(
      Object.entries(source?.grants ?? {}).filter(([permission, scope]) => scope && covers(this.session.grants()[permission], scope)),
    ) as Grants;
    await this.run(async () => {
      const created = await this.catalog.create(name, { label_ar: this.newRole.label_ar.trim(), label_en: this.newRole.label_en.trim() || null, grants });
      this.pick(created);
    }, 'rlCreated');
  }

  private async run(job: () => Promise<void>, done: CopyKey): Promise<void> {
    this.busy.set(true);
    try {
      await job();
      this.office.done(done);
    } catch (error) {
      this.office.fail(error);
      // A conflict means someone else changed the role: show theirs.
      await this.catalog.reload().catch(() => undefined);
      const role = this.role();
      if (role) this.pick(role);
    } finally {
      this.busy.set(false);
    }
  }
}
