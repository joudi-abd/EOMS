import { Component, computed, effect, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { isTop, Role, roleIds, roleLabel } from '../../../core/access/accounts';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Language } from '../../../core/language';
import { Office } from '../../../data/office';

@Component({
  selector: 'app-access-file',
  templateUrl: './access-file.html',
  styleUrl: './access-file.scss',
})
export class AccessFile {
  protected readonly language = inject(Language);
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly roles = roleIds;
  protected readonly roleLabel = roleLabel;
  protected readonly picked = signal<Role[]>(['employee']);
  protected readonly draftName = signal('');
  protected readonly limit = signal(false);
  protected readonly problem = signal('');
  protected readonly saving = signal(false);
  private loaded = '';
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

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
      this.limit.set(false);
      this.problem.set('');
      if (key === 'new') {
        this.picked.set(['employee']);
        this.draftName.set('');
        return;
      }
      this.picked.set([...(person?.roles ?? [])]);
      this.draftName.set(person ? this.language.text(person.name) : '');
    });
  }

  protected t(key: CopyKey): string {
    return this.language.t(key);
  }

  /** Only a top account hands out a top role. */
  protected lockedRole(role: Role): boolean {
    return isTop([role]) && !this.session.isDirector();
  }

  protected onName(event: Event): void {
    this.draftName.set((event.target as HTMLInputElement).value);
  }

  protected toggle(role: Role, event: Event): void {
    const input = event.target as HTMLInputElement;
    const current = this.picked();
    if (input.checked) {
      if (current.length >= 2) {
        input.checked = false;
        this.limit.set(true);
        return;
      }
      this.limit.set(false);
      this.picked.set([...current, role]);
      return;
    }
    this.limit.set(false);
    this.picked.set(current.filter((item) => item !== role));
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
      email: String(data.get('email') ?? '').trim(),
      phone: String(data.get('phone') ?? '').trim(),
      password: String(data.get('password') ?? ''),
      roles: this.picked(),
    };
    this.problem.set('');
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
