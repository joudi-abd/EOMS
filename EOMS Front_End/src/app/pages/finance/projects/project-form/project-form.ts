import { ChangeDetectorRef, Component, computed, inject, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ContractType, ProjectStatus, today } from '../../../../data/finance';
import { contractTypes, FinProjects, ProjectDraft, ProjectFile, projectStatuses } from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';
import { CopyKey } from '../../../../core/copy';

const typeHints: Record<ContractType, CopyKey> = {
  lump_sum: 'fpjTypeHintLump',
  cost_plus: 'fpjTypeHintCostPlus',
  percentage_of_receipts: 'fpjTypeHintReceipts',
  internal: 'fpjTypeHintInternal',
};

/**
 * The project working sheet: a new project (with its general project) or changes to one. The contract type decides
 * which fields show: lump sum takes a value, cost plus and percentage of receipts take a fee, internal takes no client.
 * After the first approved billing the contract type, fee, currency and client are locked (R14).
 */
@Component({
  selector: 'app-fpj-project-form',
  imports: [FormsModule],
  templateUrl: './project-form.html',
  styleUrl: './project-form.scss',
  host: { class: 'ledger' },
})
export class FpjProjectForm extends FinanceBase implements OnInit {
  private readonly api = inject(FinProjects);
  private readonly cdr = inject(ChangeDetectorRef);

  /** The project to change; none creates a new one. */
  readonly project = input<ProjectFile | null>(null);
  readonly saved = output<number>();
  readonly closed = output<void>();

  protected readonly types = contractTypes;
  protected readonly statuses = projectStatuses;
  protected readonly more = signal(false);
  protected readonly locked = computed(() => !!this.project()?.billing_locked);

  protected name = '';
  protected clientId = 0;
  protected type: ContractType = 'lump_sum';
  protected currencyId = 0;
  protected value: number | null = null;
  protected fee: number | null = null;
  protected branchId = 0;
  protected managerId = 0;
  protected startDate = today();
  protected status: ProjectStatus = 'active';
  protected endDate = '';
  protected notes = '';
  protected feeBase: number[] = [];

  /** People with a login account, who can manage a project. */
  protected readonly managers = computed(() =>
    this.office.employees().filter((item) => item.userId && item.status !== 'ended').map((item) => ({ id: Number(item.userId), name: this.text(item.name) })),
  );

  protected readonly clients = computed(() => this.finance.partiesWith('client'));
  protected readonly billable = computed(() => this.finance.activeCostCategories().filter((item) => item.is_billable));

  async ngOnInit(): Promise<void> {
    const project = this.project();
    if (project) {
      this.name = project.name;
      this.clientId = project.client_party_id ?? 0;
      this.type = project.contract_type;
      this.currencyId = project.contract_currency_id ?? 0;
      this.value = project.contract_value === null ? null : Number(project.contract_value);
      this.fee = project.fee_pct === null ? null : Number(project.fee_pct);
      this.branchId = project.branch_id ?? 0;
      this.managerId = project.manager_user_id ?? 0;
      this.startDate = project.start_date ?? '';
      this.endDate = project.end_date ?? '';
      this.notes = (project.project?.notes as string | null) ?? '';
      this.feeBase = [...(project.fee_base_category_ids ?? [])];
      this.more.set(!!(this.endDate || this.notes || this.feeBase.length));
      return;
    }
    await this.quiet(async () => {
      await Promise.all([this.finance.loadCurrencies(), this.finance.loadBranches()]);
    });
    const currencies = this.finance.activeCurrencies();
    this.currencyId = (currencies.find((item) => item.is_base) ?? currencies[0])?.id ?? 0;
    const branches = this.finance.branches().filter((item) => item.is_active);
    this.branchId = (branches.find((item) => item.is_head_office) ?? branches[0])?.id ?? 0;
    // Zoneless: these plain fields changed after an await, so ask for a repaint (the selects show the defaults).
    this.cdr.markForCheck();
  }

  protected pickType(type: ContractType): void {
    if (this.locked()) return;
    this.type = type;
  }

  protected typeHint(): CopyKey {
    return typeHints[this.type];
  }

  protected needsValue(): boolean {
    return this.type === 'lump_sum';
  }

  protected needsFee(): boolean {
    return this.type === 'cost_plus' || this.type === 'percentage_of_receipts';
  }

  protected toggleBase(id: number, on: boolean): void {
    this.feeBase = on ? [...this.feeBase, id] : this.feeBase.filter((item) => item !== id);
  }

  protected async submit(): Promise<void> {
    const current = this.project();
    const draft: ProjectDraft = {
      name: this.name.trim(),
      branch_id: this.branchId || null,
      manager_user_id: this.managerId || null,
      start_date: this.startDate || null,
      end_date: this.endDate || null,
      notes: this.notes.trim() || null,
      contract_value: this.needsValue() ? this.value : null,
    };
    if (!this.locked()) {
      draft.contract_type = this.type;
      draft.contract_currency_id = this.currencyId;
      draft.client_party_id = this.type === 'internal' ? null : this.clientId || null;
      draft.fee_pct = this.needsFee() ? this.fee : null;
    }
    draft.fee_base_category_ids = this.type === 'cost_plus' && this.feeBase.length ? this.feeBase : null;
    if (!current) draft.status = this.status;
    const result = await this.act(() => (current ? this.api.update(current.project_id, draft) : this.api.create(draft)), 'finSaved');
    if (result) this.saved.emit(current?.project_id ?? (result as { project_id: number }).project_id);
  }
}
