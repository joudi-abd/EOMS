import { Component, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Account, isTop, Role, roleLabel } from '../../../core/access/accounts';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Office } from '../../../data/office';

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

  protected readonly roleLabel = roleLabel;

  protected isTop(role: Role): boolean {
    return isTop([role]);
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
