import { Component, computed, effect, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Role, Scope } from '../../../core/access/accounts';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Language } from '../../../core/language';
import { loginMail, loginUserRejected } from '../../../core/login-mail';
import { Office } from '../../../data/office';
import { RoleRow, Roles } from '../../../data/roles';

@Component({
  selector: 'app-access-file',
  imports: [RouterLink],
  templateUrl: './access-file.html',
  styleUrl: './access-file.scss',
})
export class AccessFile {
  protected readonly language = inject(Language);
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly catalog = inject(Roles);
  protected readonly roles = this.catalog.assignable;
  protected readonly picked = signal<Role[]>(['employee']);
  protected readonly draftName = signal('');
  protected readonly problem = signal('');
  protected readonly mailNote = signal('');
  protected readonly draftMail = signal('');
  protected readonly saving = signal(false);
  private loaded = '';
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly granted = computed(() => this.catalog.groups(this.catalog.merged(this.picked())));
  protected readonly grantedTotal = computed(() => this.granted().reduce((sum, group) => sum + group.items.length, 0));

  protected readonly user = computed(() => this.params()?.get('user') ?? '');
  protected readonly creating = computed(() => this.user() === 'new');
  protected readonly person = computed(() => this.office.accounts().find((item) => item.id === this.user()));
  protected readonly editable = computed(() => {
    const actor = this.session.account().id;
    if (this.creating()) return this.session.canAct('account.add');
    return this.office.mayEditAccount(actor, this.user());
  });

  constructor() {
    effect(() => {
      const key = this.user();
      const person = this.person();
      if (!key || key === this.loaded || (key !== 'new' && !person)) {
        return;
      }
      this.loaded = key;
      this.problem.set('');
      this.mailNote.set('');
      if (key === 'new') {
        this.picked.set(['employee']);
        this.draftName.set('');
        this.draftMail.set('');
        return;
      }
      this.picked.set([...(person?.roles ?? [])]);
      this.draftName.set(person ? this.language.text(person.name) : '');
      this.draftMail.set((person?.email ?? '').split('@')[0] ?? '');
    });
  }

  protected t(key: CopyKey): string {
    return this.language.t(key);
  }

  /**
   * Mirrors the server: the administrator role is handed out by an administrator only, and no role may carry more
   * than the signed-in account holds. A role the account already has stays ticked either way.
   */
  protected lockedRole(role: RoleRow): boolean {
    if (this.person()?.roles.includes(role.name)) return false;
    if (!this.session.has('roles.manage')) return true;
    if (role.kind === 'admin') return !this.session.isAdmin();
    return !this.catalog.within(role.grants ?? {}, this.session.grants());
  }

  protected roleText(role: RoleRow): string {
    return this.language.text({ ar: role.label_ar, en: role.label_en });
  }

  protected roleHint(role: RoleRow): string {
    const hint = this.catalog.summary(role.name);
    return hint ? this.language.text(hint) : '';
  }

  protected grantCount(role: RoleRow): number {
    return Object.keys(role.grants ?? {}).length;
  }

  protected scopeText(scope: Scope): string {
    return this.t(scope === 'all' ? 'scopeAll' : scope === 'department' ? 'scopeDepartment' : 'scopeOwn');
  }

  protected onName(event: Event): void {
    this.draftName.set((event.target as HTMLInputElement).value);
  }

  protected onMail(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.mailNote.set(loginUserRejected(value) ? this.t('mailUserInvalid') : '');
  }

  protected toggle(role: Role, event: Event): void {
    const input = event.target as HTMLInputElement;
    const current = this.picked();
    this.picked.set(input.checked ? [...current, role] : current.filter((item) => item !== role));
  }

  protected back(): void {
    void this.router.navigate([], { queryParams: { user: null }, queryParamsHandling: 'merge' });
  }

  protected async save(event: Event): Promise<void> {
    event.preventDefault();
    if (this.saving()) return;
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const entry = {
      name: this.draftName().trim(),
      email: loginMail(String(data.get('email') ?? '')),
      phone: String(data.get('phone') ?? '').trim(),
      password: String(data.get('password') ?? ''),
      roles: this.picked(),
    };
    this.problem.set('');
    if (loginUserRejected(String(data.get('email') ?? ''))) {
      this.mailNote.set(this.t('mailUserInvalid'));
      return;
    }
    if (!entry.name || !entry.email || entry.roles.length === 0) {
      return;
    }
    const actor = this.session.account().id;
    const person = this.person();
    if (!this.creating() && !person) {
      return;
    }
    this.saving.set(true);
    const outcome = this.creating() ? await this.office.addAccount(entry, actor) : await this.office.updateAccount(person!.id, entry, actor);
    this.saving.set(false);
    if (!outcome.ok) {
      this.problem.set(outcome.error ?? '');
      return;
    }
    const password = form.elements.namedItem('password');
    if (password instanceof HTMLInputElement) password.value = '';
    if (this.creating() && outcome.id) {
      void this.router.navigate([], { queryParams: { user: outcome.id }, queryParamsHandling: 'merge' });
    }
  }
}
