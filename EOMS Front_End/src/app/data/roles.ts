import { computed, inject, Injectable, signal } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem } from '../core/api/types';
import { covers, Grants, Role, Scope, scopeOrder } from '../core/access/accounts';
import { Text } from './text';

/** Shapes of `/access/catalog` and `/access/roles` (Identity AccessController). */

export interface CatalogPermission {
  name: string;
  label_ar: string;
  label_en: string;
  hint_ar: string;
  hint_en: string;
  scopes: Scope[];
  sensitive: boolean;
}

export interface CatalogModule {
  key: string;
  label_ar: string;
  label_en: string;
  permissions: CatalogPermission[];
}

export type RoleKind = 'admin' | 'standard' | 'department';

export interface RoleRow {
  name: Role;
  label_ar: string;
  label_en: string;
  hint_ar: string | null;
  hint_en: string | null;
  kind: RoleKind;
  is_default: boolean;
  version: number;
  /** Present for account and role editors only. */
  holders?: number;
  grants?: Grants;
}

export interface RoleInput {
  label_ar: string;
  label_en?: string | null;
  hint_ar?: string | null;
  hint_en?: string | null;
  grants: Grants;
}

export interface GrantGroup {
  key: string;
  label: Text;
  items: { name: string; label: Text; scope: Scope }[];
}

/**
 * The roles and the permission catalogue as the server defines them. Loaded once per session and refreshed
 * after every role edit; every screen that names a role or a permission reads its wording from here.
 */
@Injectable({ providedIn: 'root' })
export class Roles {
  private readonly api = inject(Api);

  readonly modules = signal<CatalogModule[]>([]);
  readonly roles = signal<RoleRow[]>([]);
  readonly loaded = signal(false);
  private loading: Promise<void> | null = null;

  /** Roles that can be put on an account: the department role comes from managing a department instead. */
  readonly assignable = computed(() => this.roles().filter((role) => role.kind !== 'department'));

  ensure(): Promise<void> {
    if (this.loaded()) return Promise.resolve();
    return (this.loading ??= this.reload().finally(() => (this.loading = null)));
  }

  async reload(): Promise<void> {
    const [catalog, roles] = await Promise.all([
      this.api.get<ApiItem<{ scopes: Scope[]; modules: CatalogModule[] }>>('/access/catalog'),
      this.api.get<ApiItem<RoleRow[]>>('/access/roles'),
    ]);
    this.modules.set(catalog.data.modules);
    this.roles.set(roles.data);
    this.loaded.set(true);
  }

  role(name: Role): RoleRow | undefined {
    return this.roles().find((role) => role.name === name);
  }

  label(name: Role): Text {
    const role = this.role(name);
    return role ? { ar: role.label_ar, en: role.label_en } : { ar: name, en: name };
  }

  summary(name: Role): Text | null {
    const role = this.role(name);
    return role?.hint_ar ? { ar: role.hint_ar, en: role.hint_en ?? role.hint_ar } : null;
  }

  permission(name: string): CatalogPermission | undefined {
    for (const module of this.modules()) {
      const found = module.permissions.find((item) => item.name === name);
      if (found) return found;
    }
    return undefined;
  }

  /** What several roles grant together: each permission at the widest scope among them. */
  merged(names: readonly Role[]): Grants {
    const out: Record<string, Scope> = {};
    for (const name of names) {
      for (const [permission, scope] of Object.entries(this.role(name)?.grants ?? {})) {
        if (scope && !covers(out[permission], scope)) out[permission] = scope;
      }
    }
    return out;
  }

  /** Grants grouped by module, in the catalogue's order, for read-only summaries. */
  groups(grants: Grants): GrantGroup[] {
    return this.modules()
      .map((module) => ({
        key: module.key,
        label: { ar: module.label_ar, en: module.label_en },
        items: module.permissions
          .filter((item) => grants[item.name] !== undefined)
          .map((item) => ({ name: item.name, label: { ar: item.label_ar, en: item.label_en }, scope: grants[item.name] as Scope })),
      }))
      .filter((group) => group.items.length > 0);
  }

  /** True when `mine` reaches at least as far as every grant in `wanted` (the server's escalation rule). */
  within(wanted: Grants, mine: Grants): boolean {
    return Object.entries(wanted).every(([permission, scope]) => !scope || covers(mine[permission], scope));
  }

  widest(permission: CatalogPermission): Scope {
    return [...permission.scopes].sort((a, b) => scopeOrder.indexOf(b) - scopeOrder.indexOf(a))[0] ?? 'all';
  }

  // Editing (roles.manage)

  async create(name: string, input: RoleInput): Promise<RoleRow> {
    const row = (await this.api.post<ApiItem<RoleRow>>('/access/roles', { name, ...input })).data;
    await this.reload();
    return row;
  }

  async update(role: RoleRow, input: Partial<RoleInput>): Promise<RoleRow> {
    const row = (await this.api.patch<ApiItem<RoleRow>>(`/access/roles/${role.name}`, { ...input, version: role.version })).data;
    await this.reload();
    return row;
  }

  async restore(role: RoleRow): Promise<RoleRow> {
    const row = (await this.api.post<ApiItem<RoleRow>>(`/access/roles/${role.name}/restore`, { version: role.version })).data;
    await this.reload();
    return row;
  }

  async remove(role: RoleRow): Promise<void> {
    await this.api.delete(`/access/roles/${role.name}?version=${role.version}`);
    await this.reload();
  }
}
