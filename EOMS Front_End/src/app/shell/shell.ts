import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Session } from '../core/access/session';
import { CopyKey, ModuleId } from '../core/copy';
import { Language } from '../core/language';
import { AccountSwitch } from './account-switch/account-switch';
import { Api } from '../core/api/api';
import { ApiPage } from '../core/api/types';
import { Server } from '../data/server';
import { Chrome } from './chrome';
import { moduleIcon } from './icons';

const navGroups: { label: CopyKey; ids: ModuleId[] }[] = [
  { label: 'navPeople', ids: ['employees', 'attendance', 'leave', 'requests', 'payroll', 'hrSettings'] },
  { label: 'navWork', ids: ['tasks', 'projects', 'clients', 'procurement', 'documents', 'assets', 'meetings'] },
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
  private readonly api = inject(Api);
  /** Items waiting for this account's decision, shown as a count beside the module in the rail. */
  protected readonly waiting = signal<Partial<Record<ModuleId, number>>>({});
  private readonly route = inject(ActivatedRoute);
  private readonly params = toSignal(this.route.queryParamMap);

  /** Only the groups that hold at least one module this account may open. */
  protected readonly groups = computed(() =>
    navGroups.map((group) => ({ label: group.label, ids: group.ids.filter((id) => this.session.can(id)) })).filter((group) => group.ids.length > 0),
  );

  constructor() {
    void this.notes.notifications(1).catch(() => undefined);
    void this.notes.publicCompany().then((name) => this.company.set(name)).catch(() => undefined);
    // The account loads after the shell; count waiting items once per signed-in account.
    let countedFor = '';
    effect(() => {
      const id = this.session.account().id;
      if (!id || id === countedFor) return;
      countedFor = id;
      untracked(() => void this.loadWaiting());
    });
    effect(() => {
      const lang = this.params()?.get('lang');
      if (lang === 'ar' || lang === 'en') {
        this.language.set(lang);
      }
    });
  }

  /**
   * Counts what waits on this account: leave requests in its review queue, pay runs submitted for approval, and
   * finance transactions and invoices above the approval limit. Each count is one page-1 request read for its total;
   * a count the account may not see is simply left out.
   */
  private async loadWaiting(): Promise<void> {
    const total = async (path: string, params: Record<string, string | number>): Promise<number> => {
      try {
        return (await this.api.get<ApiPage<unknown>>(path, params)).meta?.total ?? 0;
      } catch {
        return 0;
      }
    };
    const counts: Partial<Record<ModuleId, number>> = {};
    const jobs: Promise<void>[] = [];
    // The review queue belongs to HR and the company manager only; the server refuses it to anyone else (403),
    // even a system administrator who holds leaves.approve.
    const roles = this.session.account().roles;
    if (this.session.has('leaves.approve') && (roles.includes('hr') || roles.includes('company'))) {
      jobs.push(total('/leaves', { scope: 'review', status: 'pending' }).then((value) => void (counts.leave = value)));
    }
    if (this.session.has('payroll.approve')) {
      jobs.push(total('/payroll/runs', { status: 'submitted' }).then((value) => void (counts.payroll = value)));
    }
    if (this.session.has('finance.approve')) {
      jobs.push(
        Promise.all([total('/finance/transactions', { status: 'pending' }), total('/finance/invoices', { status: 'pending' })]).then(
          ([transactions, invoices]) => void (counts.finance = transactions + invoices),
        ),
      );
    }
    await Promise.all(jobs);
    this.waiting.set(counts);
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
      .join('\u200c');
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
