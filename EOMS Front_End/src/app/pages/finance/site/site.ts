import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { MyCustody, FinSite } from '../../../data/finance-site';
import { Notice } from '../../../shared/notice/notice';
import { FinanceBase } from '../finance-base';
import { SiteCustody } from './site-custody/site-custody';
import { SiteInbox } from './site-inbox/site-inbox';
import { SiteMine } from './site-mine/site-mine';
import { SiteReview } from './site-review/site-review';

type View = 'mine' | 'custody' | 'inbox';

/**
 * Site requests (`/modules/finSite`), two faces on one page chosen by permission. A supervisor
 * (`finance.site_requests`) sends an expense with its invoice photo and follows "طلباتي" and "عهدتي", usually from a
 * phone on site. Finance (`finance.vouchers`) decides from "الوارد". An account with both sees all three tabs.
 */
@Component({
  selector: 'app-fin-site',
  imports: [Notice, RouterLink, SiteMine, SiteCustody, SiteInbox, SiteReview],
  templateUrl: './site.html',
  styleUrl: './site.scss',
})
export class FinSitePage extends FinanceBase {
  private readonly store = inject(FinSite);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly supervisor = computed(() => this.may('finance.site_requests'));
  protected readonly decider = computed(() => this.may('finance.vouchers'));

  protected readonly view = computed<View>(() => {
    const asked = this.params()?.get('view');
    if (asked === 'inbox' && this.decider()) return 'inbox';
    if ((asked === 'custody' || asked === 'mine') && this.supervisor()) return asked;
    return this.supervisor() ? 'mine' : 'inbox';
  });
  protected readonly id = computed(() => Number(this.params()?.get('id') ?? 0) || 0);
  /** The request sheet is open (`?new=1`). */
  protected readonly composing = computed(() => this.view() === 'mine' && this.params()?.get('new') === '1');
  protected readonly tabCount = computed(() => (this.supervisor() ? 2 : 0) + (this.decider() ? 1 : 0));

  /** The supervisor's own custody; `party` null means the account is not linked to a finance party. */
  protected readonly myCustody = signal<MyCustody | null>(null);

  constructor() {
    super();
    effect(() => {
      if (!this.supervisor()) return;
      untracked(() => void this.quiet(async () => this.myCustody.set(await this.store.myCustody())));
    });
  }
}
