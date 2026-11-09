import { Component, computed, effect, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Session } from '../core/access/session';
import { CopyKey, ModuleId } from '../core/copy';
import { Language } from '../core/language';
import { AccountSwitch } from './account-switch/account-switch';
import { Server } from '../data/server';
import { Chrome } from './chrome';
import { moduleIcon } from './icons';

const navGroups: { label: CopyKey; ids: ModuleId[] }[] = [
  { label: 'navPeople', ids: ['employees', 'attendance', 'leave', 'requests', 'payroll', 'hrSettings'] },
  { label: 'navWork', ids: ['projects', 'clients', 'procurement', 'documents', 'assets', 'meetings'] },
  { label: 'navMoney', ids: ['finance'] },
  { label: 'navSystem', ids: ['access', 'alerts'] },
];

@Component({
  selector: 'app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, AccountSwitch],
  templateUrl: './shell.html',
  styleUrl: './shell.scss',
})
export class Shell {
  protected readonly language = inject(Language);
  protected readonly chrome = inject(Chrome);
  protected readonly session = inject(Session);
  protected readonly notes = inject(Server);
  protected readonly company = signal('');
  protected readonly icons = moduleIcon;
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly params = toSignal(this.route.queryParamMap);

  /** Only the groups that hold at least one module this account may open. */
  protected readonly groups = computed(() =>
    navGroups.map((group) => ({ label: group.label, ids: group.ids.filter((id) => this.session.can(id)) })).filter((group) => group.ids.length > 0),
  );

  constructor() {
    void this.notes.notifications(1).catch(() => undefined);
    void this.notes.publicCompany().then((name) => this.company.set(name)).catch(() => undefined);
    effect(() => {
      const lang = this.params()?.get('lang');
      if (lang === 'ar' || lang === 'en') {
        this.language.set(lang);
      }
    });
  }

  protected t(key: CopyKey): string {
    return this.language.t(key);
  }

  /** Two initials for the account avatar; the Arabic article is not a name's first letter. */
  protected initials(): string {
    return this.language
      .text(this.session.account().name)
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((word) => word.replace(/^ال/, '').charAt(0))
      .join(' ');
  }

  /** The top search looks up employees by name, number or national id on the employees page. */
  protected search(event: Event): void {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const input = form.elements.namedItem('q') as HTMLInputElement | null;
    const q = input?.value.trim() ?? '';
    void this.router.navigate(['/modules/employees'], { queryParams: q ? { q } : {} });
    if (input) input.value = '';
    this.chrome.close();
  }

  protected toggleLanguage(): void {
    const next = this.language.lang() === 'ar' ? 'en' : 'ar';
    this.language.set(next);
    void this.router.navigate([], {
      queryParams: { lang: next },
      queryParamsHandling: 'merge',
    });
  }
}
