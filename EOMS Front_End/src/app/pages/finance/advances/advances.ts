import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Notice } from '../../../shared/notice/notice';
import { FinanceBase } from '../finance-base';
import { AdvanceDetail } from './advance-detail/advance-detail';
import { AdvanceList } from './advance-list/advance-list';
import { CustodyDetail } from './custody-detail/custody-detail';
import { CustodyList } from './custody-list/custody-list';

/**
 * Advances and custody (`/modules/finAdvances`): employee advances with their monthly installments, and the open
 * custodies with their settlements. Reading here; new advances and every custody movement are vouchers, so the
 * actions open the voucher form already filled.
 */
@Component({
  selector: 'app-fin-advances',
  imports: [Notice, RouterLink, AdvanceList, AdvanceDetail, CustodyList, CustodyDetail],
  templateUrl: './advances.html',
  styleUrl: './advances.scss',
})
export class FinAdvancesPage extends FinanceBase {
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly view = computed<'advances' | 'custody'>(() => (this.params()?.get('view') === 'custody' ? 'custody' : 'advances'));
  /** The record opened in place (`?id=`). */
  protected readonly id = computed(() => Number(this.params()?.get('id') ?? 0) || 0);

  protected readonly allowed = computed(() => this.may('finance.view'));
  protected readonly mayVoucher = computed(() => this.may('finance.vouchers'));
}
