import { Component, computed, effect, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Session } from '../core/access/session';
import { CopyKey, ModuleId } from '../core/copy';
import { Language } from '../core/language';
import { Office } from '../data/office';
import { AccountSwitch } from './account-switch/account-switch';
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
  protected readonly icons = moduleIcon;
  private readonly office = inject(Office);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly params = toSignal(this.route.queryParamMap);

  /** Only the groups that hold at least one module this account may open. */
  protected readonly groups = computed(() =>
    navGroups.map((group) => ({ label: group.label, ids: group.ids.filter((id) => this.session.can(id)) })).filter((group) => group.ids.length > 0),
  );

  /** Items waiting for this account's decision. */
  private readonly waiting = computed(() => {
    const actor = this.session.account().id;
    return {
      leave: this.office.leaves().filter((item) => this.office.canAdvanceLeave(item.id, actor)).length,
      requests: this.office.requests().filter((item) => this.office.canDecideRequest(item.id, actor)).length,
      payroll: this.office.runs().filter((item) => this.office.runStep(item, actor) !== null && item.status !== 'draft').length,
      procurement: this.session.canAct('purchase.decide') ? this.office.purchases().filter((item) => item.status === 'pending').length : 0,
    } as Partial<Record<ModuleId, number>>;
  });

  constructor() {
    effect(() => {
      const lang = this.params()?.get('lang');
      if (lang === 'ar' || lang === 'en') {
        this.language.set(lang);
      }
    });
  }

  protected badge(id: ModuleId): string {
    const count = this.waiting()[id] ?? 0;
    return count > 0 ? this.language.formatNumber(count) : '';
  }

  protected t(key: CopyKey): string {
    return this.language.t(key);
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
