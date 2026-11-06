import { computed, inject, Injectable } from '@angular/core';
import { Auth } from '../api/auth';
import { ModuleId, moduleIds } from '../copy';
import { Language } from '../language';
import { Office } from '../../data/office';
import { Account, Action, areaNeeds, grants, holds, Permission, permissionsOf, roleLabel, selfAreas } from './accounts';

const nobody: Account = { id: '', userId: 0, name: { ar: '', en: '' }, roles: [], enabled: false, email: '', phone: '', lastLogin: '' };

@Injectable({ providedIn: 'root' })
export class Session {
  private readonly office = inject(Office);
  private readonly auth = inject(Auth);
  private readonly language = inject(Language);

  /** The signed-in account, as the backend reports it. */
  readonly account = computed<Account>(() => this.office.self() ?? nobody);

  /** The signed-in account has an open employee file, so it reaches its own attendance, leave, and requests. */
  readonly hasFile = computed(() => this.office.employees().some((item) => item.id === this.account().id && item.status !== 'ended'));

  readonly manages = computed(() => this.office.team(this.account().id).length > 0);

  /** What the backend lets this account do, rebuilt from its roles because `/auth/me` returns role names only. */
  readonly permissions = computed(() => permissionsOf(this.account().roles));

  readonly areas = computed(() => {
    const roles = this.account().roles;
    const areas = new Set<ModuleId>();
    if (roles.length === 0) {
      return areas;
    }
    for (const id of moduleIds) {
      const needs = areaNeeds[id];
      if (needs === 'open' || holds(roles, needs)) {
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

  /** The signed-in account holds this backend permission. */
  has(permission: Permission): boolean {
    return this.permissions().has(permission);
  }

  canAct(action: Action): boolean {
    return grants(this.account().roles, action, this.hasFile());
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
    const names = this.account().roles.map((role) => this.language.t(roleLabel[role]));
    return this.language.lang() === 'ar' ? names.join(' و ') : names.join(', ');
  }
}
