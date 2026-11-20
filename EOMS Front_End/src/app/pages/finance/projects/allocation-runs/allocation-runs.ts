import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import {
  AllocationDetail,
  AllocationDraft,
  AllocationKind,
  AllocationMethod,
  allocationMethods,
  AllocationPreview,
  AllocationRow,
  FinProjects,
} from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';

interface DraftLine {
  projectId: number;
  percentage: number | null;
  currency: string;
  amount: number | null;
}

const kindKey: Record<AllocationKind, CopyKey> = { payroll: 'fpjKindPayroll', overhead: 'fpjKindOverhead' };
const methodKey: Record<AllocationMethod, CopyKey> = {
  time_shares: 'fpjMethodTimeShares',
  percentages: 'fpjMethodPercentages',
  manual_amounts: 'fpjMethodAmounts',
  proportional_direct_cost: 'fpjMethodDirectCost',
};
const methodHint: Record<AllocationMethod, CopyKey> = {
  time_shares: 'fpjMethodTimeSharesHint',
  percentages: 'fpjMethodPercentagesHint',
  manual_amounts: 'fpjMethodAmountsHint',
  proportional_direct_cost: 'fpjMethodDirectCostHint',
};

/**
 * The month's allocations and a sheet to make one: pick payroll or overhead, a method, the lines it needs, preview
 * the result, then post it. One posted allocation per kind, month and branch; a new one needs the old one reversed.
 */
@Component({
  selector: 'app-fpj-allocation-runs',
  imports: [FormsModule, RouterLink],
  templateUrl: './allocation-runs.html',
  styleUrl: './allocation-runs.scss',
  host: { class: 'ledger' },
})
export class FpjAllocationRuns extends FinanceBase {
  private readonly api = inject(FinProjects);

  readonly year = input.required<number>();
  readonly month = input.required<number>();

  protected readonly rows = signal<AllocationRow[]>([]);
  protected readonly loading = signal(true);
  protected readonly open = signal<AllocationDetail | null>(null);
  protected readonly making = signal(false);
  protected readonly preview = signal<AllocationPreview | null>(null);
  private previewKey = '';

  protected kind: AllocationKind = 'payroll';
  protected method: AllocationMethod = 'time_shares';
  protected branchId = 0;
  protected lines: DraftLine[] = [];
  protected notes = '';

  protected readonly branches = computed(() => this.finance.branches().filter((item) => item.is_active));

  constructor() {
    super();
    effect(() => {
      const year = this.year();
      const month = this.month();
      untracked(() => {
        this.open.set(null);
        this.preview.set(null);
        void this.load(year, month);
      });
    });
  }

  private async load(year = this.year(), month = this.month()): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => this.rows.set((await this.api.allocations(year, month)).data));
    this.loading.set(false);
  }

  protected kindLabel(kind: AllocationKind): string {
    return this.t(kindKey[kind] ?? 'fpjKindPayroll');
  }

  protected methodLabel(method: AllocationMethod): string {
    return this.t(methodKey[method] ?? 'fpjMethodPercentages');
  }

  protected methodHint(): string {
    return this.t(methodHint[this.method]);
  }

  protected methods(): readonly AllocationMethod[] {
    return allocationMethods[this.kind];
  }

  protected allocTone(status: AllocationRow['status']): string {
    return status === 'approved' ? 'ok' : status === 'reversed' ? 'mute' : 'wait';
  }

  protected allocStatus(status: AllocationRow['status']): string {
    return this.statusLabel(status);
  }

  protected async toggle(row: AllocationRow): Promise<void> {
    if (this.open()?.id === row.id) {
      this.open.set(null);
      return;
    }
    await this.quiet(async () => this.open.set(await this.api.allocation(row.id)));
  }

  protected totalsOf(detail: AllocationDetail): [string, string | number][] {
    return Object.entries(detail.totals);
  }

  /** Projects the lines may use: overhead goes only to the branch's own projects. */
  protected projects() {
    const open = this.finance.openProjects();
    return this.kind === 'overhead' && this.branchId ? open.filter((item) => item.branch_id === this.branchId) : open;
  }

  protected start(): void {
    this.kind = 'payroll';
    this.method = 'time_shares';
    const branches = this.branches();
    this.branchId = (branches.find((item) => item.is_head_office) ?? branches[0])?.id ?? 0;
    this.lines = [];
    this.notes = '';
    this.preview.set(null);
    this.making.set(true);
  }

  protected pickKind(kind: AllocationKind): void {
    this.kind = kind;
    this.method = allocationMethods[kind][0];
    this.lines = [];
    this.preview.set(null);
  }

  protected pickMethod(method: AllocationMethod): void {
    this.method = method;
    if (this.needsLines() && !this.lines.length) this.addLine();
    this.preview.set(null);
  }

  protected needsLines(): boolean {
    return this.method === 'percentages' || this.method === 'manual_amounts';
  }

  protected addLine(): void {
    const currency = this.finance.activeCurrencies().find((item) => item.is_base) ?? this.finance.activeCurrencies()[0];
    this.lines = [...this.lines, { projectId: 0, percentage: null, currency: currency?.code ?? '', amount: null }];
  }

  protected removeLine(index: number): void {
    this.lines = this.lines.filter((_, i) => i !== index);
  }

  protected percentTotal(): number {
    return this.lines.reduce((sum, line) => sum + (Number(line.percentage) || 0), 0);
  }

  private draft(): AllocationDraft {
    const draft: AllocationDraft = { kind: this.kind, year: this.year(), month: this.month(), method: this.method, notes: this.notes.trim() || null };
    if (this.kind === 'overhead') draft.branch_id = this.branchId || null;
    if (this.method === 'percentages') {
      draft.lines = this.lines.filter((line) => line.projectId).map((line) => ({ project_id: line.projectId, percentage: Math.round(Number(line.percentage) * 10_000) / 10_000 }));
    } else if (this.method === 'manual_amounts') {
      draft.lines = this.lines
        .filter((line) => line.projectId)
        .map((line) => ({ project_id: line.projectId, currency: line.currency, amount: Number(line.amount) }));
    }
    return draft;
  }

  /** Posting is offered only for the exact request that was previewed. */
  protected previewed(): boolean {
    return !!this.preview() && this.previewKey === JSON.stringify(this.draft());
  }

  protected async runPreview(): Promise<void> {
    const draft = this.draft();
    this.busy.set(true);
    try {
      this.preview.set(await this.api.previewAllocation(draft));
      this.previewKey = JSON.stringify(draft);
    } catch (error) {
      this.preview.set(null);
      this.office.fail(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async post(): Promise<void> {
    if (!this.previewed() || !(await this.askConfirm({ message: 'fpjPostConfirm', confirm: 'confirmProceed' }))) return;
    const result = await this.act(() => this.api.createAllocation(this.draft()), 'fpjAllocationPosted');
    if (result) {
      this.making.set(false);
      this.preview.set(null);
      await this.load();
      this.open.set(result as AllocationDetail);
    }
  }
}
