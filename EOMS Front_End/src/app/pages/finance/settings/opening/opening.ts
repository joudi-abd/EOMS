import { Component, computed, inject, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CopyKey } from '../../../../core/copy';
import { FinBox, today } from '../../../../data/finance';
import { AgreementRef, Direction, FinSettingsApi, OpeningDraft, OpeningResult } from '../../../../data/finance-settings';
import { FinanceBase } from '../../finance-base';

type Section = 'boxes' | 'projects' | 'agreements' | 'parties' | 'employee_advances' | 'custodies' | 'partners';

interface BoxRow { box: number | null; currency: string; amount: number | null }
interface ProjectRow { project: number | null; currency: string; receipts: number | null; billed: number | null }
interface CostRow { project: number | null; category: number | null; amount: number | null }
interface AgreementRow { agreement: number | null; paid: number | null }
interface OwedRow { party: number | null; currency: string; direction: Direction; amount: number | null }
interface AdvanceRow { employee: number | null; currency: string; remaining: number | null; count: number | null; start: string }
interface CustodyRow { holder: number | null; project: number | null; currency: string; balance: number | null; opened: string }

const sectionTitle: Record<Section, CopyKey> = {
  boxes: 'fstObBoxes',
  projects: 'fstObProjects',
  agreements: 'fstObAgreements',
  parties: 'fstObParties',
  employee_advances: 'fstObAdvances',
  custodies: 'fstObCustodies',
  partners: 'fstObPartners',
};

const sectionHint: Record<Section, CopyKey> = {
  boxes: 'fstObBoxesHint',
  projects: 'fstObProjectsHint',
  agreements: 'fstObAgreementsHint',
  parties: 'fstObPartiesHint',
  employee_advances: 'fstObAdvancesHint',
  custodies: 'fstObCustodiesHint',
  partners: 'fstObPartnersHint',
};

/**
 * Setup step 7 (`POST /finance/setup/opening-balances`): one branch's balances on the start date, as a guided sheet.
 * Every section starts collapsed and is a small line table; only the sections with lines are sent. The server posts
 * them in one approved opening voucher and returns the opening check, which the setup tab then shows.
 */
@Component({
  selector: 'app-fst-opening',
  imports: [FormsModule],
  templateUrl: './opening.html',
  styleUrl: './opening.scss',
  host: { class: 'ledger' },
})
export class FstOpening extends FinanceBase implements OnInit {
  private readonly settings = inject(FinSettingsApi);

  readonly closed = output<void>();
  readonly saved = output<OpeningResult>();

  protected readonly sections: readonly Section[] = ['boxes', 'projects', 'agreements', 'parties', 'employee_advances', 'custodies', 'partners'];
  protected readonly sectionTitle = sectionTitle;
  protected readonly sectionHint = sectionHint;
  protected readonly open = signal<ReadonlySet<Section>>(new Set());
  protected readonly agreements = signal<AgreementRef[]>([]);
  protected readonly mayProjects = this.may('finance.projects.view');

  protected readonly start = signal(today());
  protected readonly branch = signal<number | null>(null);
  protected description = '';

  protected boxes: BoxRow[] = [];
  protected projects: ProjectRow[] = [];
  protected costs: CostRow[] = [];
  protected agreementRows: AgreementRow[] = [];
  protected parties: OwedRow[] = [];
  protected advances: AdvanceRow[] = [];
  protected custodies: CustodyRow[] = [];
  protected partners: OwedRow[] = [];

  /** The branch the sheet is for: the chosen one, else the head office. */
  protected readonly branchId = computed(() => this.branch() ?? this.finance.branches().find((item) => item.is_head_office)?.id ?? null);
  protected readonly branchBoxes = computed(() => this.finance.activeBoxes().filter((box) => box.branch_id === this.branchId()));
  protected readonly branchProjects = computed(() =>
    // The server takes only projects of the sheet's branch.
    this.finance.openProjects().filter((project) => project.branch_id === this.branchId()),
  );
  protected readonly branchAgreements = computed(() => {
    const projects = new Set(this.branchProjects().map((project) => project.project_id));
    return this.agreements().filter((item) => projects.has(item.project_id));
  });
  /** Every active HR employee (plus linked parties): the opening advance takes the employee id; the party is optional. */
  protected readonly employees = computed(() => this.financeEmployees());
  protected readonly partnerParties = computed(() => this.finance.partiesWith('partner'));
  protected readonly allParties = computed(() => this.finance.partiesWith());

  async ngOnInit(): Promise<void> {
    await this.quiet(async () => {
      await Promise.all([
        this.finance.loadCurrencies(),
        this.finance.loadBranches(),
        this.finance.loadBoxes(),
        this.finance.loadParties(),
        this.finance.loadCostCategories(),
        this.mayProjects ? this.finance.loadProjects() : undefined,
      ]);
      if (this.mayProjects) this.agreements.set(await this.settings.agreements());
    });
  }

  protected toggle(section: Section): void {
    const next = new Set(this.open());
    if (next.has(section)) next.delete(section);
    else next.add(section);
    this.open.set(next);
  }

  protected isOpen(section: Section): boolean {
    return this.open().has(section);
  }

  /** How many lines a section holds, shown on its collapsed header. */
  protected count(section: Section): number {
    switch (section) {
      case 'boxes': return this.boxes.length;
      case 'projects': return this.projects.length + this.costs.length;
      case 'agreements': return this.agreementRows.length;
      case 'parties': return this.parties.length;
      case 'employee_advances': return this.advances.length;
      case 'custodies': return this.custodies.length;
      case 'partners': return this.partners.length;
    }
  }

  protected available(section: Section): boolean {
    return !['projects', 'agreements'].includes(section) || this.mayProjects;
  }

  private base(): string {
    return this.finance.activeCurrencies().find((item) => item.is_base)?.code ?? this.finance.activeCurrencies()[0]?.code ?? '';
  }

  protected add(section: Section): void {
    const currency = this.base();
    switch (section) {
      case 'boxes': this.boxes.push({ box: null, currency: '', amount: null }); break;
      case 'projects': this.projects.push({ project: null, currency: '', receipts: null, billed: null }); break;
      case 'agreements': this.agreementRows.push({ agreement: null, paid: null }); break;
      case 'parties': this.parties.push({ party: null, currency, direction: 'owed_to_us', amount: null }); break;
      case 'employee_advances': this.advances.push({ employee: null, currency, remaining: null, count: 1, start: this.nextMonth() }); break;
      case 'custodies': this.custodies.push({ holder: null, project: null, currency, balance: null, opened: '' }); break;
      case 'partners': this.partners.push({ party: null, currency, direction: 'we_owe', amount: null }); break;
    }
  }

  protected addCost(): void {
    this.costs.push({ project: this.projects[0]?.project ?? null, category: null, amount: null });
  }

  protected remove<T>(rows: T[], index: number): void {
    rows.splice(index, 1);
  }

  /** The month after the start date: the first instalment of an opening advance. */
  private nextMonth(): string {
    const [year, month] = this.start().split('-').map(Number);
    const next = new Date(Date.UTC(year, month, 1));
    return next.toISOString().slice(0, 7);
  }

  protected box(id: number | null): FinBox | undefined {
    return this.finance.box(id);
  }

  /** A box line takes the box's first currency when it is picked. */
  protected pickBox(row: BoxRow, id: number | null): void {
    row.box = id;
    const codes = this.box(id)?.currencies.map((item) => item.code) ?? [];
    if (!codes.includes(row.currency)) row.currency = codes[0] ?? '';
  }

  /** A project line takes the contract currency when it is picked. */
  protected pickProject(row: ProjectRow, id: number | null): void {
    row.project = id;
    row.currency = this.finance.project(id)?.contract_currency ?? row.currency;
  }

  protected agreementLabel(item: AgreementRef): string {
    const who = item.contractor?.name ?? this.finance.partyName(item.contractor_party_id);
    return `${who} · ${item.scope} · ${this.finance.projectName(item.project_id)}`;
  }

  protected agreementCurrency(id: number | null): string {
    const found = this.agreements().find((item) => item.id === id);
    return found ? this.finance.code(found.currency_id) : '';
  }

  private draft(): OpeningDraft {
    const filled = (value: number | null | undefined): value is number => value !== null && value !== undefined && `${value}` !== '';
    const draft: OpeningDraft = { date: this.start(), branch_id: this.branchId(), description: this.description.trim() || null };

    const boxes = this.boxes.filter((row) => row.box && row.currency && filled(row.amount));
    if (boxes.length) draft.boxes = boxes.map((row) => ({ cash_box_id: row.box!, currency: row.currency, amount: Number(row.amount) }));

    const projects = new Map<number, NonNullable<OpeningDraft['projects']>[number]>();
    for (const row of this.projects.filter((item) => item.project)) {
      projects.set(row.project!, {
        project_id: row.project!,
        currency: row.currency || null,
        receipts: filled(row.receipts) ? Number(row.receipts) : null,
        billed: filled(row.billed) ? Number(row.billed) : null,
      });
    }
    for (const row of this.costs.filter((item) => item.project && item.category && filled(item.amount))) {
      const entry = projects.get(row.project!) ?? { project_id: row.project! };
      entry.costs = [...(entry.costs ?? []), { project_cost_category_id: row.category!, amount: Number(row.amount) }];
      projects.set(row.project!, entry);
    }
    if (projects.size) draft.projects = [...projects.values()];

    const agreements = this.agreementRows.filter((row) => row.agreement && filled(row.paid));
    if (agreements.length) draft.agreements = agreements.map((row) => ({ contractor_agreement_id: row.agreement!, paid: Number(row.paid) }));

    const owed = (rows: OwedRow[]) =>
      rows.filter((row) => row.party && row.currency && filled(row.amount))
        .map((row) => ({ party_id: row.party!, currency: row.currency, direction: row.direction, amount: Number(row.amount) }));
    if (owed(this.parties).length) draft.parties = owed(this.parties);
    if (owed(this.partners).length) draft.partners = owed(this.partners);

    const advances = this.advances.filter((row) => row.employee && row.currency && filled(row.remaining));
    if (advances.length) {
      draft.employee_advances = advances.map((row) => ({
        employee_id: row.employee ?? 0,
        currency: row.currency,
        remaining: Number(row.remaining),
        installments_count: filled(row.count) ? Number(row.count) : 1,
        start_month: row.start || null,
      }));
    }

    const custodies = this.custodies.filter((row) => row.holder && row.currency && filled(row.balance));
    if (custodies.length) {
      draft.custodies = custodies.map((row) => ({
        holder_party_id: row.holder!,
        project_id: row.project,
        currency: row.currency,
        balance: Number(row.balance),
        opened_on: row.opened || null,
      }));
    }
    return draft;
  }

  protected async submit(): Promise<void> {
    const draft = this.draft();
    const sent = ['boxes', 'projects', 'agreements', 'parties', 'employee_advances', 'custodies', 'partners'].some((key) => key in draft);
    if (!sent) {
      this.warn('fstObEmpty');
      return;
    }
    const result = await this.act(() => this.settings.openingBalances(draft), 'fstObSaved');
    if (!result) return;
    await this.finance.loadBoxes(true);
    this.saved.emit(result);
  }
}
