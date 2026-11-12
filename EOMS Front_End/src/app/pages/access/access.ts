import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuditKind } from '../../core/access/accounts';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Language } from '../../core/language';
import { Office } from '../../data/office';
import { Roles } from '../../data/roles';
import { Notice } from '../../shared/notice/notice';
import { AccessFile } from './access-file/access-file';
import { AccessRoles } from './access-roles/access-roles';
import { AccessTable } from './access-table/access-table';

const auditKey: Record<AuditKind, CopyKey> = {
  added: 'auditAdded',
  enabled: 'auditEnabled',
  disabled: 'auditDisabled',
  updated: 'auditUpdated',
};

@Component({
  selector: 'app-access',
  imports: [Notice, RouterLink, AccessTable, AccessFile, AccessRoles],
  templateUrl: './access.html',
  styleUrl: './access.scss',
})
export class Access {
  protected readonly language = inject(Language);
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly catalog = inject(Roles);
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly user = computed(() => this.params()?.get('user') ?? '');

  /** Accounts need users.manage; the roles tab opens with either permission (read-only without roles.manage). */
  protected readonly accountsTab = computed(() => this.session.has('users.manage'));
  protected readonly tab = computed<'accounts' | 'roles'>(() =>
    this.params()?.get('tab') === 'roles' || !this.accountsTab() ? 'roles' : 'accounts',
  );
  protected readonly query = computed(() => this.params()?.get('q') ?? '');

  /** Account counts for the summary row: all accounts, active, disabled, and how many have an employee file. */
  protected readonly accountStats = computed(() => {
    const all = this.office.accounts();
    return {
      total: all.length,
      active: all.filter((item) => item.enabled).length,
      disabled: all.filter((item) => !item.enabled).length,
      linked: all.filter((item) => !!item.employeeId).length,
    };
  });

  protected readonly rows = computed(() => {
    const query = this.query().trim().toLowerCase();
    return this.office.accounts().filter((account) => {
      if (!query) return true;
      const roles = account.roles.map((role) => this.language.text(this.catalog.label(role))).join(' ');
      return `${account.name.ar} ${account.name.en} ${account.email} ${account.phone} ${roles}`.toLowerCase().includes(query);
    });
  });

  protected t(key: CopyKey): string {
    return this.language.t(key);
  }

  protected num(value: number): string {
    return this.language.formatNumber(value);
  }

  protected text(value: { ar: string; en: string }): string {
    return this.language.text(value);
  }

  protected when(at: string): string {
    return `${this.language.formatDate(at.slice(0, 10))} ${at.slice(11, 16)}`;
  }

  protected actor(id: string): string {
    const account = this.office.accounts().find((item) => item.id === id);
    return account ? this.language.text(account.name) : id;
  }

  protected verb(kind: AuditKind): string {
    return this.language.t(auditKey[kind]);
  }

  protected openNew(): void {
    void this.router.navigate([], { queryParams: { user: 'new' }, queryParamsHandling: 'merge' });
  }

  protected onSearch(event: Event): void {
    const value = (event.target as HTMLInputElement).value.trim();
    void this.router.navigate([], { queryParams: { q: value || null }, queryParamsHandling: 'merge' });
  }

  protected clearSearch(): void {
    void this.router.navigate([], { queryParams: { q: null }, queryParamsHandling: 'merge' });
  }
}
