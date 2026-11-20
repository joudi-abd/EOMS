import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Notice } from '../../../shared/notice/notice';
import { FinanceBase } from '../finance-base';
import { BoxList } from './box-list/box-list';
import { CashCounts } from './cash-counts/cash-counts';

/**
 * Finance area: boxes. Two views on the `view` query param: the boxes with their balances (default; `?id=` opens a
 * box's statement in place) and the cash counts (`?view=counts`, `&id=` opens a count). `?new=box|count` opens the
 * matching sheet, so the heading's buttons and other areas can link straight to it.
 */
@Component({
  selector: 'app-fin-boxes',
  imports: [Notice, RouterLink, BoxList, CashCounts],
  templateUrl: './boxes.html',
  styleUrl: './boxes.scss',
})
export class FinBoxesPage extends FinanceBase {
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly view = computed<'boxes' | 'counts'>(() => (this.params()?.get('view') === 'counts' ? 'counts' : 'boxes'));
}
