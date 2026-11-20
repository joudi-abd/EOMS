import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ContractType, ProjectStatus } from '../../../../data/finance';
import { contractTypes, FinProjects, projectStatuses, ProjectSummaryRow } from '../../../../data/finance-projects';
import { FinanceBase } from '../../finance-base';

const pageSize = 25;

/** Every reachable project with its money in its contract currency (the projects summary, T8). */
@Component({
  selector: 'app-fpj-project-list',
  imports: [FormsModule, RouterLink],
  templateUrl: './project-list.html',
  styleUrl: './project-list.scss',
  host: { class: 'ledger' },
})
export class FpjProjectList extends FinanceBase implements OnInit {
  private readonly api = inject(FinProjects);
  protected readonly statuses = projectStatuses;
  protected readonly types = contractTypes;

  protected readonly rows = signal<ProjectSummaryRow[]>([]);
  protected readonly loading = signal(true);
  protected readonly page = signal(1);
  protected readonly pages = computed(() => Math.max(1, Math.ceil(this.rows().length / pageSize)));
  protected readonly shown = computed(() => this.rows().slice((this.page() - 1) * pageSize, this.page() * pageSize));

  protected status: ProjectStatus | '' = '';
  protected type: ContractType | '' = '';
  protected search = '';
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  ngOnInit(): void {
    void this.load();
  }

  protected searchChanged(): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => void this.load(), 350);
  }

  protected async load(): Promise<void> {
    this.loading.set(true);
    await this.quiet(async () => {
      const rows = await this.api.summary({ status: this.status, contract_type: this.type, search: this.search.trim() });
      // The server orders by code; the newest project has the highest code.
      this.rows.set([...rows].reverse());
      this.page.set(1);
    });
    this.loading.set(false);
  }

  protected reset(): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.status = '';
    this.type = '';
    this.search = '';
    void this.load();
  }

  protected filtered(): boolean {
    return !!(this.status || this.type || this.search.trim());
  }
}
