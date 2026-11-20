import { Component, computed, inject, input, OnDestroy, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { amount, Money } from '../../../../data/finance';
import { FinSite, MyCustody, PaymentSource, SiteRequest } from '../../../../data/finance-site';
import { FinanceBase } from '../../finance-base';

interface Option {
  id: number;
  name: string;
}

const maxBytes = 10 * 1024 * 1024;
const allowed = /\.(jpe?g|png|webp|pdf)$/i;

/**
 * The supervisor's request sheet: project, what for, amount and currency, paid from (custody or own money), a short
 * description and the invoice photo. Phone first: one column, large targets, a camera button beside the file picker.
 *
 * A supervisor holding only `finance.site_requests` may not read `/finance/projects`, `/finance/project-cost-categories`
 * or `/finance/parties`, so the choices also come from what the account already sees: its own past requests and
 * its custodies.
 */
@Component({
  selector: 'app-fin-site-request-form',
  imports: [FormsModule],
  templateUrl: './site-request-form.html',
  styleUrl: './site-request-form.scss',
})
export class SiteRequestForm extends FinanceBase implements OnInit, OnDestroy {
  private readonly store = inject(FinSite);

  readonly myCustody = input<MyCustody | null>(null);
  readonly saved = output<SiteRequest>();
  readonly closed = output<void>();

  private readonly history = signal<SiteRequest[]>([]);
  protected readonly ready = signal(false);
  /** The server's wording when the account is not linked to a finance party (422 on `requested_by`). */
  protected readonly unlinked = signal('');
  protected readonly file = signal<File | null>(null);
  protected readonly preview = signal<string | null>(null);

  protected projectId = 0;
  protected categoryId = 0;
  protected amountValue: number | null = null;
  protected currency = '';
  protected source: PaymentSource = 'custody';
  protected supplierId = 0;
  protected description = '';

  protected readonly projects = computed<Option[]>(() => {
    const out = new Map<number, string>();
    for (const project of this.finance.openProjects()) out.set(project.project_id, this.finance.projectName(project.project_id));
    for (const custody of this.myCustody()?.custodies ?? []) {
      if (custody.project_id && custody.project_name && !out.has(custody.project_id)) out.set(custody.project_id, custody.project_name);
    }
    for (const row of this.history()) {
      if (row.project_name && !out.has(row.project_id)) out.set(row.project_id, row.project_name);
    }
    return [...out].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  });

  protected readonly categories = computed<Option[]>(() => {
    const out = new Map<number, string>();
    for (const category of this.finance.activeCostCategories()) out.set(category.id, category.name);
    for (const row of this.history()) {
      if (row.cost_category_name && !out.has(row.project_cost_category_id)) out.set(row.project_cost_category_id, row.cost_category_name);
    }
    return [...out].map(([id, name]) => ({ id, name }));
  });

  protected readonly suppliers = computed(() => this.finance.partiesWith('supplier'));
  protected readonly currencies = computed(() => this.finance.activeCurrencies());

  /** Open custody balance per currency code. */
  private readonly balances = computed(() => new Map((this.myCustody()?.balances ?? []).map((item) => [item.currency, item.balance])));

  protected custodyBalance(): Money | null {
    const balance = this.balances().get(this.currency);
    return balance !== undefined && amount(balance) > 0 ? balance : null;
  }

  async ngOnInit(): Promise<void> {
    const jobs: Promise<unknown>[] = [this.finance.loadCurrencies()];
    if (this.may('finance.projects.view')) jobs.push(this.finance.loadProjects(), this.finance.loadCostCategories());
    if (this.may('finance.view')) jobs.push(this.finance.loadParties());
    jobs.push(this.store.mine({ per_page: 100 }).then((page) => this.history.set(page.data)));
    await this.quiet(() => Promise.all(jobs));
    this.defaults();
    this.ready.set(true);
  }

  ngOnDestroy(): void {
    this.dropPreview();
  }

  /** The only project and category, and the currency of the custody the supervisor holds (else the first). */
  private defaults(): void {
    if (this.projects().length === 1) this.projectId = this.projects()[0].id;
    if (this.categories().length === 1) this.categoryId = this.categories()[0].id;
    const held = this.myCustody()?.balances.find((item) => amount(item.balance) > 0)?.currency;
    this.currency = held ?? this.currencies().find((item) => item.is_base)?.code ?? this.currencies()[0]?.code ?? '';
    this.source = held ? 'custody' : 'own_money';
  }

  protected pick(event: Event): void {
    const target = event.target as HTMLInputElement;
    const chosen = target.files?.[0] ?? null;
    target.value = '';
    if (!chosen) return;
    if (!allowed.test(chosen.name) && !/^image\/(jpeg|png|webp)$/.test(chosen.type) && chosen.type !== 'application/pdf') {
      this.warn('fsrBadFile');
      return;
    }
    if (chosen.size > maxBytes) {
      this.warn('fsrTooBig');
      return;
    }
    this.dropPreview();
    this.file.set(chosen);
    if (chosen.type.startsWith('image/')) this.preview.set(URL.createObjectURL(chosen));
  }

  private dropPreview(): void {
    const url = this.preview();
    if (url) URL.revokeObjectURL(url);
    this.preview.set(null);
  }

  protected async submit(): Promise<void> {
    const invoice = this.file();
    if (!this.projectId || !this.categoryId || !this.currency || !this.description.trim() || !(Number(this.amountValue) > 0)) {
      this.warn('fsrFillAll');
      return;
    }
    if (!invoice) {
      this.warn('fsrInvoiceNeeded');
      return;
    }
    this.busy.set(true);
    this.unlinked.set('');
    try {
      const result = await this.store.submit({
        project_id: this.projectId,
        project_cost_category_id: this.categoryId,
        amount: Number(this.amountValue),
        currency: this.currency,
        supplier_party_id: this.supplierId || null,
        description: this.description.trim(),
        payment_source: this.source,
        invoice,
      });
      this.office.done('fsrSent');
      this.saved.emit(result);
    } catch (error) {
      const errors = error instanceof HttpErrorResponse ? (error.error as { errors?: Record<string, string[]> } | null)?.errors : undefined;
      const message = errors?.['requested_by']?.[0];
      if (message) this.unlinked.set(message);
      this.office.fail(error);
    } finally {
      this.busy.set(false);
    }
  }
}
