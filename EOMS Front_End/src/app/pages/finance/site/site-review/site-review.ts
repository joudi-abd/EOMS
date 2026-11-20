import { Component, computed, effect, inject, input, OnDestroy, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Attachment, today } from '../../../../data/finance';
import { FinSite, SiteRequest } from '../../../../data/finance-site';
import { FinanceBase } from '../../finance-base';

/**
 * One site request for finance: its facts and the invoice (an image inline, a PDF as a download), then approve
 * (optional date) or reject (reason). Approving posts a custody settlement or a debt to the supervisor (R25).
 */
@Component({
  selector: 'app-fin-site-review',
  imports: [FormsModule, RouterLink],
  templateUrl: './site-review.html',
  styleUrl: './site-review.scss',
  host: { class: 'ledger' },
})
export class SiteReview extends FinanceBase implements OnDestroy {
  private readonly store = inject(FinSite);

  readonly id = input.required<number>();

  protected readonly request = signal<SiteRequest | null>(null);
  protected readonly loading = signal(true);
  /** Object URLs of the image attachments, by attachment id. */
  protected readonly images = signal<Record<number, string>>({});
  protected readonly rejecting = signal(false);
  protected entryDate = today();
  protected reason = '';

  protected readonly mayDecide = computed(() => this.may('finance.vouchers') && this.request()?.status === 'submitted');

  constructor() {
    super();
    void this.quiet(() => this.finance.loadCurrencies());
    effect(() => {
      const id = this.id();
      untracked(() => void this.load(id));
    });
  }

  ngOnDestroy(): void {
    this.dropImages();
  }

  private async load(id: number): Promise<void> {
    this.loading.set(true);
    this.rejecting.set(false);
    this.dropImages();
    await this.quiet(async () => {
      const request = await this.store.request(id);
      this.request.set(request);
      await this.loadImages(request);
    });
    this.loading.set(false);
  }

  private async loadImages(request: SiteRequest): Promise<void> {
    const out: Record<number, string> = {};
    for (const file of request.attachments ?? []) {
      if (!this.isImage(file)) continue;
      const blob = await this.store.attachment(request.id, file.id);
      out[file.id] = URL.createObjectURL(blob);
    }
    this.images.set(out);
  }

  private dropImages(): void {
    for (const url of Object.values(this.images())) URL.revokeObjectURL(url);
    this.images.set({});
  }

  protected isImage(file: Attachment): boolean {
    return (file.mime ?? '').startsWith('image/') || /\.(jpe?g|png|webp)$/i.test(file.original_name);
  }

  protected async download(file: Attachment): Promise<void> {
    const request = this.request();
    if (!request) return;
    await this.quiet(async () => this.saveFile(await this.store.attachment(request.id, file.id), file.original_name));
  }

  protected async approve(): Promise<void> {
    const request = this.request();
    if (!request) return;
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', confirm: 'confirmProceed' }))) return;
    const result = await this.act(() => this.store.approve(request.id, this.entryDate), 'fsrApproved');
    if (result) this.request.set({ ...request, ...result });
  }

  protected async reject(): Promise<void> {
    const request = this.request();
    if (!request) return;
    if (this.reason.trim().length < 3) {
      this.warn('fsrReasonNeeded');
      return;
    }
    const result = await this.act(() => this.store.reject(request.id, this.reason.trim()), 'fsrRejected');
    if (result) {
      this.request.set({ ...request, ...result });
      this.rejecting.set(false);
    }
  }

  protected sourceLabel(request: SiteRequest): string {
    return this.t(request.payment_source === 'custody' ? 'fsrSourceCustody' : 'fsrSourceOwn');
  }
}
