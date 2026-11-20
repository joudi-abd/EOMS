import { ChangeDetectorRef, Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { amount, BoxType, boxTypes, FinBox } from '../../../../data/finance';
import { BoxCurrencyDraft, BoxDraft, FinBoxesApi, loginPeople } from '../../../../data/finance-boxes';
import { FinanceBase } from '../../finance-base';

/**
 * The box sheet (`finance.settings`): name, type, branch and the currencies the box holds, each with its negative
 * setting; the custodian and contact sit behind "more details". Creates (POST) or changes (PATCH) the box.
 */
@Component({
  selector: 'app-fin-box-form',
  imports: [FormsModule],
  templateUrl: './box-form.html',
  styleUrl: './box-form.scss',
})
export class BoxForm extends FinanceBase implements OnInit {
  readonly box = input<FinBox | null>(null);
  readonly saved = output<FinBox>();
  readonly closed = output<void>();

  private readonly boxesApi = inject(FinBoxesApi);
  protected readonly types = boxTypes;
  protected readonly more = signal(false);
  protected readonly people = computed(() => loginPeople(this.office));
  protected readonly branches = computed(() => this.finance.branches().filter((item) => item.is_active || item.id === this.branchId));

  protected name = '';
  protected type: BoxType = 'cash_safe';
  protected branchId = 0;
  protected custodian = 0;
  protected contact = '';
  protected rows: BoxCurrencyDraft[] = [];
  private readonly cdr = inject(ChangeDetectorRef);

  ngOnInit(): void {
    const box = this.box();
    if (box) {
      this.name = box.name;
      this.type = box.type;
      this.branchId = box.branch_id;
      this.custodian = box.custodian_user_id ?? 0;
      this.contact = box.contact_info ?? '';
      this.rows = box.currencies.map((row) => ({
        currency_id: row.currency_id,
        allow_negative: row.allow_negative,
        negative_limit: row.negative_limit === null ? null : Number(row.negative_limit),
      }));
      this.more.set(!!(box.custodian_user_id || box.contact_info));
    } else {
      // Opened by a deep link, the lookups may still be loading: fill the defaults once they arrive.
      void Promise.all([this.finance.loadBranches(), this.finance.loadCurrencies()])
        .then(() => {
          this.fillDefaults();
          this.cdr.markForCheck();
        })
        .catch(() => undefined);
      this.fillDefaults();
    }
  }

  /** A new box starts in the head office with the base currency, unless the person already chose. */
  private fillDefaults(): void {
    if (!this.branchId) {
      const active = this.finance.branches().filter((item) => item.is_active);
      this.branchId = (active.find((item) => item.is_head_office) ?? active[0])?.id ?? 0;
    }
    if (!this.rows.length) {
      const currencies = this.finance.activeCurrencies();
      const first = currencies.find((item) => item.is_base) ?? currencies[0];
      this.rows = first ? [{ currency_id: first.id, allow_negative: false, negative_limit: null }] : [];
    }
  }

  /** The saved custodian is someone this account cannot list: keep them as a choice so saving does not clear them. */
  protected unlisted(): boolean {
    return !!this.custodian && !this.people().some((item) => item.userId === this.custodian);
  }

  /** Currencies a row may pick: active ones not taken by another row. */
  protected choices(row: BoxCurrencyDraft) {
    const taken = new Set(this.rows.filter((item) => item !== row).map((item) => item.currency_id));
    return this.finance.currencies().filter((item) => (item.is_active || item.id === row.currency_id) && !taken.has(item.id));
  }

  protected canAdd(): boolean {
    return this.finance.activeCurrencies().some((item) => !this.rows.some((row) => row.currency_id === item.id));
  }

  protected addRow(): void {
    const next = this.finance.activeCurrencies().find((item) => !this.rows.some((row) => row.currency_id === item.id));
    if (next) this.rows = [...this.rows, { currency_id: next.id, allow_negative: false, negative_limit: null }];
  }

  /** A saved currency that still holds a balance: the server refuses to remove it, so it can be neither removed nor swapped. */
  protected held(row: BoxCurrencyDraft): boolean {
    return !!this.box()?.currencies.some((item) => item.currency_id === row.currency_id && amount(item.balance) !== 0);
  }

  protected removeRow(row: BoxCurrencyDraft): void {
    this.rows = this.rows.filter((item) => item !== row);
  }

  protected async submit(): Promise<void> {
    if (!this.rows.length) {
      this.warn('fbxNeedCurrency');
      return;
    }
    const draft: BoxDraft = {
      name: this.name.trim(),
      type: this.type,
      branch_id: this.branchId,
      custodian_user_id: this.custodian || null,
      contact_info: this.contact.trim() || null,
      currencies: this.rows.map((row) => ({
        currency_id: row.currency_id,
        allow_negative: row.allow_negative,
        negative_limit: row.allow_negative && row.negative_limit !== null && `${row.negative_limit}` !== '' ? Number(row.negative_limit) : null,
      })),
    };
    const box = this.box();
    const result = await this.act(() => (box ? this.boxesApi.updateBox(box.id, draft) : this.boxesApi.createBox(draft)), 'finSaved');
    if (result) this.saved.emit(result);
  }
}
