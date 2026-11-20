import { Component, computed, inject, OnInit } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Notice } from '../../../shared/notice/notice';
import { FinanceBase } from '../finance-base';
import { FpjAllocations } from './allocations/allocations';
import { FpjProjectFile, ProjectView } from './project-file/project-file';
import { FpjProjectForm } from './project-form/project-form';
import { FpjProjectList } from './project-list/project-list';

const projectViews: readonly ProjectView[] = ['summary', 'budget', 'contractors', 'billings', 'statement'];

/**
 * Project finance: the projects with their money at a glance, one project's file (summary, budget, contractors,
 * billings, statement), and the monthly allocation of payroll and overhead to projects.
 * Query params: `id` (the general project id) and `view` (a project tab, or `allocations` at the top level); `new=1`
 * opens the new-project sheet above the list.
 */
@Component({
  selector: 'app-fin-projects',
  imports: [Notice, RouterLink, FpjProjectList, FpjProjectForm, FpjProjectFile, FpjAllocations],
  templateUrl: './projects.html',
  styleUrl: './projects.scss',
})
export class FinProjectsPage extends FinanceBase implements OnInit {
  private readonly router = inject(Router);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly mayRead = computed(() => this.may('finance.projects.view'));
  protected readonly mayWrite = computed(() => this.may('finance.projects'));
  protected readonly mayAllocate = computed(() => this.may('finance.allocations'));

  protected readonly projectId = computed(() => (this.mayRead() ? Number(this.params()?.get('id')) || 0 : 0));
  protected readonly top = computed<'projects' | 'allocations'>(() => {
    if (!this.mayAllocate()) return 'projects';
    if (!this.mayRead()) return 'allocations';
    return !this.projectId() && this.params()?.get('view') === 'allocations' ? 'allocations' : 'projects';
  });
  protected readonly creating = computed(() => this.mayWrite() && this.mayRead() && this.params()?.get('new') === '1');
  protected readonly subView = computed<ProjectView>(() => {
    const view = this.params()?.get('view') as ProjectView;
    return projectViews.includes(view) ? view : 'summary';
  });

  ngOnInit(): void {
    void this.quiet(async () => {
      await Promise.all([
        this.finance.loadCurrencies(),
        this.finance.loadBranches(),
        this.mayRead() ? this.finance.loadCostCategories() : undefined,
        this.mayRead() ? this.finance.loadProjects() : undefined,
        this.may('finance.view') ? this.finance.loadParties() : undefined,
      ]);
    });
  }

  protected opened(projectId: number): void {
    void this.finance.loadProjects(true);
    void this.router.navigate([], { queryParams: { id: projectId } });
  }

  protected closeForm(): void {
    void this.router.navigate([], { queryParams: {} });
  }
}
