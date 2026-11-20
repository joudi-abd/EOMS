import { Component, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Attachment, Money } from '../../../../data/finance';
import { CashCount, FinBoxesApi, loginPeople } from '../../../../data/finance-boxes';
import { FinanceBase } from '../../finance-base';

type Step = 'none' | 'reject' | 'delete';

/**
 * One cash count opened in place: per currency what was counted, the system balance captured on sending and the
 * difference (shortage red, overage amber). A draft is sent, changed (by its counter) or deleted; a sent count is
 * approved or rejected by someone with `finance.counts.approve` who did not count it (the server enforces it; the
 * buttons hide for the counter). A stale capture is refreshed before approval. Attachments: the cash sheet photo.
 */
@Component({
  selector: 'app-fin-count-detail',
  imports: [FormsModule, RouterLink],
  templateUrl: './count-detail.html',
  styleUrl: './count-detail.scss',
})
export class CountDetail extends FinanceBase {
  readonly countId = input.required<number>();
  readonly changed = output<void>();
  readonly edit = output<CashCount>();
  readonly removed = output<void>();

  private readonly boxesApi = inject(FinBoxesApi);
  protected readonly count = signal<CashCount | null>(null);
  protected readonly step = signal<Step>('none');
  protected reason = '';

  protected readonly mine = computed(() => this.count()?.counted_by === this.session.account().userId);
  protected readonly captured = computed(() => (this.count()?.status ?? 'draft') !== 'draft');

  constructor() {
    super();
    effect(() => {
      const id = this.countId();
      untracked(() => {
        this.step.set('none');
        void this.load(id);
      });
    });
  }

  private async load(id: number): Promise<void> {
    try {
      this.count.set(await this.boxesApi.count(id));
    } catch (error) {
      this.count.set(null);
      this.office.fail(error);
    }
  }

  private async run(job: () => Promise<CashCount>, message: 'fbxCountSent' | 'fbxResnapshotted' | 'fbxCountApproved' | 'fbxCountRejected'): Promise<void> {
    const result = await this.act(job, message);
    if (result) {
      this.count.set(result);
      this.step.set('none');
      this.reason = '';
      this.changed.emit();
    }
  }

  protected async submit(count: CashCount): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    return this.run(() => this.boxesApi.submitCount(count.id), 'fbxCountSent');
  }

  protected async resnapshot(count: CashCount): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    return this.run(() => this.boxesApi.resnapshotCount(count.id), 'fbxResnapshotted');
  }

  protected async approve(count: CashCount): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    return this.run(() => this.boxesApi.approveCount(count.id), 'fbxCountApproved');
  }

  protected reject(count: CashCount): Promise<void> {
    return this.run(() => this.boxesApi.rejectCount(count.id, this.reason.trim()), 'fbxCountRejected');
  }

  protected async remove(count: CashCount): Promise<void> {
    const done = await this.act(() => this.boxesApi.deleteCount(count.id), 'finDeleted');
    if (done) this.removed.emit();
  }

  protected async attach(count: CashCount, event: Event): Promise<void> {
    const field = event.target as HTMLInputElement;
    const file = field.files?.[0];
    field.value = '';
    if (!file) return;
    const done = await this.act(() => this.boxesApi.attachToCount(count.id, file), 'fbxAttached');
    if (done) await this.load(count.id);
  }

  protected async download(count: CashCount, file: Attachment): Promise<void> {
    await this.quiet(async () => this.saveFile(await this.boxesApi.countAttachment(count.id, file.id), file.original_name));
  }

  protected code(currencyId: number): string {
    return this.finance.code(currencyId) || this.finance.box(this.count()?.cash_box_id)?.currencies.find((row) => row.currency_id === currencyId)?.code || '';
  }

  protected who(userId: number | null): string {
    if (!userId) return '—';
    if (userId === this.session.account().userId) return this.t('fbxYou');
    const found = loginPeople(this.office).find((item) => item.userId === userId);
    return found ? this.text(found.name) : '—';
  }

  /** Shortage red, overage amber, a match green. */
  protected diffTone(value: Money | null): string {
    if (value === null) return 'mute';
    const n = Number(value);
    return n < 0 ? 'late' : n > 0 ? 'wait' : 'ok';
  }

  protected diffLabel(value: Money | null): string {
    if (value === null) return '';
    const n = Number(value);
    return this.t(n < 0 ? 'fbxShortage' : n > 0 ? 'fbxOverage' : 'fbxMatch');
  }
}
