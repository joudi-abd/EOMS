import { Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { FinBranch } from '../../../../data/finance';
import { FinSettingsApi } from '../../../../data/finance-settings';
import { Tasks } from '../../../../data/tasks';
import { FinanceBase } from '../../finance-base';

interface BranchForm {
  id: number | null;
  name: string;
  code: string;
  is_head_office: boolean;
  is_active: boolean;
}

interface Person {
  userId: number;
  name: string;
}

/**
 * Branches (`/settings/branches`): the list with add, edit and the head-office mark; and the accountants' branches
 * (`/finance/users/{id}/branches`): pick an account and tick the branches it works in — its reach for every finance
 * permission it holds at "own" scope.
 */
@Component({
  selector: 'app-fst-branches',
  imports: [FormsModule],
  templateUrl: './branches.html',
  styleUrl: './branches.scss',
  host: { class: 'ledger' },
})
export class FstBranches extends FinanceBase implements OnInit {
  private readonly settings = inject(FinSettingsApi);
  private readonly tasks = inject(Tasks);

  readonly part = input('');

  protected readonly view = signal<'list' | 'people'>('list');
  protected form: BranchForm | null = null;
  protected readonly editing = signal(false);

  // Accountants' branches
  private readonly extraPeople = signal<Person[]>([]);
  protected readonly person = signal<number | null>(null);
  protected readonly picked = signal<ReadonlySet<number>>(new Set());
  protected readonly loadingPerson = signal(false);

  /**
   * Accounts to choose from: the access list (with `users.manage`), else the task people list (with `tasks.manage`),
   * which is all a finance manager may read today.
   */
  protected readonly people = computed<Person[]>(() => {
    const seen = new Map<number, Person>();
    for (const account of this.office.accounts()) seen.set(account.userId, { userId: account.userId, name: this.text(account.name) });
    for (const person of this.extraPeople()) if (!seen.has(person.userId)) seen.set(person.userId, person);
    return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));
  });

  protected readonly activeBranches = computed(() => this.finance.branches().filter((item) => item.is_active));

  ngOnInit(): void {
    if (this.part() === 'people') this.view.set('people');
    void this.quiet(() => this.finance.loadBranches());
    if (!this.session.reach('users.manage') && this.may('tasks.manage')) {
      void this.quiet(async () => this.extraPeople.set((await this.tasks.people()).map((item) => ({ userId: item.user_id, name: item.name }))));
    }
  }

  // ----- Branches -----

  protected startNew(): void {
    this.form = { id: null, name: '', code: '', is_head_office: !this.finance.branches().some((item) => item.is_head_office), is_active: true };
    this.editing.set(true);
  }

  protected edit(item: FinBranch): void {
    this.form = { id: item.id, name: item.name, code: item.code ?? '', is_head_office: item.is_head_office, is_active: item.is_active };
    this.editing.set(true);
  }

  /**
   * The current head office keeps its mark until another branch takes it: unticking it would leave the company with
   * no head office (the server accepts that and later falls back on it for defaults).
   */
  protected isHeadOffice(id: number | null): boolean {
    return !!id && !!this.finance.branches().find((item) => item.id === id)?.is_head_office;
  }

  protected close(): void {
    this.form = null;
    this.editing.set(false);
  }

  protected async submit(): Promise<void> {
    const form = this.form;
    if (!form) return;
    const body = { name: form.name.trim(), code: form.code.trim(), is_head_office: form.is_head_office, is_active: form.is_active };
    const done = await this.act(() => (form.id ? this.settings.updateBranch(form.id, body) : this.settings.createBranch(body)), 'finSaved');
    if (!done) return;
    this.close();
    await this.finance.loadBranches(true);
  }

  protected async toggleActive(item: FinBranch): Promise<void> {
    if (item.is_active && !(await this.askConfirm({ message: 'confirmSensitiveBody', tone: 'danger', confirm: 'confirmProceed' }))) return;
    const done = await this.act(() => this.settings.updateBranch(item.id, { is_active: !item.is_active }), 'finSaved');
    if (done) await this.finance.loadBranches(true);
  }

  // ----- Accountants' branches -----

  protected async pickPerson(userId: number | null): Promise<void> {
    this.person.set(userId);
    this.picked.set(new Set());
    if (!userId) return;
    this.loadingPerson.set(true);
    await this.quiet(async () => this.picked.set(new Set(await this.settings.userBranches(userId))));
    this.loadingPerson.set(false);
  }

  protected tick(branchId: number, on: boolean): void {
    const next = new Set(this.picked());
    if (on) next.add(branchId);
    else next.delete(branchId);
    this.picked.set(next);
  }

  protected async saveReach(): Promise<void> {
    const userId = this.person();
    if (!userId) return;
    const saved = await this.act(() => this.settings.setUserBranches(userId, [...this.picked()]), 'fstReachSaved');
    if (saved) this.picked.set(new Set(saved));
  }
}
