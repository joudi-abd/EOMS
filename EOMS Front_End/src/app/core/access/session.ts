import { computed, inject, Injectable } from '@angular/core';
import { Auth } from '../api/auth';
import { ModuleId, moduleIds } from '../copy';
import { Language } from '../language';
import { Office } from '../../data/office';
import { Roles } from '../../data/roles';
import { Account, Action, adminRole, areaNeeds, areaReach, covers, grants, Grants, holds, Permission, Scope, selfAreas } from './accounts';

const nobody: Account = { id: '', userId: 0, name: { ar: '', en: '' }, roles: [], enabled: false, email: '', phone: '', lastLogin: '' };

@Injectable({ providedIn: 'root' })
export class Session {
  private readonly office = inject(Office);
  private readonly auth = inject(Auth);
  private readonly language = inject(Language);
  private readonly catalog = inject(Roles);

  /** The signed-in account, as the backend reports it. */
  readonly account = computed<Account>(() => this.office.self() ?? nobody);

  /** The signed-in account has an open employee file, so it reaches its own attendance, leave, and requests. */
  readonly hasFile = computed(() => this.office.employees().some((item) => item.id === this.account().id && item.status !== 'ended'));

  readonly manages = computed(() => this.office.team(this.account().id).length > 0);

  /**
   * What the backend lets this account do — permission → widest scope — exactly as `/auth/me` reports it
   * (roles plus the departments the account manages). Never rebuilt here.
   */
  readonly grants = computed<Grants>(() => this.auth.user()?.permissions ?? {});

  /** Permissions held company-wide. */
  readonly permissions = computed(() => new Set(Object.keys(this.grants()).filter((name) => this.grants()[name] === 'all')));

  readonly isAdmin = computed(() => this.account().roles.includes(adminRole));

  readonly areas = computed(() => {
    const areas = new Set<ModuleId>();
    if (this.account().roles.length === 0) {
      return areas;
    }
    for (const id of moduleIds) {
      const needs = areaNeeds[id];
      if (needs === 'open' || holds(this.grants(), needs, areaReach[id] ?? 'all')) {
        areas.add(id);
      }
    }
    if (this.hasFile()) {
      for (const area of selfAreas) {
        areas.add(area);
      }
    }
    return areas;
  });

  readonly modules = computed(() => moduleIds.filter((id) => this.areas().has(id)));

  can(area: ModuleId): boolean {
    return this.areas().has(area);
  }

  /** The signed-in account holds this permission company-wide. Use `reach` for department or own scope. */
  has(permission: Permission): boolean {
    return covers(this.grants()[permission], 'all');
  }

  /** How far the account holds this permission, or null when it does not hold it at all. */
  reach(permission: Permission): Scope | null {
    return this.grants()[permission] ?? null;
  }

  canAct(action: Action): boolean {
    return grants(this.grants(), action, this.hasFile());
  }

  readonly isDirector = computed(() => this.office.isDirector(this.account().id));

  /** The signed-in account may change this person's record. */
  mayEdit(targetId: string, action: Action): boolean {
    return this.office.mayEditFile(this.account().id, targetId, action);
  }

  mayManage(targetId: string): boolean {
    return this.office.mayManage(this.account().id, targetId);
  }

  logout(): Promise<void> {
    return this.auth.logout();
  }

  roleLine(): string {
    const names = this.account().roles.map((role) => this.language.text(this.catalog.label(role)));
    return this.language.lang() === 'ar' ? names.join(' و ') : names.join(', ');
  }
}
