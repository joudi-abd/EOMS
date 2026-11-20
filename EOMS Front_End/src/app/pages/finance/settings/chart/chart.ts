import { Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AccountType, accountTypes, FinAccount } from '../../../../data/finance';
import { FinSettingsApi, SystemRole } from '../../../../data/finance-settings';
import { FinanceBase } from '../../finance-base';

interface AccountForm {
  id: number | null;
  parent_id: number | null;
  code: string;
  name: string;
  type: AccountType;
  is_postable: boolean;
  requires_party: boolean;
  requires_project: boolean;
}

interface TreeRow {
  account: FinAccount;
  depth: number;
}

/**
 * The chart of accounts as an indented tree (code, name, type, postable or group, the system roles it plays, active),
 * with add child, edit and deactivate; and the required accounts (`/finance/system-accounts`): each role with the
 * account it posts to, the unmapped ones first. Reading needs `finance.view`, changing `finance.settings`.
 */
@Component({
  selector: 'app-fst-chart',
  imports: [FormsModule],
  templateUrl: './chart.html',
  styleUrl: './chart.scss',
  host: { class: 'ledger' },
})
export class FstChart extends FinanceBase implements OnInit {
  private readonly settings = inject(FinSettingsApi);

  readonly part = input('');

  protected readonly view = signal<'tree' | 'roles'>('tree');
  protected readonly mayEdit = this.may('finance.settings');
  protected readonly types = accountTypes;

  protected readonly search = signal('');
  protected readonly roles = signal<SystemRole[]>([]);
  protected readonly mapping = signal<Record<string, number | null>>({});
  protected form: AccountForm | null = null;
  protected readonly editing = signal(false);
  protected details = false;

  protected readonly roleLabels = computed(() => new Map(this.roles().map((item) => [item.role, item.label])));

  /** The tree flattened with each row's depth; a search shows the matching rows alone. */
  protected readonly rows = computed<TreeRow[]>(() => {
    const out: TreeRow[] = [];
    const walk = (nodes: FinAccount[], depth: number): void => {
      for (const node of nodes) {
        out.push({ account: node, depth });
        walk(node.children ?? [], depth + 1);
      }
    };
    walk(this.finance.accountTree(), 0);
    const query = this.search().trim().toLowerCase();
    return query ? out.filter((row) => `${row.account.code} ${row.account.name}`.toLowerCase().includes(query)).map((row) => ({ ...row, depth: 0 })) : out;
  });

  /** Unmapped roles first, then the rest in the server's order. */
  protected readonly roleRows = computed(() => [...this.roles()].sort((a, b) => Number(b.unmapped) - Number(a.unmapped)));
  protected readonly unmapped = computed(() => this.roles().filter((item) => item.unmapped).length);

  ngOnInit(): void {
    if (this.part() === 'roles') this.view.set('roles');
    void this.load();
  }

  private async load(force = false): Promise<void> {
    await this.quiet(async () => {
      await this.finance.loadAccounts(force);
      const roles = await this.settings.systemAccounts();
      this.roles.set(roles.roles);
      this.mapping.set(Object.fromEntries(roles.roles.map((item) => [item.role, item.account_id])));
    });
  }

  protected systemRoleLabel(role: string): string {
    return this.roleLabels().get(role) ?? role;
  }

  protected canDeactivate(account: FinAccount): boolean {
    return account.is_active && !account.is_system && !account.roles?.length;
  }

  // ----- Accounts -----

  protected startNew(parent: FinAccount | null): void {
    this.form = {
      id: null,
      parent_id: parent?.id ?? null,
      code: parent ? `${parent.code}` : '',
      name: '',
      type: parent?.type ?? 'asset',
      is_postable: true,
      requires_party: false,
      requires_project: false,
    };
    this.details = false;
    this.editing.set(true);
  }

  protected edit(account: FinAccount): void {
    this.form = {
      id: account.id,
      parent_id: account.parent_id,
      code: account.code,
      name: account.name,
      type: account.type,
      is_postable: account.is_postable,
      requires_party: account.requires_party,
      requires_project: account.requires_project,
    };
    this.details = account.requires_party || account.requires_project;
    this.editing.set(true);
  }

  protected close(): void {
    this.form = null;
    this.editing.set(false);
  }

  protected parentLabel(id: number | null): string {
    return id ? this.finance.accountLabel(id) : this.t('fstRootAccount');
  }

  protected async submit(): Promise<void> {
    const form = this.form;
    if (!form) return;
    const body = {
      code: form.code.trim(),
      name: form.name.trim(),
      is_postable: form.is_postable,
      requires_party: form.requires_party,
      requires_project: form.requires_project,
    };
    const done = await this.act(
      () =>
        form.id
          ? this.settings.updateAccount(form.id, body)
          : this.settings.createAccount({ ...body, type: form.type, parent_id: form.parent_id }),
      'finSaved',
    );
    if (!done) return;
    this.close();
    await this.load(true);
  }

  protected async deactivate(account: FinAccount): Promise<void> {
    if (!(await this.askConfirm({ message: 'confirmSensitiveBody', tone: 'danger', confirm: 'confirmProceed' }))) return;
    const done = await this.act(() => this.settings.deactivateAccount(account.id), 'fstAccountDeactivated');
    if (done) await this.load(true);
  }

  protected async activate(account: FinAccount): Promise<void> {
    const done = await this.act(() => this.settings.updateAccount(account.id, { is_active: true }), 'finSaved');
    if (done) await this.load(true);
  }

  // ----- Required accounts -----

  /** The boxes' parent is an asset group; every other role posts to an active postable account that is not a box. */
  protected choices(role: string): FinAccount[] {
    const active = this.finance.accounts().filter((item) => item.is_active && !item.is_system && !item.cash_box_id);
    return role === 'cash_boxes_parent' ? active.filter((item) => item.type === 'asset') : active.filter((item) => item.is_postable);
  }

  protected setMapping(role: string, accountId: number | null): void {
    this.mapping.set({ ...this.mapping(), [role]: accountId });
  }

  protected async saveRoles(): Promise<void> {
    const before = new Map(this.roles().map((item) => [item.role, item.account_id]));
    const mappings: Record<string, number> = {};
    for (const [role, accountId] of Object.entries(this.mapping())) {
      if (accountId && accountId !== before.get(role)) mappings[role] = accountId;
    }
    if (!Object.keys(mappings).length) {
      this.warn('fstRolesUnchanged');
      return;
    }
    const saved = await this.act(() => this.settings.setSystemAccounts(mappings), 'fstRolesSaved');
    if (saved) await this.load(true);
  }
}
