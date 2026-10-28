import { Component, computed, inject, input, signal } from '@angular/core';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { Department } from '../../../data/employees';
import { ContractTypeDef, LeaveTypeDef, NamedItem } from '../../../data/hr';
import { NamedList, Office } from '../../../data/office';

const titleKey: Record<NamedList, CopyKey> = {
  departments: 'listDepartments',
  titles: 'listTitles',
  leaveTypes: 'listLeaveTypes',
  contractTypes: 'listContractTypes',
};

@Component({
  selector: 'app-settings-list',
  templateUrl: './settings-list.html',
  styleUrl: './settings-list.scss',
})
export class SettingsList extends Ui {
  readonly list = input.required<NamedList>();
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly editing = signal('');
  protected readonly problem = signal('');
  protected readonly busy = signal(false);

  protected readonly heading = computed(() => this.t(titleKey[this.list()]));
  protected readonly items = computed(() => this.office.namedList(this.list()));
  /** Departments and titles live on the server with one name each; the local lists keep Arabic and English. */
  protected readonly remote = computed(() => this.list() === 'departments' || this.list() === 'titles');

  protected code(item: NamedItem): string {
    return (item as Department).code ?? '';
  }

  protected extra(item: NamedItem): string {
    if (this.list() === 'leaveTypes') return (item as LeaveTypeDef).unit === 'hour' ? this.t('unitHour') : this.t('unitDay');
    if (this.list() === 'contractTypes') return (item as ContractTypeDef).fixed ? this.t('hasEnd') : this.t('noEndType');
    if (this.list() === 'departments') {
      const count = this.office.departments().find((row) => row.id === item.id)?.count ?? 0;
      return `${this.code(item)} · ${this.num(count)} ${this.t('employeesLabel')}`;
    }
    const count = this.office.employees().filter((row) => row.titleId === item.id && row.status !== 'ended').length;
    return `${this.num(count)} ${this.t('employeesLabel')}`;
  }

  protected edit(id: string): void {
    this.problem.set('');
    this.editing.set(this.editing() === id ? '' : id);
  }

  protected async rename(event: Event, id: string): Promise<void> {
    event.preventDefault();
    const data = new FormData(event.target as HTMLFormElement);
    const name = { ar: String(data.get('ar') ?? ''), en: String(data.get('en') ?? '') };
    await this.run(() => this.office.renameNamed(this.list(), id, name, this.session.account().id, String(data.get('code') ?? '')), () => this.editing.set(''));
  }

  protected async add(event: Event): Promise<void> {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const name = { ar: String(data.get('ar') ?? ''), en: String(data.get('en') ?? '') };
    const extra = { unit: data.get('unit') === 'hour' ? ('hour' as const) : ('day' as const), fixed: data.get('fixed') !== 'open', code: String(data.get('code') ?? '') };
    await this.run(() => this.office.addNamed(this.list(), name, this.session.account().id, extra), () => form.reset());
  }

  protected async remove(id: string): Promise<void> {
    const list = this.list();
    if (list !== 'departments' && list !== 'titles') return;
    await this.run(() => this.office.removeNamed(list, id, this.session.account().id));
  }

  private async run(action: () => Promise<{ ok: boolean; error?: string }>, onDone?: () => void): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.problem.set('');
    const outcome = await action();
    this.busy.set(false);
    if (outcome.ok) onDone?.();
    else this.problem.set(outcome.error ?? '');
  }
}
