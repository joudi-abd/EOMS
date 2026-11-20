import { ChangeDetectorRef, Component, computed, inject, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { today } from '../../../../data/finance';
import { CashCount, CountDraft, FinBoxesApi } from '../../../../data/finance-boxes';
import { FinanceBase } from '../../finance-base';

interface Row {
  currency_id: number;
  code: string;
  counted: number | null;
  explanation: string;
}

/**
 * The count sheet (`finance.counts`): the box, the day, and a row per currency enabled on the box with what is
 * actually in it. The system balance is not shown here: it is captured when the count is sent (blind count).
 * "Save and send" creates (or updates) the draft and submits it in one go.
 */
@Component({
  selector: 'app-fin-count-form',
  imports: [FormsModule],
  templateUrl: './count-form.html',
  styleUrl: './count-form.scss',
})
export class CountForm extends FinanceBase implements OnInit {
  readonly count = input<CashCount | null>(null);
  readonly saved = output<CashCount>();
  readonly closed = output<void>();

  private readonly boxesApi = inject(FinBoxesApi);
  private readonly cdr = inject(ChangeDetectorRef);
  /** Active boxes, plus the draft's own box should it have been deactivated since (the choice is locked on a draft). */
  protected readonly boxes = computed(() => {
    const own = this.count()?.cash_box_id;
    return this.finance.boxes().filter((box) => box.is_active || box.id === own);
  });
  protected readonly more = signal(false);
  protected readonly maxDate = today();

  private createdId: number | null = null;
  protected boxId = 0;
  protected countDate = today();
  protected notes = '';
  protected rows: Row[] = [];

  ngOnInit(): void {
    const count = this.count();
    if (count) {
      this.boxId = count.cash_box_id;
      this.countDate = count.count_date.slice(0, 10);
      this.notes = count.notes ?? '';
      this.more.set(!!count.notes);
      this.pickBox();
      for (const line of count.lines) {
        const row = this.rows.find((item) => item.currency_id === line.currency_id);
        if (row) {
          row.counted = Number(line.counted_amount);
          row.explanation = line.explanation ?? '';
        }
      }
    } else {
      // Opened by a deep link, the boxes may still be loading: pick the only box once they arrive.
      void this.finance
        .loadBoxes()
        .then(() => {
          const only = this.boxes();
          if (!this.boxId && only.length === 1) {
            this.boxId = only[0].id;
            this.pickBox();
            this.cdr.markForCheck();
          }
        })
        .catch(() => undefined);
    }
  }

  /** A row per currency enabled on the chosen box. */
  protected pickBox(): void {
    const box = this.finance.box(this.boxId);
    this.rows = (box?.currencies ?? []).map((row) => ({ currency_id: row.currency_id, code: row.code, counted: null, explanation: '' }));
  }

  private draft(): CountDraft | null {
    const lines = this.rows
      .filter((row) => row.counted !== null && `${row.counted}` !== '')
      .map((row) => ({ currency_id: row.currency_id, counted_amount: Number(row.counted), explanation: row.explanation.trim() || null }));
    if (!this.boxId || !lines.length) {
      this.warn('fbxNeedCounted');
      return null;
    }
    return { cash_box_id: this.boxId, count_date: this.countDate, notes: this.notes.trim() || null, lines };
  }

  protected async store(send: boolean): Promise<void> {
    const draft = this.draft();
    if (!draft) return;
    const existing = this.count()?.id ?? this.createdId;
    const result = await this.act(async () => {
      const saved = existing ? await this.boxesApi.updateCount(existing, draft) : await this.boxesApi.createCount(draft);
      // A draft created before a failed send is updated on the next try, never duplicated.
      this.createdId = saved.id;
      return send ? this.boxesApi.submitCount(saved.id) : saved;
    }, send ? 'fbxCountSent' : 'finSaved');
    if (result) this.saved.emit(result);
  }
}
