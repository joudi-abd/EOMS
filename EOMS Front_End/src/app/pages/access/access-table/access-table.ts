import { Component, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Account, companyRole, Role } from '../../../core/access/accounts';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';
import { Roles } from '../../../data/roles';

@Component({
  selector: 'app-access-table',
  imports: [RouterLink],
  templateUrl: './access-table.html',
  styleUrl: './access-table.scss',
})
export class AccessTable extends Ui {
  readonly rows = input.required<Account[]>();
  protected readonly session = inject(Session);
  private readonly office = inject(Office);

  private readonly catalog = inject(Roles);
  protected readonly companyRole = companyRole;

  protected isTop(role: Role): boolean {
    return this.catalog.role(role)?.kind === 'admin' || role === companyRole;
  }

  protected roleName(role: Role): string {
    return this.text(this.catalog.label(role));
  }

  /** Nobody disables themselves, nobody below a director touches a director, and one director always stays enabled. */
  protected locked(account: Account): boolean {
    return !this.office.mayToggle(this.session.account().id, account.id);
  }

  protected editable(account: Account): boolean {
    return this.office.mayEditAccount(this.session.account().id, account.id);
  }

  protected readonly busy = signal('');

  protected async toggle(account: Account): Promise<void> {
    if (this.locked(account) || this.busy()) return;
    this.busy.set(account.id);
    await this.office.setEnabled(account.id, !account.enabled, this.session.account().id);
    this.busy.set('');
  }
}
